<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\ApiKey;

/**
 * "Connect your app": the workspace id, the API endpoint, server keys for a
 * backend and publishable client keys for browser and mobile apps. Until now
 * a key only ever appeared once, in the console that created the workspace.
 */
class ConnectController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $apiPrefix = config('trigger-engage-server.routes.api_prefix');

        return Inertia::render('Connect/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'endpoint' => url($apiPrefix),
            'embedded' => config('trigger-engage-server.mode') === 'embedded',
            'keys' => $workspace->apiKeys()->latest('id')->get()->map(fn (ApiKey $key) => [
                ...$key->only('id', 'name', 'kind', 'prefix', 'public_key', 'allowed_origins', 'allowed_events', 'last_used_at', 'revoked_at', 'created_at'),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate([
            'kind' => ['required', 'in:server,client'],
            'name' => ['required', 'string', 'max:100'],
            'allowed_origins' => ['nullable', 'string', 'max:2000'],
            'allowed_events' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['kind'] === ApiKey::KIND_SERVER) {
            [, $plaintext] = ApiKey::issue($workspace, $data['name']);

            return back()->with('issued', ['kind' => 'server', 'name' => $data['name'], 'key' => $plaintext]);
        }

        $origins = $this->list($data['allowed_origins'] ?? null);
        foreach ($origins as $origin) {
            if (! preg_match('#^https?://[^/\s]+$#', $origin)) {
                return back()->withErrors(['allowed_origins' => "“{$origin}” isn't an origin. Use the scheme and host only, like https://app.example.com."]);
            }
        }

        [$key, $secret] = ApiKey::issueClient($workspace, $data['name'], $origins, $this->list($data['allowed_events'] ?? null));

        return back()->with('issued', ['kind' => 'client', 'name' => $data['name'], 'key' => $key->public_key, 'secret' => $secret]);
    }

    public function revoke(Request $request, ApiKey $key): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($key->workspace_id === $workspace->id, 404);

        // A standalone dashboard signs in with a server key: keep at least one.
        $lastServerKey = $key->kind === ApiKey::KIND_SERVER
            && ! $workspace->apiKeys()->where('kind', ApiKey::KIND_SERVER)->whereNull('revoked_at')->whereKeyNot($key->id)->exists();
        if ($lastServerKey && config('trigger-engage-server.mode') !== 'embedded') {
            return back()->withErrors(['key' => 'This is the last server key, and the dashboard signs in with one. Create another first.']);
        }

        $key->update(['revoked_at' => now()]);

        return back()->with('success', "“{$key->name}” revoked. Anything still using it gets 401 from now on.");
    }

    /** @return array<int, string> */
    protected function list(?string $value): array
    {
        return collect(preg_split('/[\s,]+/', (string) $value))
            ->map(fn (string $item) => Str::of($item)->trim()->rtrim('/')->toString())
            ->filter()->unique()->values()->all();
    }
}
