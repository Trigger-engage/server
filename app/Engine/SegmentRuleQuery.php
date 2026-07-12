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
 * Rule shape:
 * {
 *   "match": "all" | "any",
 *   "conditions": [
 *     {"kind":"attribute","field":"plan","operator":"equals","value":"premium"},
 *     {"kind":"event","event_id":5,"performed":true,"within_days":30}
 *   ]
 * }
 */
class SegmentRuleQuery
{
    public const OPERATORS = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'contains', 'exists', 'not_exists'];

    /** @return Builder<Person> */
    public function forWorkspace(int $workspaceId, array $rules): Builder
    {
        $conditions = $rules['conditions'] ?? [];
        $match = ($rules['match'] ?? 'all') === 'any' ? 'any' : 'all';

        $query = Person::query()->where('workspace_id', $workspaceId);

        // An empty rule set matches nobody rather than the whole workspace, so a
        // misconfigured segment can never accidentally message everyone.
        if ($conditions === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $group) use ($conditions, $match): void {
            foreach (array_values($conditions) as $index => $condition) {
                $clause = fn (Builder $q) => $this->applyCondition($q, $condition);

                if ($match === 'any' && $index > 0) {
                    $group->orWhere($clause);
                } else {
                    $group->where($clause);
                }
            }
        });
    }

    protected function applyCondition(Builder $query, array $condition): void
    {
        if (($condition['kind'] ?? 'attribute') === 'event') {
            $this->applyEventCondition($query, $condition);

            return;
        }

        $this->applyAttributeCondition($query, $condition);
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

        if ($performed) {
            $query->whereHas('occurrences', $occurrences);
        } else {
            $query->whereDoesntHave('occurrences', $occurrences);
        }
    }
}
