<?php

namespace TriggerEngage\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Workspace;

/**
 * Authenticates an MCP client with a workspace's server key.
 *
 * MCP clients are configured with one header, so the key alone is enough:
 * `Authorization: Bearer te_…` finds its workspace by the key's hash. The
 * API's own Basic scheme (workspace id + key) works too. Client keys (pk_…)
 * are public by design and never open this endpoint, and neither does a
 * revoked key.
 */
class AuthenticateMcp
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $this->resolveKey($request);

        if (! $key) {
            return response()->json([
                'message' => 'Authenticate with a Trigger Engage server API key: Authorization: Bearer te_…',
            ], 401, ['WWW-Authenticate' => 'Bearer realm="trigger-engage"']);
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('workspace', $key->workspace);
        $request->attributes->set('trigger-engage.mcp.actor', 'api-key:'.$key->name);
        // Server keys draft only where the operator switched drafting on.
        $request->attributes->set('trigger-engage.mcp.can-write', (bool) config('trigger-engage-server.mcp.writes', false));
        $request->attributes->set('trigger-engage.mcp.test-recipient', config('trigger-engage-server.mcp.test_recipient'));

        return $next($request);
    }

    protected function resolveKey(Request $request): ?ApiKey
    {
        $bearer = $request->bearerToken();

        if (filled($bearer)) {
            return $this->serverKeys()
                ->where('key_hash', hash('sha256', $bearer))
                ->with('workspace')
                ->first();
        }

        $workspaceId = $request->getUser();
        $plaintext = $request->getPassword();

        if (blank($workspaceId) || blank($plaintext)) {
            return null;
        }

        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();

        return $workspace
            ? $this->serverKeys()->where('workspace_id', $workspace->id)->where('key_hash', hash('sha256', $plaintext))->with('workspace')->first()
            : null;
    }

    protected function serverKeys()
    {
        return ApiKey::query()->where('kind', ApiKey::KIND_SERVER)->whereNull('revoked_at');
    }
}
