<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Arr;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;

class EventMatchEvaluator
{
    public function __construct(protected ConditionEvaluator $conditions) {}

    public function passes(AutomationRun $run, EventOccurrence $occurrence, array $rules): bool
    {
        if ($rules === []) {
            return true;
        }

        $context = [
            'incoming' => $occurrence->payload ?? [],
            'event' => $run->occurrence?->payload ?? [],
            'person' => $run->person?->toContext() ?? [],
        ];

        foreach ($rules as $rule) {
            $source = $rule['source'] ?? 'trigger_event';
            $expected = $source === 'literal'
                ? ($rule['value'] ?? null)
                : Arr::get($context, ($source === 'person' ? 'person.' : 'event.').($rule['source_field'] ?? ''));

            if (! $this->conditions->passes([
                'field' => 'incoming.'.($rule['incoming_field'] ?? ''),
                'operator' => $rule['operator'] ?? 'equals',
                'value' => $expected,
            ], $context)) {
                return false;
            }
        }

        return true;
    }
}
