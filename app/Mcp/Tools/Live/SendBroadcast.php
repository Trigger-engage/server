<?php

namespace TriggerEngage\Server\Mcp\Tools\Live;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\HtmlText;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Live\Concerns\ConfirmsLiveAction;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Services\BroadcastAudience;
use TriggerEngage\Server\Services\BroadcastSender;

class SendBroadcast extends Tool
{
    use ConfirmsLiveAction;

    public function __construct(
        protected BroadcastAudience $audience,
        protected BroadcastSender $sender,
    ) {}

    public function name(): string
    {
        return 'send_broadcast';
    }

    public function title(): string
    {
        return 'Send a broadcast';
    }

    public function description(): string
    {
        return 'Send a draft broadcast to its segment, as Send in the dashboard does. This reaches real people and can\'t be '
            .'undone. Two calls: the first returns a preview (who receives it and who is skipped and why, the channel, the '
            .'subject and the start of the message) and a confirmation; only the second call, with that confirmation, '
            .'sends. If the content or the audience changes in between, the confirmation stops working. Show the person the '
            .'preview and send only if they say yes. Follow progress with get_broadcast.';
    }

    public function properties(): array
    {
        return ['broadcast_id' => ['type' => 'integer'], ...$this->confirmationProperty()];
    }

    public function required(): array
    {
        return ['broadcast_id'];
    }

    public function rules(): array
    {
        return ['broadcast_id' => ['required', 'integer'], 'confirmation' => ['nullable', 'string', 'max:200']];
    }

    protected function subject(array $arguments, Context $context): Model
    {
        return $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast')
            ->load('segment', 'channelConfiguration', 'subscriptionTopic', 'template');
    }

    protected function guard(Model $subject, Context $context): void
    {
        /** @var Broadcast $subject */
        if (! $subject->isEditable()) {
            throw new ToolError("Broadcast \"{$subject->name}\" is {$subject->status}; only a draft can be sent. Use get_broadcast for its report.");
        }

        if (! $subject->segment || ! $subject->channelConfiguration) {
            throw new ToolError('This broadcast has lost its segment or its delivery channel. Fix it in the dashboard.');
        }

        if ($this->audience->estimate($subject)['sendable'] === 0) {
            throw new ToolError("Nobody in segment \"{$subject->segment->name}\" can receive this {$subject->channel}: everyone would be skipped. See get_broadcast for why.");
        }
    }

    protected function fingerprint(Model $subject): string
    {
        /** @var Broadcast $subject */
        return md5(json_encode([
            $subject->status,
            $subject->only('name', 'subject', 'body', 'preheader', 'settings', 'from_name', 'from_address', 'segment_id', 'channel_id', 'subscription_topic_id'),
            $subject->updated_at?->getTimestamp(),
            $subject->segment->people()->count(),
        ]));
    }

    protected function preview(Model $subject, Context $context): array
    {
        /** @var Broadcast $subject */
        $content = $subject->messageTemplate();
        [$excerpt, $cut] = HtmlText::cap($subject->channel === 'email' ? HtmlText::from($content->body) : (string) $content->body, 800);

        return [
            'broadcast' => $subject->only('id', 'name', 'channel'),
            'audience' => [
                'segment' => $subject->segment->only('id', 'name'),
                ...$this->audience->estimate($subject),
            ],
            'via' => $subject->channelConfiguration->only('id', 'name', 'driver'),
            'from' => trim(($content->from_name ?? '').' '.($content->from_address ? '<'.$content->from_address.'>' : '')) ?: 'the channel\'s sender',
            'topic' => $subject->subscriptionTopic?->only('key', 'name'),
            'subject' => $context->redactor->text($content->subject),
            'message_start' => $context->redactor->text($excerpt).($cut ? '…' : ''),
            'cannot_be_undone' => true,
            'url' => $context->url('broadcasts.edit', $subject),
        ];
    }

    protected function perform(Model $subject, Context $context): array
    {
        /** @var Broadcast $subject */
        // After the response: a large audience must not hold the caller's
        // request open on a sync queue until it times out mid-send.
        $queued = $this->sender->send($subject, afterResponse: true);

        return [
            'summary' => "Sent broadcast \"{$subject->name}\" to {$queued} people.",
            'result' => [
                'broadcast' => $subject->fresh()->only('id', 'name', 'status'),
                'recipients_queued' => $queued,
                'follow_progress' => 'get_broadcast shows sent, skipped and failed counts as they come in.',
                'url' => $context->url('broadcasts.show', $subject),
            ],
        ];
    }
}
