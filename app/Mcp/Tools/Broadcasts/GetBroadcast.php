<?php

namespace TriggerEngage\Server\Mcp\Tools\Broadcasts;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\HtmlText;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\BroadcastRecipient;
use TriggerEngage\Server\Services\BroadcastAudience;

class GetBroadcast extends Tool
{
    public function __construct(protected BroadcastAudience $audience) {}

    public function name(): string
    {
        return 'get_broadcast';
    }

    public function title(): string
    {
        return 'Get a broadcast';
    }

    public function description(): string
    {
        return 'One broadcast\'s delivery report: its content, audience, outcome counts (sent, skipped, failed, still '
            .'pending), every distinct skip or failure reason with how many people it hit, and a sample of the people '
            .'who were skipped or failed. A draft shows its pre-send audience estimate instead.';
    }

    public function properties(): array
    {
        return [
            'broadcast_id' => ['type' => 'integer'],
            'sample' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'How many skipped or failed recipients to list (default 10).'],
        ];
    }

    public function required(): array
    {
        return ['broadcast_id'];
    }

    public function rules(): array
    {
        return ['broadcast_id' => ['required', 'integer'], 'sample' => ['nullable', 'integer', 'min:0', 'max:50']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Broadcast $broadcast */
        $broadcast = $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast');
        $broadcast->load('segment:id,name', 'template:id,name', 'channelConfiguration:id,name,driver', 'subscriptionTopic:id,key,name');

        $counts = $broadcast->recipients()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $content = $broadcast->messageTemplate();
        [$body, $truncated] = HtmlText::cap($broadcast->channel === 'email' ? HtmlText::from($content->body) : (string) $content->body, 5000);

        return [
            'broadcast' => [
                ...$broadcast->only('id', 'name', 'channel', 'status'),
                'segment' => $broadcast->segment?->only('id', 'name'),
                'template' => $broadcast->template?->only('id', 'name'),
                'delivery_channel' => $broadcast->channelConfiguration?->only('id', 'name', 'driver'),
                'topic' => $broadcast->subscriptionTopic?->only('key', 'name'),
                'subject' => $context->redactor->text($content->subject),
                'body' => $context->redactor->text($body),
                'body_truncated' => $truncated,
                'created_at' => $context->time($broadcast->created_at),
                'started_at' => $context->time($broadcast->started_at),
                'completed_at' => $context->time($broadcast->completed_at),
                'url' => $context->url($broadcast->isEditable() ? 'broadcasts.edit' : 'broadcasts.show', $broadcast),
            ],
            'audience_estimate' => $broadcast->isEditable() && $broadcast->segment ? $this->audience->estimate($broadcast) : null,
            'counts' => [
                'sent' => (int) ($counts['sent'] ?? 0),
                'skipped' => (int) ($counts['skipped'] ?? 0),
                'failed' => (int) ($counts['failed'] ?? 0),
                'pending' => (int) (($counts['queued'] ?? 0) + ($counts['sending'] ?? 0)),
                'total' => (int) $counts->sum(),
            ],
            'reasons' => $broadcast->recipients()
                ->whereIn('status', ['skipped', 'failed'])
                ->selectRaw('status, error, count(*) as total')
                ->groupBy('status', 'error')
                ->orderByDesc('total')->orderBy('status')->orderBy('error')
                ->limit(20)
                ->get()
                ->map(fn ($reason) => [
                    'status' => $reason->status,
                    'reason' => $context->redactor->text($reason->error),
                    'people' => (int) $reason->total,
                ])->values()->all(),
            'problem_recipients' => $broadcast->recipients()
                ->whereIn('status', ['skipped', 'failed'])
                ->with('person:id,external_id,anonymous_id,email,phone')
                ->orderByDesc('id')
                ->limit((int) ($arguments['sample'] ?? 10))
                ->get()
                ->map(fn (BroadcastRecipient $recipient) => [
                    'person' => $context->person($recipient->person),
                    'status' => $recipient->status,
                    'reason' => $context->redactor->text($recipient->error),
                    'message_id' => $recipient->message_id,
                ])->values()->all(),
        ];
    }
}
