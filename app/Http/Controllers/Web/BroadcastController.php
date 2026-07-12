<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Http\Controllers\Concerns\EditsMessageContent;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Broadcast;
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

        return Inertia::render('Broadcasts/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'segments' => $workspace->segments()->withCount('people')->orderBy('name')->get(['id', 'public_id', 'name', 'type']),
            'templates' => $workspace->templates()->orderBy('name')->get(['id', 'name', 'channel', 'subject']),
            'channels' => $workspace->channels()->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'type', 'driver']),
            'broadcasts' => $workspace->broadcasts()->with('segment:id,name', 'template:id,name', 'channelConfiguration:id,name')->withCount([
                'recipients', 'recipients as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'recipients as failed_count' => fn ($query) => $query->whereIn('status', ['failed', 'skipped']),
            ])->latest()->get(),
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

        return back()->with('success', "Broadcast queued for {$count} recipients.");
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
