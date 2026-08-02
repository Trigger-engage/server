<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use TriggerEngage\Server\Engine\Channels\PushChannel;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;

/**
 * Expo offers no delivery webhook, so the terminal state of a push has to be
 * pulled. Every send leaves its ticket ids in `messages.pending_receipts`;
 * this job drains them and settles each message.
 *
 * Expo only retains receipts for about 24 hours, so anything older than that
 * is abandoned rather than polled forever.
 */
class PollExpoPushReceipts implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const RECEIPT_TTL_HOURS = 24;

    public int $tries = 3;

    public function __construct(public int $workspaceId) {}

    public function handle(PushChannel $push): void
    {
        $channel = Channel::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('type', 'push')
            ->where('driver', 'expo')
            ->orderByDesc('is_default')
            ->first();

        if (! $channel) {
            return;
        }

        $pending = Message::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('channel', 'push')
            ->where('status', 'sent')
            ->whereNotNull('pending_receipts')
            ->where('sent_at', '>', now()->subHours(self::RECEIPT_TTL_HOURS))
            ->get()
            ->filter(fn (Message $message) => filled($message->pending_receipts));

        $this->abandonExpiredReceipts();

        if ($pending->isEmpty()) {
            return;
        }

        // Expo accepts up to 1000 ticket ids per lookup.
        foreach ($pending->chunk(300) as $batch) {
            $this->settle($push, $channel, $batch);
        }
    }

    /** @param Collection<int, Message> $batch */
    protected function settle(PushChannel $push, Channel $channel, Collection $batch): void
    {
        $owners = [];

        foreach ($batch as $message) {
            foreach ($message->pending_receipts as $ticketId) {
                $owners[$ticketId] = $message;
            }
        }

        $response = $push->expoClient($channel->credentials ?? [])
            ->retry(2, 250, throw: false)
            ->post('/--/api/v2/push/getReceipts', ['ids' => array_keys($owners)]);

        if ($response->failed()) {
            throw new \RuntimeException('Expo receipt lookup failed: '.$response->body());
        }

        $receipts = $response->json('data');

        if (! is_array($receipts)) {
            return;
        }

        /** @var array<int, array{message: Message, ok: int, errors: array<int, string>, resolved: array<int, string>}> $outcomes */
        $outcomes = [];

        foreach ($receipts as $ticketId => $receipt) {
            $message = $owners[$ticketId] ?? null;

            if (! $message) {
                continue;
            }

            $outcomes[$message->id] ??= ['message' => $message, 'ok' => 0, 'errors' => [], 'resolved' => []];
            $outcomes[$message->id]['resolved'][] = (string) $ticketId;

            if (($receipt['status'] ?? null) === 'ok') {
                $outcomes[$message->id]['ok']++;

                continue;
            }

            $outcomes[$message->id]['errors'][] = (string) ($receipt['message'] ?? 'unknown error');

            if (Arr::get($receipt, 'details.error') === 'DeviceNotRegistered') {
                $this->pruneToken($push, $message, (string) $ticketId);
            }
        }

        foreach ($outcomes as $outcome) {
            $this->apply($outcome['message'], $outcome['ok'], $outcome['errors'], $outcome['resolved']);
        }
    }

    /**
     * @param  array<int, string>  $errors
     * @param  array<int, string>  $resolved
     */
    protected function apply(Message $message, int $ok, array $errors, array $resolved): void
    {
        $remaining = array_values(array_diff($message->pending_receipts ?? [], $resolved));
        $updates = ['pending_receipts' => $remaining];

        if ($ok > 0) {
            // At least one device took delivery; that settles the message even
            // if a sibling token failed. The failures stay on `error` so the
            // partial outcome is still visible in the UI.
            $updates += ['status' => 'delivered', 'delivered_at' => $message->delivered_at ?? now()];
        }

        if ($errors !== []) {
            $updates['error'] = trim(($message->error ? $message->error.' | ' : '').'Receipt errors — '.implode('; ', $errors));
        }

        // Every ticket came back and none of them landed.
        if ($ok === 0 && $errors !== [] && $remaining === [] && $message->status !== 'delivered') {
            $updates['status'] = 'failed';
        }

        $message->update($updates);
    }

    /**
     * The receipt names a ticket, not a token, so recover the token from the
     * ticket's position in the original send.
     */
    protected function pruneToken(PushChannel $push, Message $message, string $ticketId): void
    {
        $index = array_search($ticketId, $message->pending_receipts ?? [], true);
        $person = $message->person;

        if ($index === false || ! $person) {
            return;
        }

        // `attributes` is a real column here, so it has to be read through
        // getAttribute() — the bare property is Eloquent's own state.
        $tokens = array_values(array_filter(
            Arr::wrap(($person->getAttribute('attributes') ?? [])['expo_push_tokens'] ?? []),
            fn ($token) => is_string($token)
        ));

        // Single-token sends are unambiguous; a multi-device fan-out only lines
        // up when the profile still holds the same token list it was sent to.
        if (count($tokens) === 1) {
            $push->forgetExpoToken($person, $tokens[0]);
        } elseif (isset($tokens[$index]) && count($tokens) === count($message->pending_receipts ?? [])) {
            $push->forgetExpoToken($person, $tokens[$index]);
        }
    }

    /**
     * Expo drops receipts after ~24h. Stop asking so these rows do not get
     * re-queried on every tick forever; the message keeps its `sent` status,
     * which is the last thing we could actually confirm.
     */
    protected function abandonExpiredReceipts(): void
    {
        Message::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('channel', 'push')
            ->whereNotNull('pending_receipts')
            ->where('sent_at', '<=', now()->subHours(self::RECEIPT_TTL_HOURS))
            ->update(['pending_receipts' => null]);
    }
}
