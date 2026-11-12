<?php

namespace TriggerEngage\Server\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Redactor;
use TriggerEngage\Server\Mcp\Server;
use TriggerEngage\Server\Models\Workspace;

/**
 * POST {mcp path} — Trigger Engage as an MCP server. The route's middleware
 * authenticates the caller and settles the workspace (a server key by
 * default, or the host's own auth plus `trigger-engage.workspace` when
 * embedded); this only builds the Context and hands over to the protocol.
 */
class McpController extends Controller
{
    public function handle(Request $request, Server $server): JsonResponse|Response
    {
        $workspace = $request->attributes->get('workspace');

        abort_unless($workspace instanceof Workspace, 401, 'The MCP route needs middleware that settles the workspace.');

        $actor = $request->attributes->get('trigger-engage.mcp.actor')
            ?? ($request->user() ? 'user:'.$request->user()->getAuthIdentifier() : 'mcp');

        return $server->handle($request, new Context(
            workspace: $workspace,
            redactor: new Redactor((bool) config('trigger-engage-server.mcp.reveal_contact_details', false)),
            actor: $actor,
            canWrite: (bool) $request->attributes->get('trigger-engage.mcp.can-write', false),
            testRecipient: $request->attributes->get('trigger-engage.mcp.test-recipient'),
        ));
    }

    public function unsupported(): JsonResponse
    {
        return response()->json([
            'message' => 'This MCP server is stateless: POST JSON-RPC messages to this URL. It does not stream (GET) or keep sessions (DELETE).',
        ], 405, ['Allow' => 'POST']);
    }
}
