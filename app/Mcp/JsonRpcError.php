<?php

namespace TriggerEngage\Server\Mcp;

use RuntimeException;

/**
 * A JSON-RPC 2.0 error the MCP server reports as `error`, not as a tool
 * result: malformed requests, unknown methods and unknown tools.
 */
class JsonRpcError extends RuntimeException
{
    public const PARSE_ERROR = -32700;

    public const INVALID_REQUEST = -32600;

    public const METHOD_NOT_FOUND = -32601;

    public const INVALID_PARAMS = -32602;

    public const INTERNAL_ERROR = -32603;

    public function __construct(int $code, string $message, protected mixed $data = null)
    {
        parent::__construct($message, $code);
    }

    public static function invalidRequest(string $message = 'Invalid Request'): self
    {
        return new self(self::INVALID_REQUEST, $message);
    }

    public static function methodNotFound(string $method): self
    {
        return new self(self::METHOD_NOT_FOUND, "Method not found: {$method}");
    }

    public static function invalidParams(string $message, mixed $data = null): self
    {
        return new self(self::INVALID_PARAMS, $message, $data);
    }

    /** @return array{code: int, message: string, data?: mixed} */
    public function toArray(): array
    {
        $error = ['code' => $this->getCode(), 'message' => $this->getMessage()];

        if ($this->data !== null) {
            $error['data'] = $this->data;
        }

        return $error;
    }
}
