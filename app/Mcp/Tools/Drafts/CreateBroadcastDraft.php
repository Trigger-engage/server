<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsContent;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Services\BroadcastAudience;

class CreateBroadcastDraft extends Tool
{
    use DraftsContent;

    public function __construct(EmailLayoutRenderer $layouts, TemplateRenderer $renderer, protected BroadcastAudience $audience)
    {
        $this->layouts = $layouts;
        $this->renderer = $renderer;
    }

    public function name(): string
    {
        return 'create_broadcast_draft';
    }

    public function title(): string
    {
        return 'Create a broadcast draft';
    }

    public function description(): string
    {
        return 'Start a one-off campaign to a segment as a draft. Like the dashboard\'s composer, it copies a template\'s '
            .'content into the broadcast (edit it with update_broadcast_draft; the template is left alone). Nothing is sent: '
            .'a person reviews and presses Send in the dashboard. Returns the audience estimate: how many members would '
            .'receive it and how many would be skipped for no address, suppression or a topic opt-out.';
    }

    public function properties(): array
    {
        return [
            'name' => ['type' => 'string', 'description' => 'What the team calls the campaign.'],
            'segment_id' => ['type' => ['integer', 'string'], 'description' => 'The audience: numeric id or seg_… public id.'],
            'template_id' => ['type' => 'integer', 'description' => 'Where the content starts from. Its channel (email, SMS, push) is the broadcast\'s.'],
            'channel_id' => ['type' => 'integer', 'description' => 'Which provider sends it (see list_channels). Default: the workspace default for that channel type.'],
        ];
    }

    public function required(): array
    {
        return ['name', 'segment_id', 'template_id'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'segment_id' => ['required'],
            'template_id' => ['required', 'integer'],
            'channel_id' => ['nullable', 'integer'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspace = $context->workspace;

        $segment = Segment::query()
            ->where('workspace_id', $workspace->id)
            ->where(is_numeric($arguments['segment_id']) ? 'id' : 'public_id', $arguments['segment_id'])
            ->first() ?? throw new ToolError("No segment {$arguments['segment_id']} in this workspace.");

        /** @var Template $template */
        $template = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');
        $template->setRelation('workspace', $workspace);

        $channel = Channel::query()->where('workspace_id', $workspace->id)->where('type', $template->channel)
            ->when($arguments['channel_id'] ?? null, fn ($query, $id) => $query->whereKey($id), fn ($query) => $query->orderByDesc('is_default'))
            ->first();

        if (! $channel) {
            throw new ToolError(filled($arguments['channel_id'] ?? null)
                ? "Channel {$arguments['channel_id']} isn't a {$template->channel} channel in this workspace."
                : "There is no {$template->channel} channel set up to send this.");
        }

        $broadcast = $workspace->broadcasts()->create([
            'name' => $arguments['name'],
            'status' => 'draft',
            'channel' => $template->channel,
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'channel_id' => $channel->id,
            ...$this->snapshotFrom($template),
        ]);

        Audit::record($context, 'broadcast.drafted', $broadcast, "Drafted {$broadcast->channel} broadcast \"{$broadcast->name}\" to segment \"{$segment->name}\".");

        return [
            'broadcast' => [
                ...$broadcast->only('id', 'name', 'channel', 'status'),
                'segment' => $segment->only('id', 'name'),
                'template' => $template->only('id', 'name'),
                'delivery_channel' => $channel->only('id', 'name', 'driver'),
            ],
            'audience_estimate' => $this->audience->estimate($broadcast->load('segment', 'channelConfiguration')),
            'url' => $context->url('broadcasts.edit', $broadcast),
        ];
    }
}
