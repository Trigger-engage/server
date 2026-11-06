<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\Channels\ChannelConnectionTester;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\AutomationVersion;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Workspace;

class ChannelController extends Controller
{
    /** Drivers each channel type can use. */
    public const DRIVERS = [
        'email' => ['log', 'smtp'],
        'sms' => ['log', 'termii'],
        'push' => ['log', 'onesignal', 'expo'],
    ];

    /**
     * Credential fields that are never sent back to the browser. On edit a
     * blank value keeps the stored one, so a rename never needs the password.
     */
    public const SECRETS = ['password', 'api_key', 'secret_key', 'webhook_token', 'access_token'];

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $usage = $this->usage($workspace);

        return Inertia::render('Channels/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'channels' => $workspace->channels()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get()
                ->map(fn (Channel $channel) => [
                    ...$channel->only('id', 'type', 'name', 'driver', 'is_default', 'updated_at'),
                    // Everything but secrets, which only say whether one is stored.
                    'settings' => Arr::except($channel->credentials ?? [], self::SECRETS),
                    'secrets' => collect(self::SECRETS)
                        ->filter(fn (string $key) => filled($channel->credentials[$key] ?? null))
                        ->values(),
                    'webhook_url' => in_array($channel->driver, ['termii', 'onesignal'], true)
                        ? url(config('trigger-engage-server.routes.api_prefix')."/webhooks/{$channel->driver}/{$channel->id}")
                        : null,
                    'usage' => $usage[$channel->id] ?? ['automations' => [], 'broadcasts' => 0],
                ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'in:email,sms,push'],
            'name' => ['required', 'string', 'max:150'],
            'driver' => ['required', 'in:log,smtp,termii,onesignal,expo'],
            'is_default' => ['boolean'],
            ...$this->credentialRules(),
        ]);
        $workspace = $request->attributes->get('workspace');
        $this->ensureDriverFits($validated['type'], $validated['driver']);

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
     * Rename, re-point or re-key a channel in place. Automations and broadcasts
     * keep their reference, so moving the seeded log channel onto SMTP is an
     * edit, not a rebuild of every journey that uses it.
     */
    public function update(Request $request, Channel $channel): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace, $channel);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'driver' => ['required', 'in:log,smtp,termii,onesignal,expo'],
            ...$this->credentialRules(),
        ]);
        $this->ensureDriverFits($channel->type, $validated['driver']);

        $channel->update([
            'name' => $validated['name'],
            'driver' => $validated['driver'],
            'credentials' => $this->credentialsFor($validated['driver'], $this->withStoredSecrets($channel, $validated)),
        ]);

        return back()->with('success', "“{$channel->name}” saved. The next send uses these settings.");
    }

    public function makeDefault(Request $request, Channel $channel): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace, $channel);

        DB::transaction(function () use ($workspace, $channel): void {
            $workspace->channels()->where('type', $channel->type)->update(['is_default' => false]);
            $channel->update(['is_default' => true]);
        });

        return back()->with('success', "“{$channel->name}” is now the default {$channel->type} channel.");
    }

    /**
     * Deleting a channel an automation or broadcast still names would fail
     * their sends ("Missing template or channel"), so it is refused with
     * where it is used. In-flight runs count: they stay on the version they
     * started on, even after a newer one is published.
     */
    public function destroy(Request $request, Channel $channel): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureOwned($workspace, $channel);
        $usage = $this->usage($workspace)[$channel->id] ?? ['automations' => [], 'broadcasts' => 0];

        if ($usage['automations'] || $usage['broadcasts']) {
            $where = array_filter([
                $usage['automations'] ? 'automations: '.implode(', ', $usage['automations']) : null,
                $usage['broadcasts'] ? $usage['broadcasts'].' '.str('broadcast')->plural($usage['broadcasts']) : null,
            ]);
            throw ValidationException::withMessages([
                'channel' => "“{$channel->name}” is still used by ".implode(' and ', $where).'. Switch them to another channel first.',
            ]);
        }

        $promoted = DB::transaction(function () use ($workspace, $channel): ?Channel {
            $channel->delete();
            if (! $channel->is_default) {
                return null;
            }
            $next = $workspace->channels()->where('type', $channel->type)->oldest('id')->first();
            $next?->update(['is_default' => true]);

            return $next;
        });

        return back()->with('success', "“{$channel->name}” deleted.".($promoted ? " “{$promoted->name}” is now the default {$channel->type} channel." : ''));
    }

    /**
     * Probe provider credentials without saving them. With a channel_id, any
     * secret left blank is taken from that channel, so the edit form can test
     * a changed host without re-typing the password.
     */
    public function test(Request $request, ChannelConnectionTester $tester): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'in:log,smtp,termii,onesignal,expo'],
            'channel_id' => ['nullable', 'integer'],
            ...$this->credentialRules(),
        ]);

        if (filled($validated['channel_id'] ?? null)) {
            $channel = $request->attributes->get('workspace')->channels()->findOrFail($validated['channel_id']);
            $validated = $this->withStoredSecrets($channel, $validated);
        }

        $result = $tester->test($validated['driver'], $this->credentialsFor($validated['driver'], $validated) ?? []);

        return back()->with($result['ok'] ? 'success' : 'error', $result['message']);
    }

    /** Re-test what is saved, from the channel's card. */
    public function testStored(Request $request, Channel $channel, ChannelConnectionTester $tester): RedirectResponse
    {
        $this->ensureOwned($request->attributes->get('workspace'), $channel);
        $result = $tester->test($channel->driver, $channel->credentials ?? []);

        return back()->with($result['ok'] ? 'success' : 'error', "{$channel->name}: {$result['message']}");
    }

    /** @return array<string, array<int, string>> */
    protected function credentialRules(): array
    {
        return [
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
            'access_token' => ['nullable', 'string', 'max:500'],
            'priority' => ['nullable', 'in:default,normal,high'],
            'sound' => ['nullable', 'string', 'max:100'],
            'android_channel_id' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Fill blank secrets from the stored channel, but only while the driver is
     * unchanged: an SMTP password must not leak into a new provider's api_key.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function withStoredSecrets(Channel $channel, array $validated): array
    {
        if ($channel->driver !== $validated['driver']) {
            return $validated;
        }

        foreach (self::SECRETS as $key) {
            if (blank($validated[$key] ?? null) && filled($channel->credentials[$key] ?? null)) {
                $validated[$key] = $channel->credentials[$key];
            }
        }

        return $validated;
    }

    protected function ensureDriverFits(string $type, string $driver): void
    {
        if (! in_array($driver, self::DRIVERS[$type] ?? [], true)) {
            throw ValidationException::withMessages(['driver' => "A {$type} channel can't use the {$driver} driver."]);
        }
    }

    protected function ensureOwned(Workspace $workspace, Channel $channel): void
    {
        abort_unless($channel->workspace_id === $workspace->id, 404);
    }

    /**
     * Which automations (by name) and how many broadcasts name each channel.
     * Scans each automation's published version plus any older version a run
     * is still on, in send nodes and A/B variants. A send step that names no
     * channel uses its type's default, so it counts against the default.
     *
     * @return array<int, array{automations: array<int, string>, broadcasts: int}>
     */
    protected function usage(Workspace $workspace): array
    {
        $usage = [];
        $defaults = $workspace->channels()->where('is_default', true)->pluck('id', 'type');
        $automations = $workspace->automations()->get(['id', 'name', 'active_version_id']);
        $versionIds = $automations->pluck('active_version_id')->filter()
            ->merge(AutomationRun::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', AutomationRun::activeStatuses())
                ->distinct()->pluck('automation_version_id'))
            ->unique();

        AutomationVersion::query()->whereIn('id', $versionIds)->get(['id', 'automation_id', 'graph'])
            ->each(function (AutomationVersion $version) use (&$usage, $automations, $defaults): void {
                $name = $automations->firstWhere('id', $version->automation_id)?->name;
                foreach ($version->graph['nodes'] ?? [] as $node) {
                    $ids = [$node['config']['channel_id'] ?? null, ...array_column($node['config']['variants'] ?? [], 'channel_id')];
                    $sendType = ['send_email' => 'email', 'send_sms' => 'sms', 'send_push' => 'push'][$node['type'] ?? ''] ?? null;
                    if ($sendType && blank($node['config']['channel_id'] ?? null)) {
                        $ids[] = $defaults[$sendType] ?? null;
                    }
                    foreach (array_filter($ids) as $channelId) {
                        $usage[(int) $channelId]['automations'][$name] = $name;
                    }
                }
            });

        $workspace->broadcasts()->selectRaw('channel_id, count(*) as total')->groupBy('channel_id')->pluck('total', 'channel_id')
            ->each(function ($total, $channelId) use (&$usage): void {
                $usage[(int) $channelId]['broadcasts'] = (int) $total;
            });

        return array_map(fn (array $entry) => [
            'automations' => array_values($entry['automations'] ?? []),
            'broadcasts' => $entry['broadcasts'] ?? 0,
        ], $usage);
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
            // Expo needs no project identifier — the push token itself names
            // the project. The access token is only required when the Expo
            // project enforces one.
            'expo' => [
                'access_token' => $validated['access_token'] ?? null,
                'priority' => $validated['priority'] ?? 'high',
                'sound' => $validated['sound'] ?? 'default',
                'android_channel_id' => $validated['android_channel_id'] ?? null,
            ],
            default => null,
        };
    }
}
