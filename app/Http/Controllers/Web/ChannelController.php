<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\Channels\ChannelConnectionTester;
use TriggerEngage\Server\Http\Controllers\Controller;

class ChannelController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Channels/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'channels' => $workspace->channels()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'driver', 'is_default', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:email,sms,push'],
            'name' => ['required', 'string', 'max:150'],
            'driver' => ['required', 'in:log,smtp,termii,onesignal'],
            'is_default' => ['boolean'],
            'host' => ['required_if:driver,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:driver,smtp', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'base_url' => ['nullable', 'url'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'sender_id' => ['nullable', 'string', 'max:20'],
            'route' => ['nullable', 'in:dnd,generic'],
            'app_id' => ['nullable', 'string', 'max:150'],
            'webhook_token' => ['nullable', 'string', 'min:24', 'max:500'],
        ]);
        $workspace = $request->attributes->get('workspace');

        abort_if($validated['driver'] === 'smtp' && $validated['type'] !== 'email', 422);
        abort_if($validated['driver'] === 'termii' && $validated['type'] !== 'sms', 422);
        abort_if($validated['driver'] === 'onesignal' && $validated['type'] !== 'push', 422);

        DB::transaction(function () use ($workspace, $validated): void {
            if ($validated['is_default'] ?? false) {
                $workspace->channels()->where('type', $validated['type'])->update(['is_default' => false]);
            }

            $workspace->channels()->create([
                'type' => $validated['type'],
                'driver' => $validated['driver'],
                'name' => $validated['name'],
                'is_default' => $validated['is_default'] ?? false,
                'credentials' => $this->credentialsFor($validated['driver'], $validated),
            ]);
        });

        return back()->with('success', ucfirst($validated['type']).' channel created.');
    }

    /**
     * Probe the provider credentials without persisting a channel, so an
     * operator can confirm a connection works before saving it.
     */
    public function test(Request $request, ChannelConnectionTester $tester): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'in:log,smtp,termii,onesignal'],
            'host' => ['required_if:driver,smtp', 'nullable', 'string', 'max:255'],
            'port' => ['required_if:driver,smtp', 'nullable', 'integer', 'between:1,65535'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'encryption' => ['nullable', 'in:tls,ssl'],
            'base_url' => ['nullable', 'url'],
            'api_key' => ['nullable', 'string', 'max:500'],
            'secret_key' => ['nullable', 'string', 'max:500'],
            'sender_id' => ['nullable', 'string', 'max:20'],
            'route' => ['nullable', 'in:dnd,generic'],
            'app_id' => ['nullable', 'string', 'max:150'],
            'webhook_token' => ['nullable', 'string', 'min:24', 'max:500'],
        ]);

        $result = $tester->test($validated['driver'], $this->credentialsFor($validated['driver'], $validated) ?? []);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /**
     * Shape the validated request into the encrypted credentials payload for a driver.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>|null
     */
    protected function credentialsFor(string $driver, array $validated): ?array
    {
        return match ($driver) {
            'smtp' => [
                'host' => $validated['host'] ?? null,
                'port' => $validated['port'] ?? null,
                'username' => $validated['username'] ?? null,
                'password' => $validated['password'] ?? null,
                'encryption' => $validated['encryption'] ?? 'tls',
            ],
            'termii' => [
                'base_url' => $validated['base_url'] ?? null,
                'api_key' => $validated['api_key'] ?? null,
                'secret_key' => $validated['secret_key'] ?? null,
                'sender_id' => $validated['sender_id'] ?? null,
                'route' => $validated['route'] ?? 'dnd',
            ],
            'onesignal' => [
                'app_id' => $validated['app_id'] ?? null,
                'api_key' => $validated['api_key'] ?? null,
                'webhook_token' => $validated['webhook_token'] ?? null,
            ],
            default => null,
        };
    }
}
