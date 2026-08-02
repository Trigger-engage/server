<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Segment;

/**
 * Validates and normalizes a segment rule group (including nested groups) into
 * the stored shape. Store, update, and audience preview all run through here so
 * the builder can never save a rule the query compiler would misread.
 */
class SegmentRuleValidator
{
    public const MAX_CONDITIONS_PER_GROUP = 20;

    /**
     * @return array{match: string, conditions: array<int, array<string, mixed>>}
     *
     * @throws ValidationException
     */
    public function validate(array $rules, int $workspaceId, ?int $selfSegmentId = null): array
    {
        $normalized = $this->normalizeGroup($rules, $workspaceId, $selfSegmentId, 1);

        if ($normalized['conditions'] === []) {
            $this->fail('Add at least one condition.');
        }

        return $normalized;
    }

    protected function normalizeGroup(array $group, int $workspaceId, ?int $selfSegmentId, int $depth): array
    {
        if ($depth > SegmentRuleQuery::MAX_DEPTH) {
            $this->fail(sprintf('Condition groups can nest at most %d levels deep.', SegmentRuleQuery::MAX_DEPTH));
        }

        $match = $group['match'] ?? 'all';

        if (! in_array($match, ['all', 'any'], true)) {
            $this->fail('Each group must match "all" or "any" of its conditions.');
        }

        $conditions = $group['conditions'] ?? [];

        if (! is_array($conditions)) {
            $this->fail('Conditions must be a list.');
        }

        if (count($conditions) > self::MAX_CONDITIONS_PER_GROUP) {
            $this->fail(sprintf('A group can hold at most %d conditions.', self::MAX_CONDITIONS_PER_GROUP));
        }

        return [
            'match' => $match,
            'conditions' => collect($conditions)
                ->values()
                ->map(fn ($condition) => $this->normalizeCondition(
                    is_array($condition) ? $condition : [],
                    $workspaceId,
                    $selfSegmentId,
                    $depth
                ))
                ->all(),
        ];
    }

    protected function normalizeCondition(array $condition, int $workspaceId, ?int $selfSegmentId, int $depth): array
    {
        return match ($condition['kind'] ?? 'attribute') {
            'group' => [
                'kind' => 'group',
                ...$this->requireNonEmptyGroup(
                    $this->normalizeGroup($condition, $workspaceId, $selfSegmentId, $depth + 1)
                ),
            ],
            'event' => $this->normalizeEventCondition($condition, $workspaceId),
            'segment' => $this->normalizeSegmentCondition($condition, $workspaceId, $selfSegmentId),
            default => $this->normalizeAttributeCondition($condition),
        };
    }

    protected function requireNonEmptyGroup(array $group): array
    {
        if ($group['conditions'] === []) {
            $this->fail('Nested groups need at least one condition.');
        }

        return $group;
    }

    protected function normalizeAttributeCondition(array $condition): array
    {
        $field = trim((string) ($condition['field'] ?? ''));

        if ($field === '' || strlen($field) > 150) {
            $this->fail('Attribute conditions need a field name (max 150 characters).');
        }

        $operator = $condition['operator'] ?? 'equals';

        if (! in_array($operator, SegmentRuleQuery::OPERATORS, true)) {
            $this->fail(sprintf('Unknown attribute operator [%s].', $operator));
        }

        $needsValue = ! in_array($operator, ['exists', 'not_exists'], true);
        $value = $condition['value'] ?? null;

        if ($needsValue && ($value === null || $value === '')) {
            $this->fail('Attribute conditions need a value unless the operator is exists / not exists.');
        }

        return [
            'kind' => 'attribute',
            'field' => $field,
            'operator' => $operator,
            'value' => $needsValue ? $value : null,
        ];
    }

    protected function normalizeEventCondition(array $condition, int $workspaceId): array
    {
        $eventId = (int) ($condition['event_id'] ?? 0);

        if (! Event::query()->where('workspace_id', $workspaceId)->whereKey($eventId)->exists()) {
            $this->fail('Behaviour conditions must reference an event from this workspace.');
        }

        $withinDays = (int) ($condition['within_days'] ?? 0);

        if ($withinDays < 0 || $withinDays > 3650) {
            $this->fail('Behaviour windows must be between 0 (ever) and 3650 days.');
        }

        $performed = (bool) ($condition['performed'] ?? true);
        $countOperator = $condition['count_operator'] ?? 'gte';

        if (! in_array($countOperator, SegmentRuleQuery::COUNT_OPERATORS, true)) {
            $this->fail(sprintf('Unknown count comparison [%s].', $countOperator));
        }

        $count = (int) ($condition['count'] ?? 1);

        if ($count < 1 || $count > 10000) {
            $this->fail('Behaviour counts must be between 1 and 10000.');
        }

        return [
            'kind' => 'event',
            'event_id' => $eventId,
            'performed' => $performed,
            'within_days' => $withinDays,
            // Counts only narrow "performed"; "did not perform" is count-free.
            'count_operator' => $performed ? $countOperator : 'gte',
            'count' => $performed ? $count : 1,
        ];
    }

    protected function normalizeSegmentCondition(array $condition, int $workspaceId, ?int $selfSegmentId): array
    {
        $segmentId = (int) ($condition['segment_id'] ?? 0);

        if (! Segment::query()->where('workspace_id', $workspaceId)->whereKey($segmentId)->exists()) {
            $this->fail('Segment conditions must reference a segment from this workspace.');
        }

        if ($selfSegmentId !== null && $segmentId === $selfSegmentId) {
            $this->fail('A segment cannot reference its own membership.');
        }

        return [
            'kind' => 'segment',
            'segment_id' => $segmentId,
            'in' => ($condition['in'] ?? true) !== false,
        ];
    }

    protected function fail(string $message): never
    {
        throw ValidationException::withMessages(['rules' => $message]);
    }
}
