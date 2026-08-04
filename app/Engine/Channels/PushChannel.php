<?php

namespace TriggerEngage\Server\Engine\Channels;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

/**
 * Push delivery across providers that address recipients very differently:
 *
 * - OneSignal owns the device tokens and is addressed by a user *alias*
 *   (external_id); one API call reaches every device that user logged in on.
 * - Expo is addressed by the *device tokens* themselves, which we therefore
 *   have to hold on the person profile. One call fans out to every token.
 */
class PushChannel
{
    public const EXPO_BASE_URL = 'https://exp.host';

    public function __construct(protected TemplateRenderer $renderer) {}

    /** @return array{message: Message, warnings: array<int, string>}|null */
    public function send(Channel $channel, Template $template, Person $person, array $context, ?RunStep $step = null, ?Message $message = null): ?array
    {
        $driver = $channel->driver ?: 'onesignal';
        $tokens = $driver === 'expo' ? $this->expoTokens($person) : [];
        $address = $driver === 'expo'
            ? $this->describeTokens($tokens)
            : $this->onesignalAlias($person);

        if (blank($address)) {
            return null;
        }

        $this->renderer->reset();
        $subject = $this->renderer->render($template->subject ?? '', $context);
        $body = $this->renderer->render($template->body, $context);
        $credentials = $channel->credentials ?? [];
        $message ??= Message::query()->firstOrCreate(
            ['run_step_id' => $step?->id],
            [
                'workspace_id' => $person->workspace_id,
                'person_id' => $person->id,
                'template_id' => $template->id,
                'channel' => 'push',
                'to_address' => $address,
                'subject' => $subject,
                'body' => $body,
                'status' => 'queued',
            ]
        );

        if ($message->status === 'sent') {
            return ['message' => $message, 'warnings' => $this->renderer->missingVariables()];
        }

        $message->update(['subject' => $subject, 'body' => $body, 'status' => 'sending', 'error' => null]);

        try {
            match ($driver) {
                'expo' => $this->deliverViaExpo($credentials, $message, $person, $tokens, $subject, $body),
                default => $this->deliverViaOnesignal($credentials, $message, $address, $subject, $body),
            };
        } catch (\Throwable $exception) {
            $message->update(['status' => 'failed', 'error' => $exception->getMessage()]);
        }

        return ['message' => $message->refresh(), 'warnings' => $this->renderer->missingVariables()];
    }

    /**
     * The address a push through this driver would target — null means the
     * person is unreachable and a send should be SKIPPED, not failed. The
     * broadcast job uses this so its skip/fail split matches what send() does.
     */
    public function destinationFor(Person $person, ?string $driver): ?string
    {
        return ($driver ?: 'onesignal') === 'expo'
            ? $this->describeTokens($this->expoTokens($person))
            : $this->onesignalAlias($person);
    }

    protected function onesignalAlias(Person $person): ?string
    {
        return ($person->getAttribute('attributes') ?? [])['onesignal_external_id'] ?? $person->external_id;
    }

    /**
     * Expo push tokens live on the profile, written by the app after
     * `getExpoPushTokenAsync()`. Accept either a list (multi-device, preferred)
     * or a bare string, and ignore anything that is not an Expo token so a
     * stray FCM/APNs token cannot poison the whole batch.
     *
     * @return array<int, string>
     */
    protected function expoTokens(Person $person): array
    {
        $attributes = $person->getAttribute('attributes') ?? [];
        $raw = $attributes['expo_push_tokens'] ?? $attributes['expo_push_token'] ?? [];

        return collect(Arr::wrap($raw))
            ->filter(fn ($token) => is_string($token) && preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', trim($token)) === 1)
            ->map(fn (string $token) => trim($token))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * `to_address` is a single varchar, so summarise a multi-device fan-out
     * rather than overflowing it with a comma-joined token list.
     *
     * @param  array<int, string>  $tokens
     */
    protected function describeTokens(array $tokens): ?string
    {
        if ($tokens === []) {
            return null;
        }

        return count($tokens) === 1 ? $tokens[0] : $tokens[0].' +'.(count($tokens) - 1).' more';
    }

    /** @param array<string, mixed> $credentials */
    protected function deliverViaOnesignal(array $credentials, Message $message, string $externalId, string $subject, string $body): void
    {
        // OneSignal's newer API tokens authenticate as "Key <token>", legacy
        // REST keys as "Basic <key>". Try modern first, fall back on an auth
        // rejection so either key type delivers.
        $response = $this->onesignalNotify($credentials, $message, $externalId, $subject, $body, 'Key');

        if (in_array($response->status(), [401, 403], true)) {
            $response = $this->onesignalNotify($credentials, $message, $externalId, $subject, $body, 'Basic');
        }

        $providerId = $response->json('id');

        if ($response->failed() || blank($providerId)) {
            throw new \RuntimeException('OneSignal rejected the message: '.$response->body());
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => (string) $providerId,
            'sent_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $credentials */
    protected function onesignalNotify(array $credentials, Message $message, string $externalId, string $subject, string $body, string $scheme): Response
    {
        return Http::baseUrl('https://api.onesignal.com')
            ->withHeaders(['Authorization' => $scheme.' '.($credentials['api_key'] ?? '')])
            ->timeout((int) ($credentials['timeout'] ?? 10))
            ->retry(2, 250, throw: false)
            ->acceptJson()
            ->asJson()
            ->post('/notifications', [
                'app_id' => $credentials['app_id'] ?? null,
                'include_aliases' => ['external_id' => [$externalId]],
                'target_channel' => 'push',
                'headings' => ['en' => $subject],
                'contents' => ['en' => $body],
                'data' => ['trigger_engage_message_id' => $message->id],
            ]);
    }

    /**
     * Expo answers synchronously with one *ticket* per token. A ticket only
     * says Expo accepted the message — actual handoff to FCM/APNs is confirmed
     * later by PollExpoPushReceipts. A partial failure (one dead token out of
     * three) still counts as sent, because the other devices were reached.
     *
     * @param  array<string, mixed>  $credentials
     * @param  array<int, string>  $tokens
     */
    protected function deliverViaExpo(array $credentials, Message $message, Person $person, array $tokens, string $subject, string $body): void
    {
        $response = $this->expoClient($credentials)
            ->retry(2, 250, throw: false)
            ->post('/--/api/v2/push/send', array_map(fn (string $token) => array_filter([
                'to' => $token,
                'title' => $subject !== '' ? $subject : null,
                'body' => $body,
                'sound' => $credentials['sound'] ?? 'default',
                'priority' => $credentials['priority'] ?? 'high',
                'channelId' => $credentials['android_channel_id'] ?? null,
                'data' => ['trigger_engage_message_id' => $message->id],
            ], fn ($value) => $value !== null), $tokens));

        // A top-level `errors` array means the whole request was rejected
        // (bad access token, malformed body) — no tickets were issued.
        if ($response->failed() || filled($response->json('errors'))) {
            throw new \RuntimeException('Expo rejected the request: '.$response->body());
        }

        $tickets = $response->json('data');

        if (! is_array($tickets) || $tickets === []) {
            throw new \RuntimeException('Expo returned no push tickets: '.$response->body());
        }

        $accepted = [];
        $rejections = [];

        foreach ($tickets as $index => $ticket) {
            $token = $tokens[$index] ?? null;

            if (($ticket['status'] ?? null) === 'ok' && filled($ticket['id'] ?? null)) {
                $accepted[] = (string) $ticket['id'];

                continue;
            }

            $rejections[] = trim(($token ? $token.': ' : '').($ticket['message'] ?? 'unknown error'));

            if (Arr::get($ticket, 'details.error') === 'DeviceNotRegistered' && $token) {
                $this->forgetExpoToken($person, $token);
            }
        }

        if ($accepted === []) {
            throw new \RuntimeException('Expo rejected every token — '.implode('; ', $rejections));
        }

        $message->update([
            'status' => 'sent',
            'provider_message_id' => $accepted[0],
            'pending_receipts' => $accepted,
            'error' => $rejections === [] ? null : 'Partially delivered — '.implode('; ', $rejections),
            'sent_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $credentials */
    public function expoClient(array $credentials): PendingRequest
    {
        $accessToken = $credentials['access_token'] ?? null;

        return Http::baseUrl($credentials['base_url'] ?? self::EXPO_BASE_URL)
            ->when(filled($accessToken), fn (PendingRequest $client) => $client->withToken((string) $accessToken))
            ->timeout((int) ($credentials['timeout'] ?? 10))
            ->acceptJson()
            ->asJson();
    }

    /**
     * Expo is explicit that a DeviceNotRegistered token must never be used
     * again. We prune the token rather than suppressing the person: a
     * reinstall issues a fresh token, and a channel-wide suppression would
     * permanently mute someone who is perfectly reachable again.
     */
    public function forgetExpoToken(Person $person, string $token): void
    {
        $attributes = $person->getAttribute('attributes') ?? [];
        $remaining = array_values(array_diff(Arr::wrap($attributes['expo_push_tokens'] ?? $attributes['expo_push_token'] ?? []), [$token]));

        unset($attributes['expo_push_token']);
        $attributes['expo_push_tokens'] = $remaining;

        $person->forceFill(['attributes' => $attributes])->save();
    }
}
