<?php

namespace TriggerEngage\Server\Mcp;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Models\Person;

/**
 * One MCP tool. Most only read the workspace in their Context. A tool that
 * writes says so with writes(); the server then lists it only to callers the
 * host lets write, and its annotations tell clients to ask before calling it.
 * Writing tools make drafts: nothing they do reaches a person.
 */
abstract class Tool
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    /** The name agents call, e.g. `get_person`. */
    abstract public function name(): string;

    /** A short human title. */
    abstract public function title(): string;

    /** What the tool returns and when to reach for it, written for the model choosing tools. */
    abstract public function description(): string;

    /**
     * The tool's arguments as JSON Schema properties. The server wraps them
     * in an object schema, so an empty array means "no arguments".
     *
     * @return array<string, array<string, mixed>>
     */
    public function properties(): array
    {
        return [];
    }

    /** @return array<int, string> */
    public function required(): array
    {
        return [];
    }

    /**
     * Laravel validation rules for the arguments, mirroring properties().
     * A failure comes back to the agent as a tool error it can correct.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Run the tool and return JSON-serialisable structured content. Throw a
     * ToolError for anything the agent can fix.
     *
     * @return array<string, mixed>
     */
    abstract public function handle(array $arguments, Context $context): array;

    /** Whether this tool changes anything. Read-only unless a tool says otherwise. */
    public function writes(): bool
    {
        return false;
    }

    /**
     * MCP tool annotations. A writing tool is not read-only; one that
     * overwrites existing content is destructive; a create is not idempotent.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint' => ! $this->writes(),
            'destructiveHint' => false,
            'idempotentHint' => ! $this->writes(),
            'openWorldHint' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function inputSchema(): array
    {
        $schema = [
            'type' => 'object',
            'properties' => (object) $this->properties(),
            'additionalProperties' => false,
        ];

        if ($this->required() !== []) {
            $schema['required'] = $this->required();
        }

        return $schema;
    }

    /** @return array<string, array<string, mixed>> */
    protected function paginationProperties(): array
    {
        return [
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_LIMIT, 'description' => 'Rows per page (default '.self::DEFAULT_LIMIT.').'],
            'page' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Page number, starting at 1. Use next_page from the previous result.'],
        ];
    }

    /** @return array<string, mixed> */
    protected function paginationRules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * One page of a query without counting the whole table: fetch a row more
     * than asked for to know whether another page exists.
     *
     * @param  callable(Model): array<string, mixed>  $map
     * @return array{items: array<int, array<string, mixed>>, page: int, limit: int, has_more: bool, next_page: int|null}
     */
    protected function page(Builder $query, array $arguments, callable $map): array
    {
        $limit = (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT);
        $page = (int) ($arguments['page'] ?? 1);

        $rows = $query->offset(($page - 1) * $limit)->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return [
            'items' => $rows->take($limit)->map($map)->values()->all(),
            'page' => $page,
            'limit' => $limit,
            'has_more' => $hasMore,
            'next_page' => $hasMore ? $page + 1 : null,
        ];
    }

    /**
     * The three ways a tool accepts a person. Exactly one is needed.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function personProperties(string $purpose = 'The person'): array
    {
        return [
            'external_id' => ['type' => 'string', 'description' => $purpose.', by the id the host app identifies them with (e.g. "user-123"). Preferred.'],
            'person_id' => ['type' => 'integer', 'description' => $purpose.', by Trigger Engage\'s numeric id (the `id` other tools return).'],
            'email' => ['type' => 'string', 'description' => $purpose.', by exact email address.'],
        ];
    }

    /** @return array<string, mixed> */
    protected function personRules(bool $required = true): array
    {
        return [
            'external_id' => ['nullable', 'string', 'max:191', ...($required ? ['required_without_all:person_id,email'] : [])],
            'person_id' => ['nullable', 'integer', ...($required ? ['required_without_all:external_id,email'] : [])],
            'email' => ['nullable', 'string', 'max:255', ...($required ? ['required_without_all:external_id,person_id'] : [])],
        ];
    }

    protected function hasPerson(array $arguments): bool
    {
        return filled($arguments['external_id'] ?? null) || filled($arguments['person_id'] ?? null) || filled($arguments['email'] ?? null);
    }

    protected function findPerson(array $arguments, Context $context): Person
    {
        $query = Person::query()->where('workspace_id', $context->workspace->id);

        [$field, $value] = match (true) {
            filled($arguments['person_id'] ?? null) => ['id', (int) $arguments['person_id']],
            filled($arguments['external_id'] ?? null) => ['external_id', (string) $arguments['external_id']],
            filled($arguments['email'] ?? null) => ['email', (string) $arguments['email']],
            default => throw new ToolError('Name a person with external_id, person_id or email.'),
        };

        $matches = $query->where($field, $value)->orderByDesc('updated_at')->limit(2)->get();

        if ($matches->isEmpty()) {
            throw new ToolError("No person in this workspace has {$field} \"{$value}\". Try search_people to find them.");
        }

        if ($matches->count() > 1) {
            throw new ToolError("More than one person has {$field} \"{$value}\". Use external_id or person_id instead.");
        }

        return $matches->first();
    }

    /**
     * A record that must exist in this workspace.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @return TModel
     */
    protected function findOwned(string $model, mixed $id, Context $context, string $label): Model
    {
        $record = $model::query()->where('workspace_id', $context->workspace->id)->find($id);

        if (! $record) {
            throw new ToolError("No {$label} with id {$id} in this workspace.");
        }

        return $record;
    }
}
