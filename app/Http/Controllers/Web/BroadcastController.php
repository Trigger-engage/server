<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Http\Controllers\Concerns\EditsMessageContent;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Jobs\SendBroadcastRecipient;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\BroadcastRecipient;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Services\BroadcastSender;

class BroadcastController extends Controller
{
    use EditsMessageContent;

    public function __construct(
        protected EmailLayoutRenderer $layouts,
        protected TemplateRenderer $renderer,
    ) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        // "Create broadcast" links on segment pages land here with ?segment=<id>
        // so the form opens with that audience already selected.
        $preselected = $workspace->segments()->whereKey($request->integer('segment'))->value('id');

        return Inertia::render('Broadcasts/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'preselectedSegmentId' => $preselected,
            'segments' => $workspace->segments()->withCount('people')->orderBy('name')->get(['id', 'public_id', 'name', 'type']),
            'templates' => $workspace->templates()->orderBy('name')->get(['id', 'name', 'channel', 'subject']),
            'channels' => $workspace->channels()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type', 'driver']),
            'broadcasts' => $workspace->broadcasts()->with('segment:id,name', 'template:id,name', 'channelConfiguration:id,name')->withCount([
                'recipients', 'recipients as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'recipients as skipped_count' => fn ($query) => $query->where('status', 'skipped'),
                'recipients as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])->latest()->get()->map(fn (Broadcast $broadcast) => [
                ...$broadcast->toArray(),
                // Drafts get a pre-send audience estimate so the send confirmation
                // can say "sends to 3 of 8" BEFORE the surprise, not after it.
                'audience' => $broadcast->status === 'draft' ? $this->audiencePreview($broadcast) : null,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'channel' => ['required', Rule::in(['email', 'sms', 'push'])],
            'segment_id' => ['required', Rule::exists('segments', 'id')->where('workspace_id', $workspace->id)],
            'template_id' => ['required', 'integer'],
            'channel_id' => ['required', 'integer'],
        ]);
        $template = Template::query()->where('workspace_id', $workspace->id)->where('channel', $data['channel'])->find($data['template_id']);
        $channel = Channel::query()->where('workspace_id', $workspace->id)->where('type', $data['channel'])->find($data['channel_id']);
        if (! $template || ! $channel) {
            throw ValidationException::withMessages(['channel' => 'Template and delivery channel must match the broadcast channel.']);
        }
        $broadcast = $workspace->broadcasts()->create([...$data, ...$this->snapshotFrom($template)]);

        return redirect()->route('engage.broadcasts.edit', $broadcast)
            ->with('success', 'Broadcast draft created. Edit the message, preview it, then send.');
    }

    public function edit(Request $request, Broadcast $broadcast): Response
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($broadcast->workspace_id === $workspace->id, 404);
        $broadcast->load('segment:id,name', 'template:id,name', 'channelConfiguration:id,name,driver');
        $broadcast->settings = $broadcast->channel === 'email' ? $this->layouts->normalizeSettings($broadcast->settings) : $broadcast->settings;

        return Inertia::render('Broadcasts/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'broadcast' => [
                ...$broadcast->only([
                    'id', 'name', 'channel', 'status', 'subject', 'body', 'layout',
                    'preheader', 'settings', 'from_name', 'from_address',
                ]),
                'editable' => $broadcast->isEditable(),
                'segment' => $broadcast->segment?->only('id', 'name'),
                'template' => $broadcast->template?->only('id', 'name'),
                'delivery_channel' => $broadcast->channelConfiguration?->only('id', 'name', 'driver'),
                'audience' => $broadcast->isEditable() ? $this->audiencePreview($broadcast) : null,
            ],
            'preview' => $this->renderPreview($broadcast->messageTemplate()),
            'defaultSettings' => $this->layouts->defaultSettings(),
        ]);
    }

    public function update(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($broadcast->workspace_id === $workspace->id, 404);
        if (! $broadcast->isEditable()) {
            throw ValidationException::withMessages(['broadcast' => 'This broadcast has already been sent and can no longer be edited.']);
        }
        $data = $request->validate($this->contentRules());
        $data['channel'] = $broadcast->channel;
        $this->validateLiquid($data);
        $broadcast->update($this->normalizedContent($data));

        return back()->with('success', 'Broadcast saved. It will send with this content.');
    }

    public function send(Request $request, Broadcast $broadcast, BroadcastSender $sender): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($broadcast->workspace_id === $workspace->id, 404);
        $count = $sender->send($broadcast);

        return redirect()->route('engage.broadcasts.show', $broadcast)
            ->with('success', "Broadcast queued for {$count} recipients.");
    }

    /**
     * The delivery report: every recipient outcome, with skipped (expected — no
     * address, suppressed) separated from failed (something actually broke), and
     * the stored per-recipient error strings finally on a screen. This page
     * exists because of a real incident where "8 skipped/failed" had to be
     * diagnosed by reading broadcast_recipients in SQL.
     */
    public function show(Request $request, Broadcast $broadcast): Response|RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($broadcast->workspace_id === $workspace->id, 404);

        // A draft has no outcomes yet — its page is the composer.
        if ($broadcast->status === 'draft') {
            return redirect()->route('engage.broadcasts.edit', $broadcast);
        }

        $broadcast->load('segment:id,name', 'template:id,name', 'channelConfiguration:id,name,driver');

        $counts = $broadcast->recipients()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        $status = $request->string('status')->toString();

        return Inertia::render('Broadcasts/Show', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'broadcast' => [
                ...$broadcast->only(['id', 'name', 'channel', 'status', 'started_at', 'completed_at']),
                'segment' => $broadcast->segment?->only('id', 'name'),
                'template' => $broadcast->template?->only('id', 'name'),
                'delivery_channel' => $broadcast->channelConfiguration?->only('id', 'name', 'driver'),
            ],
            'counts' => [
                'sent' => (int) ($counts['sent'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'pending' => (int) (($counts['queued'] ?? 0) + ($counts['sending'] ?? 0)),
                'total' => (int) $counts->sum(),
            ],
            // One row per distinct outcome, so a crash that hit 200 people reads
            // as one line, not 200. Totals int-cast (MySQL can stringify raw
            // aggregates) and secondary-ordered so ties don't shuffle on reload.
            'reasons' => $broadcast->recipients()
                ->whereIn('status', ['skipped', 'failed'])
                ->selectRaw('status, error, count(*) as total')
                ->groupBy('status', 'error')
                ->orderByDesc('total')->orderBy('status')->orderBy('error')
                ->get()
                ->map(fn ($reason) => [
                    'status' => $reason->status,
                    'error' => $reason->error,
                    'total' => (int) $reason->total,
                ]),
            'recipients' => $broadcast->recipients()
                ->with('person:id,external_id,email,phone')
                ->when(in_array($status, ['sent', 'skipped', 'failed'], true), fn ($query) => $query->where('status', $status))
                ->orderByRaw("case status when 'failed' then 0 when 'skipped' then 1 else 2 end")
                ->orderBy('id')
                ->paginate(50)->withQueryString()
                ->through(fn (BroadcastRecipient $recipient) => [
                    ...$recipient->only(['id', 'status', 'error', 'sent_at']),
                    'person' => $recipient->person?->only('id', 'external_id', 'email', 'phone'),
                ]),
            'filters' => ['status' => $status],
        ]);
    }

    /**
     * Re-queue only the FAILED recipients — skipped ones are expected outcomes
     * (no address, unsubscribed), not errors, and retrying them would just skip
     * again. The send job is already idempotent for re-dispatch: it reuses the
     * recipient's existing message row and ignores non-queued statuses.
     */
    public function retryFailed(Request $request, Broadcast $broadcast): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($broadcast->workspace_id === $workspace->id, 404);

        $recipientIds = DB::transaction(function () use ($broadcast): array {
            $locked = Broadcast::query()->lockForUpdate()->findOrFail($broadcast->id);
            if ($locked->status !== 'completed') {
                throw ValidationException::withMessages(['broadcast' => 'Wait for the current send to finish before retrying.']);
            }

            $ids = $locked->recipients()->where('status', 'failed')->pluck('id');
            if ($ids->isEmpty()) {
                throw ValidationException::withMessages(['broadcast' => 'There are no failed recipients to retry.']);
            }

            $locked->recipients()->whereKey($ids)->update(['status' => 'queued', 'error' => null]);
            $locked->update(['status' => 'sending', 'completed_at' => null]);

            return $ids->all();
        });

        // On the sync queue a job that throws rethrows into THIS loop after its
        // failed() handler has already marked the recipient failed — so swallow
        // and keep going, or one bad recipient strands every recipient after it
        // as 'queued' forever with the broadcast wedged in 'sending' (retry and
        // send both refuse non-completed/non-draft states, so there would be no
        // way out from the UI).
        foreach ($recipientIds as $id) {
            try {
                SendBroadcastRecipient::dispatch($id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $count = count($recipientIds);

        return back()->with('success', "Retrying {$count} failed ".str('recipient')->plural($count).'.');
    }

    /**
     * Pre-send audience estimate: of the segment's current members, how many
     * will actually receive this channel and how many will be skipped (missing
     * destination, unsubscribed/suppressed). Counts overlap-safe: `sendable`
     * is the distinct complement, not total minus the sum.
     *
     * @return array{total: int, sendable: int, no_destination: int, suppressed: int}
     */
    protected function audiencePreview(Broadcast $broadcast): array
    {
        // Destination predicates mirror SendBroadcastRecipient exactly — it
        // skips on blank($address), so '' must count as missing, and push
        // resolves attributes.onesignal_external_id ?? external_id (anonymous
        // profiles have a NULL external_id since the anonymous-identity
        // migration, so push CAN lack a destination).
        $hasDestination = match ($broadcast->channel) {
            'email' => "(people.email is not null and people.email <> '')",
            'sms' => "(people.phone is not null and people.phone <> '')",
            default => "((people.external_id is not null and people.external_id <> '')"
                ." or json_extract(people.attributes, '$.onesignal_external_id') is not null)",
        };

        $suppressed = '(people.unsubscribed_at is not null or exists ('
            .'select 1 from suppressions where suppressions.person_id = people.id'
            ." and suppressions.channel in (?, 'all')))";

        // One conditional-aggregation pass instead of four COUNTs — the index
        // page calls this for every draft. no_destination and suppressed may
        // overlap on the same person; only `sendable` is overlap-safe.
        $row = $broadcast->segment->people()->selectRaw(
            'count(*) as total'
            .", coalesce(sum(case when {$hasDestination} and not {$suppressed} then 1 else 0 end), 0) as sendable"
            .", coalesce(sum(case when not {$hasDestination} then 1 else 0 end), 0) as no_destination"
            .", coalesce(sum(case when {$suppressed} then 1 else 0 end), 0) as suppressed",
            [$broadcast->channel, $broadcast->channel]
        )->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'sendable' => (int) ($row->sendable ?? 0),
            'no_destination' => (int) ($row->no_destination ?? 0),
            'suppressed' => (int) ($row->suppressed ?? 0),
        ];
    }

    /**
     * Copy a template's message content into the fields the broadcast owns, so
     * the campaign starts as an editable snapshot rather than a live reference.
     *
     * @return array<string, mixed>
     */
    protected function snapshotFrom(Template $template): array
    {
        $content = $this->normalizedContent([
            'channel' => $template->channel,
            'name' => $template->name,
            'subject' => $template->subject,
            'body' => $template->body,
            'layout' => $template->layout,
            'preheader' => $template->preheader,
            'from_name' => $template->from_name,
            'from_address' => $template->from_address,
            'settings' => $template->settings,
        ]);

        return [
            'subject' => $content['subject'] ?? null,
            'body' => $content['body'] ?? '',
            'layout' => $content['layout'] ?? null,
            'preheader' => $content['preheader'] ?? null,
            'settings' => $content['settings'] ?? null,
            'from_name' => $content['from_name'] ?? null,
            'from_address' => $content['from_address'] ?? null,
        ];
    }
}
