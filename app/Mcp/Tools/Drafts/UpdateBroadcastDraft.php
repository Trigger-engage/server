<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsContent;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Services\BroadcastAudience;

class UpdateBroadcastDraft extends Tool
{
    use DraftsContent;

    public function __construct(EmailLayoutRenderer $layouts, TemplateRenderer $renderer, protected BroadcastAudience $audience)
    {
        $this->layouts = $layouts;
        $this->renderer = $renderer;
    }

    public function name(): string
    {
        return 'update_broadcast_draft';
    }

    public function title(): string
    {
        return 'Edit a broadcast draft';
    }

    public function description(): string
    {
        return 'Change a draft broadcast\'s name or content (subject, body, preheader, sender, delivery settings, topic). '
            .'Name only the fields that change. Only drafts can be edited: once a person sends one it is history. The '
            .'audience and channel stay as created, as in the dashboard\'s composer.';
    }

    public function properties(): array
    {
        return ['broadcast_id' => ['type' => 'integer'], ...$this->contentProperties(creating: false)];
    }

    public function required(): array
    {
        return ['broadcast_id'];
    }

    public function rules(): array
    {
        return ['broadcast_id' => ['required', 'integer'], ...$this->contentRulesFor(creating: false)];
    }

    public function annotations(): array
    {
        return [...parent::annotations(), 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Broadcast $broadcast */
        $broadcast = $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast');

        if (! $broadcast->isEditable()) {
            throw new ToolError("Broadcast {$broadcast->id} is {$broadcast->status}, not a draft, so it can't be edited.");
        }

        $content = [
            ...$broadcast->only('name', 'subject', 'body', 'layout', 'preheader', 'from_name', 'from_address', 'settings'),
            ...collect($arguments)->except(['broadcast_id', 'subscription_topic'])->all(),
            'channel' => $broadcast->channel,
        ];

        $broadcast->update([
            ...$this->checkedContent($content, $context),
            'subscription_topic_id' => $this->topicId($arguments, $context, $broadcast->subscription_topic_id),
        ]);

        $fields = array_keys(collect($arguments)->except('broadcast_id')->all());
        Audit::record($context, 'broadcast.updated', $broadcast, "Edited broadcast draft \"{$broadcast->name}\": ".implode(', ', $fields).'.', ['fields' => $fields]);

        return [
            'broadcast' => $broadcast->only('id', 'name', 'channel', 'status', 'subject'),
            'changed' => $fields,
            'empty_in_preview' => $this->previewWarnings($content, $context),
            'audience_estimate' => $this->audience->estimate($broadcast->load('segment', 'channelConfiguration', 'subscriptionTopic')),
            'url' => $context->url('broadcasts.edit', $broadcast),
        ];
    }
}
