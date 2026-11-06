<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;

/**
 * The sent-messages log: the one place that answers "did user X get the
 * email, and what did it say?" Before it, a message was only reachable from
 * inside a run timeline, and broadcast or person pages showed bare counts.
 */
class MessageController extends Controller
{
    /** Status chips. "problem" matches the Overview's "Failed or bounced" tile. */
    public const STATUS_FILTERS = [
        'sent' => ['sent'],
        'delivered' => ['delivered'],
        'problem' => ['failed', 'bounced'],
        'pending' => ['queued', 'sending'],
    ];

    public const DAY_FILTERS = [1, 7, 30, 90];

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        $status = $request->string('status')->toString();
        $channel = $request->string('channel')->toString();
        $days = $request->integer('days');
        $filters = [
            'status' => array_key_exists($status, self::STATUS_FILTERS) ? $status : '',
            'channel' => in_array($channel, ['email', 'sms', 'push'], true) ? $channel : '',
            'search' => trim($request->string('search')->toString()),
            'person' => $request->integer('person') ?: null,
            'automation' => $request->integer('automation') ?: null,
            'broadcast' => $request->integer('broadcast') ?: null,
            'days' => in_array($days, self::DAY_FILTERS, true) ? $days : null,
        ];

        // Everything but the status filter, so each chip can show how many
        // messages it would leave on screen.
        $base = fn (): Builder => $this->filtered($workspace->id, $filters);

        $counts = $base()->toBase()->reorder()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')->pluck('total', 'status');

        $messages = $base()
            ->when($filters['status'], fn ($query, $status) => $query->whereIn('status', self::STATUS_FILTERS[$status]))
            // Rendered email bodies are whole HTML documents; a page of 25 of
            // them is megabytes the list never shows. Email rows show the
            // subject, SMS and push rows show the start of their text.
            ->select([
                'id', 'person_id', 'run_step_id', 'template_id', 'channel', 'to_address', 'subject',
                'status', 'error', 'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'created_at',
            ])
            ->selectRaw("case when channel = 'email' then null else substr(body, 1, 160) end as excerpt")
            ->with($this->sourceRelations())
            ->orderByDesc('id')
            ->paginate(25)->withQueryString()
            ->through(fn (Message $message) => $this->summary($message));

        return Inertia::render('Messages/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'messages' => $messages,
            'counts' => [
                'all' => (int) $counts->sum(),
                ...collect(self::STATUS_FILTERS)->map(fn (array $statuses) => (int) $counts->only($statuses)->sum())->all(),
            ],
            'filters' => $filters,
            // Names for the active scoping filters, so the page can say whose
            // messages these are instead of showing a bare id.
            'scope' => [
                'person' => $filters['person'] ? Person::query()->where('workspace_id', $workspace->id)->find($filters['person'], ['id', 'external_id', 'email'])?->only('id', 'external_id', 'email') : null,
                'automation' => $filters['automation'] ? $workspace->automations()->find($filters['automation'], ['id', 'name'])?->only('id', 'name') : null,
                'broadcast' => $filters['broadcast'] ? $workspace->broadcasts()->find($filters['broadcast'], ['id', 'name'])?->only('id', 'name') : null,
            ],
            'automations' => $workspace->automations()->orderBy('name')->get(['id', 'name']),
            'broadcasts' => $workspace->broadcasts()->where('status', '!=', 'draft')->latest()->limit(100)->get(['id', 'name']),
        ]);
    }

    public function show(Request $request, Message $message): Response
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($message->workspace_id === $workspace->id, 404);
        $message->load($this->sourceRelations());

        return Inertia::render('Messages/Show', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'message' => [
                ...$this->summary($message),
                'body' => $message->body,
                'provider_message_id' => $message->provider_message_id,
                'awaiting_receipt' => ! empty($message->pending_receipts),
                'updated_at' => $message->updated_at,
            ],
        ]);
    }

    protected function filtered(int $workspaceId, array $filters): Builder
    {
        return Message::query()
            ->where('workspace_id', $workspaceId)
            ->when($filters['channel'], fn ($query, $channel) => $query->where('channel', $channel))
            ->when($filters['person'], fn ($query, $person) => $query->where('person_id', $person))
            ->when($filters['automation'], fn ($query, $automation) => $query->whereHas(
                'runStep.run', fn ($run) => $run->where('automation_id', $automation)
            ))
            ->when($filters['broadcast'], fn ($query, $broadcast) => $query->whereHas(
                'broadcastRecipient', fn ($recipient) => $recipient->where('broadcast_id', $broadcast)
            ))
            ->when($filters['days'], fn ($query, $days) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when($filters['search'] !== '', function ($query) use ($filters) {
                $term = '%'.addcslashes($filters['search'], '%_\\').'%';
                $query->where(fn ($nested) => $nested
                    ->where('to_address', 'like', $term)
                    ->orWhere('subject', 'like', $term)
                    ->orWhereHas('person', fn ($person) => $person->where('external_id', 'like', $term)));
            });
    }

    /** @return array<int|string, mixed> */
    protected function sourceRelations(): array
    {
        return [
            'person:id,external_id,email,phone',
            'template:id,name',
            'runStep:id,automation_run_id,node_id,attempts',
            'runStep.run:id,automation_id',
            'runStep.run.automation:id,name',
            'broadcastRecipient:id,broadcast_id,message_id',
            'broadcastRecipient.broadcast:id,name',
        ];
    }

    /** @return array<string, mixed> */
    protected function summary(Message $message): array
    {
        $run = $message->runStep?->run;
        $broadcast = $message->broadcastRecipient?->broadcast;
        // The index selects a short excerpt instead of the body. Read it from
        // the raw attributes: hosts running Eloquent in strict mode throw on
        // a missing attribute, and the detail page loads the full row.
        $attributes = $message->getAttributes();
        $excerpt = array_key_exists('excerpt', $attributes)
            ? $attributes['excerpt']
            : ($message->channel === 'email' ? null : mb_substr((string) $message->body, 0, 160));

        return [
            ...$message->only([
                'id', 'channel', 'to_address', 'subject', 'status', 'error',
                'sent_at', 'delivered_at', 'opened_at', 'clicked_at', 'bounced_at', 'created_at',
            ]),
            'excerpt' => $excerpt,
            'person' => $message->person?->only('id', 'external_id', 'email', 'phone'),
            'template' => $message->template?->only('id', 'name'),
            'source' => match (true) {
                $run !== null => [
                    'type' => 'automation',
                    'run_id' => $run->id,
                    'automation' => $run->automation?->only('id', 'name'),
                    'attempts' => $message->runStep->attempts,
                ],
                $broadcast !== null => ['type' => 'broadcast', 'broadcast' => $broadcast->only('id', 'name')],
                default => null,
            },
        ];
    }
}
