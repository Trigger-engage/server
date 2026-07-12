<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Jobs\ProcessDeliveryWebhook;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\WebhookEvent;

class DeliveryWebhookController extends Controller
{
    public function termii(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->driver === 'termii' && $channel->type === 'sms', 404);
        $secret = $channel->credentials['secret_key'] ?? null;
        $expected = hash_hmac('sha512', $request->getContent(), (string) $secret);

        abort_unless(filled($secret) && hash_equals($expected, (string) $request->header('X-Termii-Signature')), 401);

        return $this->accept($channel, 'termii', (string) ($request->input('id') ?? $request->input('message_id')), $request->all());
    }

    public function onesignal(Request $request, Channel $channel): JsonResponse
    {
        abort_unless($channel->driver === 'onesignal' && $channel->type === 'push', 404);
        $expected = 'Bearer '.($channel->credentials['webhook_token'] ?? '');

        abort_unless(filled($channel->credentials['webhook_token'] ?? null)
            && hash_equals($expected, (string) $request->header('Authorization')), 401);

        return $this->accept(
            $channel,
            'onesignal',
            (string) ($request->input('event.id') ?? Str::uuid()),
            $request->all()
        );
    }

    protected function accept(Channel $channel, string $provider, string $eventId, array $payload): JsonResponse
    {
        abort_if(blank($eventId), 422, 'Provider event id is required.');

        $event = WebhookEvent::query()->firstOrCreate(
            ['provider' => $provider, 'provider_event_id' => $eventId],
            ['channel_id' => $channel->id, 'payload' => $payload]
        );

        if ($event->wasRecentlyCreated) {
            ProcessDeliveryWebhook::dispatch($event->id);
        }

        return response()->json(['accepted' => true], 202);
    }
}
