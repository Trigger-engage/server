<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\WebhookEvent;

class ProcessDeliveryWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    public function __construct(public int $eventId) {}

    public function handle(): void
    {
        $event = WebhookEvent::query()->find($this->eventId);

        if (! $event || $event->processed_at) {
            return;
        }

        try {
            $payload = $event->payload;
            $providerMessageId = $event->provider === 'termii'
                ? (string) ($payload['message_id'] ?? $payload['id'] ?? '')
                : (string) Arr::get($payload, 'message.id', '');
            $message = Message::query()->where('provider_message_id', $providerMessageId)->first();

            if (! $message) {
                throw new \RuntimeException("Message [{$providerMessageId}] was not found.");
            }

            $kind = strtolower((string) ($event->provider === 'termii'
                ? ($payload['status'] ?? '')
                : Arr::get($payload, 'event.kind', '')));

            $updates = match (true) {
                Str::contains($kind, ['delivered', 'received']) => ['status' => 'delivered', 'delivered_at' => now()],
                Str::contains($kind, ['opened']) => ['opened_at' => now()],
                Str::contains($kind, ['clicked']) => ['clicked_at' => now()],
                Str::contains($kind, ['bounced']) => ['status' => 'bounced', 'bounced_at' => now()],
                Str::contains($kind, ['failed', 'rejected', 'expired']) => ['status' => 'failed'],
                default => [],
            };

            if ($updates) {
                $message->update($updates);
            }

            if (Str::contains($kind, ['unsubscribed', 'bounced'])) {
                $message->person->suppressions()->updateOrCreate(
                    ['workspace_id' => $message->workspace_id, 'channel' => $message->channel],
                    ['reason' => Str::contains($kind, 'bounced') ? 'bounce' : 'unsubscribe']
                );
            }

            $event->update(['processed_at' => now(), 'error' => null]);
        } catch (\Throwable $exception) {
            $event->update(['error' => $exception->getMessage()]);
            throw $exception;
        }
    }
}
