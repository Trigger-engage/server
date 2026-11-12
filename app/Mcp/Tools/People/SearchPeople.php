<?php

namespace TriggerEngage\Server\Mcp\Tools\People;

use TriggerEngage\Server\Engine\SegmentRuleQuery;
use TriggerEngage\Server\Engine\SegmentRuleValidator;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Person;

class SearchPeople extends Tool
{
    public function __construct(
        protected SegmentRuleQuery $ruleQuery,
        protected SegmentRuleValidator $ruleValidator,
    ) {}

    public function name(): string
    {
        return 'search_people';
    }

    public function title(): string
    {
        return 'Search people';
    }

    public function description(): string
    {
        return 'Find people by text (external id, anonymous id, email or phone contains it), by segment membership, or '
            .'by segment-style rules, e.g. {"match":"all","conditions":[{"kind":"attribute","field":"plan","operator":'
            .'"equals","value":"premium"},{"kind":"event","event_id":5,"performed":true,"within_days":30}]}. Filters '
            .'combine. Most recently updated first. Use get_person for one person\'s full profile and history.';
    }

    public function properties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Text to look for in external id, anonymous id, email or phone.'],
            'segment_id' => ['type' => 'integer', 'description' => 'Only members of this segment.'],
            'rules' => [
                'type' => 'object',
                'description' => 'A segment rule group: {"match":"all"|"any","conditions":[...]}. Condition kinds: attribute '
                    .'(field, operator: '.implode('|', SegmentRuleQuery::OPERATORS).', value), event (event_id, performed, '
                    .'within_days, count_operator: gte|lte|eq, count), segment (segment_id, in), group (match, conditions).',
            ],
            'include_attributes' => ['type' => 'boolean', 'description' => 'Include each person\'s attributes (default false).'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'query' => ['nullable', 'string', 'max:191'],
            'segment_id' => ['nullable', 'integer'],
            'rules' => ['nullable', 'array'],
            'include_attributes' => ['nullable', 'boolean'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspaceId = $context->workspace->id;

        $query = filled($arguments['rules'] ?? null)
            ? $this->ruleQuery->forWorkspace($workspaceId, $this->ruleValidator->validate($arguments['rules'], $workspaceId))
            : Person::query()->where('workspace_id', $workspaceId);

        $query
            ->when($arguments['segment_id'] ?? null, fn ($query, $segment) => $query->whereHas('segments', fn ($segments) => $segments->where('segments.id', $segment)))
            ->when(filled($arguments['query'] ?? null), function ($query) use ($arguments) {
                $term = '%'.addcslashes((string) $arguments['query'], '%_\\').'%';
                $query->where(fn ($nested) => $nested
                    ->where('external_id', 'like', $term)
                    ->orWhere('anonymous_id', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term));
            })
            ->orderByDesc('updated_at')->orderByDesc('id');

        $withAttributes = (bool) ($arguments['include_attributes'] ?? false);

        return $this->page($query, $arguments, function (Person $person) use ($context, $withAttributes): array {
            $row = [
                ...$context->person($person),
                'unsubscribed_from_everything' => $person->unsubscribed_at !== null,
                'created_at' => $context->time($person->created_at),
                'updated_at' => $context->time($person->updated_at),
            ];

            if ($withAttributes) {
                $row['attributes'] = $context->redactor->data($person->properties());
            }

            return $row;
        });
    }
}
