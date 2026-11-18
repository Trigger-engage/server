<?php

namespace TriggerEngage\Server\Mcp\Support;

use TriggerEngage\Server\Mcp\Redactor;

/**
 * Which keys a set of JSON objects (profile attributes, event payloads)
 * carry, with their types and an example, so an agent can write segment
 * rules and Liquid against real field names instead of guessing them.
 * Nested objects are followed two levels deep as dot paths, the same
 * paths Liquid and segment rules use.
 */
class KeySampler
{
    protected const MAX_DEPTH = 2;

    /** @var array<string, array{types: array<string, true>, seen: int, example: mixed}> */
    protected array $keys = [];

    protected int $objects = 0;

    public function __construct(protected Redactor $redactor) {}

    public function add(?array $object): void
    {
        $this->objects++;
        $this->walk($object ?? [], '', 1);
    }

    /** @return array<int, array{key: string, types: array<int, string>, seen_in: int, fill_rate: float, example: mixed}> */
    public function keys(): array
    {
        return collect($this->keys)
            ->map(fn (array $entry, string $key) => [
                'key' => $key,
                'types' => array_keys($entry['types']),
                'seen_in' => $entry['seen'],
                'fill_rate' => $this->objects > 0 ? round($entry['seen'] / $this->objects * 100, 1) : 0.0,
                'example' => $entry['example'],
            ])
            ->sortBy([['seen_in', 'desc'], ['key', 'asc']])
            ->values()->all();
    }

    public function count(): int
    {
        return $this->objects;
    }

    protected function walk(array $object, string $prefix, int $depth): void
    {
        foreach ($object as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $path = $prefix.$key;

            if (is_array($value) && ! array_is_list($value) && $value !== [] && $depth < self::MAX_DEPTH) {
                $this->walk($value, $path.'.', $depth + 1);

                continue;
            }

            $entry = $this->keys[$path] ?? ['types' => [], 'seen' => 0, 'example' => null];
            $entry['types'][$this->type($value)] = true;
            $entry['seen']++;

            if ($entry['example'] === null && $value !== null && $value !== '') {
                $entry['example'] = $this->example($value, $key);
            }

            $this->keys[$path] = $entry;
        }
    }

    protected function type(mixed $value): string
    {
        return match (true) {
            is_null($value) => 'null',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_array($value) => array_is_list($value) ? 'list' : 'object',
            default => 'string',
        };
    }

    protected function example(mixed $value, string $key): mixed
    {
        $value = $this->redactor->data($value, $key);

        if (is_array($value)) {
            return mb_strimwidth(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '', 0, 80, '…');
        }

        return is_string($value) ? mb_strimwidth($value, 0, 80, '…') : $value;
    }
}
