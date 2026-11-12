<?php

namespace TriggerEngage\Server\Mcp;

use Composer\InstalledVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/**
 * A Model Context Protocol server over Streamable HTTP, in the stateless
 * shape the spec allows: each POST carries one JSON-RPC message (or a batch,
 * for clients older than 2025-06-18), the reply is plain JSON, and there is
 * no SSE stream or session id.
 *
 * It speaks the lifecycle (`initialize`, `ping`, `notifications/*`) and the
 * tools capability. Authentication and the workspace are settled before it
 * runs; it only ever sees the Context the route built.
 */
class Server
{
    /** Newest first. `initialize` echoes the client's version when it is one of these. */
    public const PROTOCOL_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const NAME = 'trigger-engage';

    public function __construct(protected ToolRegistry $registry) {}

    public function handle(Request $request, Context $context): JsonResponse|Response
    {
        try {
            $payload = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->respond($this->error(null, new JsonRpcError(JsonRpcError::PARSE_ERROR, 'Parse error: '.$e->getMessage())), 400);
        }

        if (! is_array($payload)) {
            return $this->respond($this->error(null, JsonRpcError::invalidRequest()), 400);
        }

        if (array_is_list($payload)) {
            if ($payload === []) {
                return $this->respond($this->error(null, JsonRpcError::invalidRequest('Empty batch')), 400);
            }

            $responses = array_values(array_filter(
                array_map(fn ($message) => $this->dispatch($message, $context), $payload),
                fn ($response) => $response !== null,
            ));

            return $responses === [] ? $this->accepted() : $this->respond($responses);
        }

        $response = $this->dispatch($payload, $context);

        return $response === null ? $this->accepted() : $this->respond($response);
    }

    /** Route one JSON-RPC message; null for notifications and client responses. */
    protected function dispatch(mixed $message, Context $context): ?array
    {
        if (! is_array($message) || array_is_list($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return $this->error(is_array($message) ? ($message['id'] ?? null) : null, JsonRpcError::invalidRequest());
        }

        // A client's reply to a server request. This server sends none.
        if (! array_key_exists('method', $message)) {
            return null;
        }

        $method = $message['method'];
        $params = $message['params'] ?? [];
        $id = $message['id'] ?? null;
        $hasId = array_key_exists('id', $message) && $id !== null;

        if (! is_string($method) || ! is_array($params)) {
            return $this->error($id, JsonRpcError::invalidRequest());
        }

        if (str_starts_with($method, 'notifications/')) {
            return null;
        }

        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'ping' => (object) [],
                'tools/list' => ['tools' => array_map(fn (Tool $tool) => $this->describe($tool), $this->registry->available($context))],
                'tools/call' => $this->callTool($params, $context),
                default => throw JsonRpcError::methodNotFound($method),
            };
        } catch (JsonRpcError $error) {
            return $hasId ? $this->error($id, $error) : null;
        } catch (Throwable $e) {
            Log::error('Trigger Engage MCP request failed', ['method' => $method, 'exception' => $e]);

            return $hasId ? $this->error($id, new JsonRpcError(JsonRpcError::INTERNAL_ERROR, 'Internal error')) : null;
        }

        return $hasId ? ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result] : null;
    }

    /** @return array<string, mixed> */
    protected function initialize(array $params): array
    {
        $requested = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($requested, self::PROTOCOL_VERSIONS, true) ? $requested : self::PROTOCOL_VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'title' => 'Trigger Engage', 'version' => self::version()],
            'instructions' => Instructions::text(),
        ];
    }

    /** @return array<string, mixed> */
    protected function describe(Tool $tool): array
    {
        return [
            'name' => $tool->name(),
            'title' => $tool->title(),
            'description' => $tool->description(),
            'inputSchema' => $tool->inputSchema(),
            'annotations' => ['title' => $tool->title(), ...$tool->annotations()],
        ];
    }

    /** @return array<string, mixed> */
    protected function callTool(array $params, Context $context): array
    {
        $name = $params['name'] ?? null;
        $arguments = $params['arguments'] ?? [];

        if (! is_string($name)) {
            throw JsonRpcError::invalidParams('tools/call needs a tool name.');
        }

        $tool = $this->registry->find($name, $context);

        if (! $tool) {
            throw JsonRpcError::invalidParams("Unknown tool: {$name}", [
                'available' => array_map(fn (Tool $tool) => $tool->name(), $this->registry->available($context)),
            ]);
        }

        if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
            return $this->toolError('arguments must be a JSON object.');
        }

        $unknown = array_diff(array_keys($arguments), array_keys($tool->properties()));
        if ($unknown !== []) {
            return $this->toolError("{$name} does not take: ".implode(', ', $unknown).'. It takes: '.(implode(', ', array_keys($tool->properties())) ?: 'no arguments').'.');
        }

        try {
            $arguments = Validator::make($arguments, $tool->rules())->validate();
            $structured = $tool->handle($arguments, $context);
        } catch (ValidationException $e) {
            return $this->toolError('Invalid arguments for '.$name.': '.collect($e->errors())->flatten()->implode(' '), $e->errors());
        } catch (ToolError $e) {
            return $this->toolError($e->getMessage(), $e->errors());
        } catch (JsonRpcError $e) {
            throw $e;
        } catch (Throwable $e) {
            // The exception goes to the log, not the caller: a database
            // error carries SQL and schema detail no remote client needs.
            Log::warning('Trigger Engage MCP tool failed', ['tool' => $name, 'arguments' => $arguments, 'exception' => $e]);

            return $this->toolError("{$name} failed on the server. The error has been logged.");
        }

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($structured, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]],
            'structuredContent' => (object) $structured,
            'isError' => false,
        ];
    }

    /** @return array<string, mixed> */
    protected function toolError(string $message, array $errors = []): array
    {
        $text = $errors === [] ? $message : $message."\n".json_encode(['errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => true,
        ];
    }

    /** @return array<string, mixed> */
    protected function error(mixed $id, JsonRpcError $error): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error->toArray()];
    }

    protected function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    protected function accepted(): Response
    {
        return response()->noContent(202);
    }

    /** The installed package version, for serverInfo. */
    public static function version(): string
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('trigger-engage/server')) {
            return (string) (InstalledVersions::getPrettyVersion('trigger-engage/server') ?? 'dev');
        }

        return 'dev';
    }
}
