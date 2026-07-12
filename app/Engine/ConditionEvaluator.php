<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Evaluates branch predicates against the run context, e.g.
 * {"field": "event.plan", "operator": "equals", "value": "free"}
 * Field paths: event.* (trigger payload), person.* (profile context).
 */
class ConditionEvaluator
{
    public function passes(array $condition, array $context): bool
    {
        $actual = Arr::get($context, $condition['field'] ?? '');
        $expected = $condition['value'] ?? null;

        return match ($condition['operator'] ?? 'equals') {
            'equals' => $actual == $expected,
            'not_equals' => $actual != $expected,
            'gt' => is_numeric($actual) && $actual > $expected,
            'gte' => is_numeric($actual) && $actual >= $expected,
            'lt' => is_numeric($actual) && $actual < $expected,
            'lte' => is_numeric($actual) && $actual <= $expected,
            'contains' => is_string($actual) && Str::contains($actual, (string) $expected),
            'exists' => ! is_null($actual),
            'not_exists' => is_null($actual),
            default => false,
        };
    }
}
