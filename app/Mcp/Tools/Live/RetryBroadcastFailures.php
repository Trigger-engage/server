<?php

namespace TriggerEngage\Server\Mcp\Tools\Live;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Live\Concerns\ConfirmsLiveAction;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Services\BroadcastSender;

class RetryBroadcastFailures extends Tool
{
    use ConfirmsLiveAction;

    public function __construct(protected BroadcastSender $sender) {}

    public function name(): string
    {
        return 'retry_broadcast_failures';
    }

    public function title(): string
    {
        return 'Retry a broadcast\'s failures';
    }

    public function description(): string
    {
        return 'Send a finished broadcast again to the people it failed for (a provider error, not a skip), as Retry failed '
            .'in the dashboard does. Skipped people (no address, unsubscribed) are left alone. Two calls: a preview (how '
            .'many failed and why) and a confirmation, then the call with that confirmation retries. Fix what made them fail '
            .'first, and retry only if the person says yes.';
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
        return $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast')->load('channelConfiguration');
    }

    protected function guard(Model $subject, Context $context): void
    {
        /** @var Broadcast $subject */
        if ($subject->status !== 'completed') {
            throw new ToolError("Broadcast \"{$subject->name}\" is {$subject->status}. Only a finished broadcast can retry its failures.");
        }

        if (! $subject->recipients()->where('status', 'failed')->exists()) {
            throw new ToolError("Broadcast \"{$subject->name}\" has no failed recipients to retry.");
        }
    }

    protected function fingerprint(Model $subject): string
    {
        /** @var Broadcast $subject */
        return md5(json_encode([
            $subject->status,
            $subject->completed_at?->getTimestamp(),
            $subject->recipients()->where('status', 'failed')->orderBy('id')->pluck('id')->all(),
        ]));
    }

    protected function preview(Model $subject, Context $context): array
    {
        /** @var Broadcast $subject */
        $reasons = $subject->recipients()->where('status', 'failed')
            ->selectRaw('error, count(*) as total')->groupBy('error')->orderByDesc('total')->limit(10)->get();

        return [
            'broadcast' => $subject->only('id', 'name', 'channel'),
            'via' => $subject->channelConfiguration?->only('id', 'name', 'driver'),
            'people_to_retry' => (int) $reasons->sum('total'),
            'why_they_failed' => $reasons->map(fn ($reason) => [
                'reason' => $context->redactor->text($reason->error),
                'people' => (int) $reason->total,
            ])->values()->all(),
            'url' => $context->url('broadcasts.show', $subject),
        ];
    }

    protected function perform(Model $subject, Context $context): array
    {
        /** @var Broadcast $subject */
        $queued = $this->sender->retryFailed($subject, afterResponse: true);

        return [
            'summary' => "Retried broadcast \"{$subject->name}\" for {$queued} people it had failed for.",
            'result' => [
                'broadcast' => $subject->fresh()->only('id', 'name', 'status'),
                'recipients_queued' => $queued,
                'url' => $context->url('broadcasts.show', $subject),
            ],
        ];
    }
}
