<?php

namespace TriggerEngage\Server\Mcp;

use RuntimeException;

/**
 * A problem the calling agent can fix: a person that does not exist, an
 * invalid segment rule, an argument the schema could not express. The
 * server returns it as a tool result with `isError: true`, so the model
 * reads the message and tries again, instead of a protocol error it never sees.
 */
class ToolError extends RuntimeException
{
    /** @param  array<string, array<int, string>>  $errors */
    public function __construct(string $message, protected array $errors = [])
    {
        parent::__construct($message);
    }

    /** @return array<string, array<int, string>> */
    public function errors(): array
    {
        return $this->errors;
    }
}
