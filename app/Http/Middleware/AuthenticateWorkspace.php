<?php

namespace TriggerEngage\Server\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Workspace;

/**
 * Credentials are the COMBINATION of workspace id and API key, sent as HTTP
 * Basic auth (username = workspace public id, password = plaintext key).
 * A key is only valid inside the workspace it was issued for.
 */
class AuthenticateWorkspace
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspaceId = $request->getUser();
        $plaintextKey = $request->getPassword();

        if (blank($workspaceId) || blank($plaintextKey)) {
            return $this->unauthorized();
        }

        $workspace = Workspace::query()->where('public_id', $workspaceId)->first();

        if (! $workspace) {
            return $this->unauthorized();
        }

        $apiKey = ApiKey::query()
            ->where('workspace_id', $workspace->id)
            ->where('key_hash', hash('sha256', $plaintextKey))
            ->first();

        if (! $apiKey) {
            return $this->unauthorized();
        }

        if (! $apiKey->last_used_at || $apiKey->last_used_at->lt(now()->subMinute())) {
            $apiKey->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }

    protected function unauthorized(): Response
    {
        return response()->json([
            'message' => 'Invalid workspace credentials. Authenticate with Basic auth: workspace id as username, API key as password.',
        ], 401, ['WWW-Authenticate' => 'Basic realm="trigger-engage"']);
    }
}
