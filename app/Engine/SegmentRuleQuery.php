<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;
use TriggerEngage\Server\Models\Person;

/**
 * Translates a segment rule group into a People query. Both the full segment
 * recompute and the single-person membership check run through here, so the two
 * can never disagree about who belongs.
 *
 * Rule shape (groups nest up to MAX_DEPTH levels):
 * {
 *   "match": "all" | "any",
 *   "conditions": [
 *     {"kind":"attribute","field":"plan","operator":"equals","value":"premium"},
 *     {"kind":"event","event_id":5,"performed":true,"within_days":30,
 *      "count_operator":"gte","count":3},
 *     {"kind":"segment","segment_id":9,"in":false},
 *     {"kind":"group","match":"any","conditions":[...]}
 *   ]
 * }
 *
 * Segment conditions read the materialized segment_person rows, never the
 * referenced segment's rule, so evaluation always terminates even when rule
 * segments reference each other. Chains converge across recompute passes.
 */
class SegmentRuleQuery
{
    public const OPERATORS = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists', 'not_exists'];

    public const COUNT_OPERATORS = ['gte', 'lte', 'eq'];

    public const MAX_DEPTH = 3;

    /** @return Builder<Person> */
    public function forWorkspace(int $workspaceId, array $rules): Builder
    {
        $query = Person::query()->where('workspace_id', $workspaceId);

        // An empty rule set matches nobody rather than the whole workspace, so a
        // misconfigured segment can never accidentally message everyone.
        if (($rules['conditions'] ?? []) === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(fn (Builder $group) => $this->applyGroup($group, $rules));
    }

    protected function applyGroup(Builder $query, array $group): void
    {
        $conditions = array_values($group['conditions'] ?? []);
        $match = ($group['match'] ?? 'all') === 'any' ? 'any' : 'all';

        if ($conditions === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        foreach ($conditions as $index => $condition) {
            $clause = fn (Builder $q) => $this->applyCondition($q, $condition);

            if ($match === 'any' && $index > 0) {
                $query->orWhere($clause);
            } else {
                $query->where($clause);
            }
        }
    }

    protected function applyCondition(Builder $query, array $condition): void
    {
        match ($condition['kind'] ?? 'attribute') {
            'group' => $this->applyGroup($query, $condition),
            'event' => $this->applyEventCondition($query, $condition),
            'segment' => $this->applySegmentCondition($query, $condition),
            default => $this->applyAttributeCondition($query, $condition),
        };
    }

    protected function applyAttributeCondition(Builder $query, array $condition): void
    {
        $field = (string) ($condition['field'] ?? '');
        $column = $this->attributeColumn($field);
        $value = $condition['value'] ?? null;

        match ($condition['operator'] ?? 'equals') {
            'not_equals' => $query->where(fn (BuilderContract $w) => $w->where($column, '!=', $value)->orWhereNull($column)),
            'gt' => $query->where($column, '>', $value),
            'gte' => $query->where($column, '>=', $value),
            'lt' => $query->where($column, '<', $value),
            'lte' => $query->where($column, '<=', $value),
            'contains' => $query->where($column, 'like', '%'.$value.'%'),
            'exists' => $query->whereNotNull($column),
            'not_exists' => $query->whereNull($column),
            default => $query->where($column, '=', $value),
        };
    }

    /**
     * Identity columns (email, phone, external_id) are real columns; anything
     * else is a key inside the attributes JSON blob.
     */
    protected function attributeColumn(string $field): string
    {
        return in_array($field, ['email', 'phone', 'external_id'], true)
            ? $field
            : 'attributes->'.$field;
    }

    protected function applyEventCondition(Builder $query, array $condition): void
    {
        $eventId = $condition['event_id'] ?? null;
        $withinDays = (int) ($condition['within_days'] ?? 0);
        $performed = ($condition['performed'] ?? true) !== false;

        $occurrences = function (BuilderContract $q) use ($eventId, $withinDays): void {
            $q->where('event_id', $eventId);

            if ($withinDays > 0) {
                $q->where('occurred_at', '>=', now()->subDays($withinDays));
            }
        };

        if (! $performed) {
            $query->whereDoesntHave('occurrences', $occurrences);

            return;
        }

        [$operator, $count] = $this->countComparison($condition);

        $query->whereHas('occurrences', $occurrences, $operator, $count);
    }

    /**
     * "Performed" defaults to at-least-once; count_operator + count narrow it
     * to e.g. at least 3 times, at most 2 times, or exactly once.
     *
     * @return array{string, int}
     */
    protected function countComparison(array $condition): array
    {
        $count = max(1, (int) ($condition['count'] ?? 1));

        return match ($condition['count_operator'] ?? 'gte') {
            'lte' => ['<=', $count],
            'eq' => ['=', $count],
            default => ['>=', $count],
        };
    }

    protected function applySegmentCondition(Builder $query, array $condition): void
    {
        $segmentId = $condition['segment_id'] ?? null;
        $membership = fn (BuilderContract $q) => $q->where('segments.id', $segmentId);

        if (($condition['in'] ?? true) !== false) {
            $query->whereHas('segments', $membership);
        } else {
            $query->whereDoesntHave('segments', $membership);
        }
    }
}
