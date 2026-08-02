<?php

namespace TriggerEngage\Server\Engine\Channels;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Smtp\SmtpTransport;
use Throwable;

/**
 * Probes a channel's provider credentials without persisting anything or
 * sending a real message, so operators can confirm a connection works before
 * saving it. Each driver returns a uniform {ok, message} result.
 */
class ChannelConnectionTester
{
    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    public function test(string $driver, array $credentials): array
    {
        return match ($driver) {
            'smtp' => $this->testSmtp($credentials),
            'termii' => $this->testTermii($credentials),
            'onesignal' => $this->testOnesignal($credentials),
            'expo' => $this->testExpo($credentials),
            'log' => $this->ok('Log driver needs no connection — messages are written to the application log.'),
            default => $this->fail("Unknown driver [{$driver}]."),
        };
    }

    /**
     * Opens the SMTP connection and authenticates using the exact transport the
     * send path builds, then closes it — no email is dispatched.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    protected function testSmtp(array $credentials): array
    {
        try {
            $transport = Mail::createSymfonyTransport([
                'transport' => 'smtp',
                'host' => $credentials['host'] ?? null,
                'port' => (int) ($credentials['port'] ?? 587),
                'username' => $credentials['username'] ?? null,
                'password' => $credentials['password'] ?? null,
                'encryption' => $credentials['encryption'] ?? 'tls',
                'timeout' => (int) ($credentials['timeout'] ?? 10),
            ]);

            if ($transport instanceof SmtpTransport) {
                $transport->start();
                $transport->stop();
            }

            return $this->ok('Connected and authenticated with the SMTP server.');
        } catch (Throwable $exception) {
            return $this->fail('Could not connect: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    protected function testTermii(array $credentials): array
    {
        $baseUrl = rtrim((string) ($credentials['base_url'] ?? ''), '/');

        if (blank($baseUrl) || blank($credentials['api_key'] ?? null)) {
            return $this->fail('A base URL and API key are required to test Termii.');
        }

        try {
            $response = Http::timeout((int) ($credentials['timeout'] ?? 10))
                ->acceptJson()
                ->get($baseUrl.'/api/get-balance', ['api_key' => $credentials['api_key']]);

            if ($response->successful() && $response->json('balance') !== null) {
                return $this->ok('Termii credentials are valid. Balance: '.$response->json('balance').' '.($response->json('currency') ?? ''));
            }

            return $this->fail('Termii rejected the credentials: '.trim($response->body()));
        } catch (Throwable $exception) {
            return $this->fail('Could not reach Termii: '.$exception->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    protected function testOnesignal(array $credentials): array
    {
        if (blank($credentials['app_id'] ?? null) || blank($credentials['api_key'] ?? null)) {
            return $this->fail('An app ID and REST API key are required to test OneSignal.');
        }

        try {
            $response = Http::baseUrl('https://api.onesignal.com')
                ->withHeaders(['Authorization' => 'Key '.$credentials['api_key']])
                ->timeout((int) ($credentials['timeout'] ?? 10))
                ->acceptJson()
                ->get('/notifications', ['app_id' => $credentials['app_id'], 'limit' => 1]);

            if ($response->successful()) {
                return $this->ok('OneSignal app ID and REST API key are valid.');
            }

            return $this->fail('OneSignal rejected the credentials: '.trim($response->body()));
        } catch (Throwable $exception) {
            return $this->fail('Could not reach OneSignal: '.$exception->getMessage());
        }
    }

    /**
     * Expo has no credential-introspection endpoint, so probe with an empty
     * receipt lookup: it is free, sends nothing, and still exercises the
     * access token when a project has enhanced security switched on.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{ok: bool, message: string}
     */
    protected function testExpo(array $credentials): array
    {
        try {
            $response = Http::baseUrl($credentials['base_url'] ?? PushChannel::EXPO_BASE_URL)
                ->when(filled($credentials['access_token'] ?? null), fn ($client) => $client->withToken((string) $credentials['access_token']))
                ->timeout((int) ($credentials['timeout'] ?? 10))
                ->acceptJson()
                ->asJson()
                ->post('/--/api/v2/push/getReceipts', ['ids' => []]);

            $error = $response->json('errors.0.code');

            if ($error === 'UNAUTHORIZED' || $response->status() === 401) {
                return $this->fail('Expo rejected the access token. Check the token in your Expo account settings.');
            }

            if ($response->failed()) {
                return $this->fail('Expo rejected the request: '.trim($response->body()));
            }

            return $this->ok(filled($credentials['access_token'] ?? null)
                ? 'Reached the Expo push service and the access token is valid.'
                : 'Reached the Expo push service. No access token set — fine unless your project enforces one.');
        } catch (Throwable $exception) {
            return $this->fail('Could not reach Expo: '.$exception->getMessage());
        }
    }

    /** @return array{ok: bool, message: string} */
    protected function ok(string $message): array
    {
        return ['ok' => true, 'message' => $message];
    }

    /** @return array{ok: bool, message: string} */
    protected function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message];
    }
}
