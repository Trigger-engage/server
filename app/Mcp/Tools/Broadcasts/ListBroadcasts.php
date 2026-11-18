<?php

namespace TriggerEngage\Server\Mcp\Tools\Broadcasts;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Services\BroadcastAudience;

class ListBroadcasts extends Tool
{
    public function __construct(protected BroadcastAudience $audience) {}

    public function name(): string
    {
        return 'list_broadcasts';
    }

    public function title(): string
    {
        return 'List broadcasts';
    }

    public function description(): string
    {
        return 'One-off sends to a segment, newest first: channel, status, audience segment, template and delivery '
            .'channel, and outcome counts (sent, skipped, failed). Drafts carry a pre-send audience estimate: how many '
            .'members would actually receive it and how many would be skipped for no address, suppression or a topic opt-out.';
    }

    public function properties(): array
    {
        return [
            'status' => ['type' => 'string', 'description' => 'e.g. draft, sending, sent.'],
            'search' => ['type' => 'string', 'description' => 'Only broadcasts whose name contains this text.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', 'max:30'],
            'search' => ['nullable', 'string', 'max:150'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $query = Broadcast::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($arguments['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->with('segment:id,name', 'template:id,name', 'channelConfiguration:id,name,driver')
            ->withCount([
                'recipients',
                'recipients as sent_count' => fn ($query) => $query->where('status', 'sent'),
                'recipients as skipped_count' => fn ($query) => $query->where('status', 'skipped'),
                'recipients as failed_count' => fn ($query) => $query->where('status', 'failed'),
            ])
            ->orderByDesc('id');

        return $this->page($query, $arguments, fn (Broadcast $broadcast) => [
            ...$broadcast->only('id', 'name', 'channel', 'status'),
            'segment' => $broadcast->segment?->only('id', 'name'),
            'template' => $broadcast->template?->only('id', 'name'),
            'delivery_channel' => $broadcast->channelConfiguration?->only('id', 'name', 'driver'),
            'recipients' => [
                'total' => (int) $broadcast->recipients_count,
                'sent' => (int) $broadcast->sent_count,
                'skipped' => (int) $broadcast->skipped_count,
                'failed' => (int) $broadcast->failed_count,
            ],
            'audience_estimate' => $broadcast->isEditable() && $broadcast->segment ? $this->audience->estimate($broadcast) : null,
            'created_at' => $context->time($broadcast->created_at),
            'started_at' => $context->time($broadcast->started_at),
            'completed_at' => $context->time($broadcast->completed_at),
            'url' => $context->url($broadcast->isEditable() ? 'broadcasts.edit' : 'broadcasts.show', $broadcast),
        ]);
    }
}
