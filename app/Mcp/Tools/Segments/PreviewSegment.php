<?php

namespace TriggerEngage\Server\Mcp\Tools\Segments;

use TriggerEngage\Server\Engine\SegmentRuleQuery;
use TriggerEngage\Server\Engine\SegmentRuleValidator;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Person;

class PreviewSegment extends Tool
{
    public function __construct(
        protected SegmentRuleQuery $ruleQuery,
        protected SegmentRuleValidator $ruleValidator,
    ) {}

    public function name(): string
    {
        return 'preview_segment';
    }

    public function title(): string
    {
        return 'Preview segment rules';
    }

    public function description(): string
    {
        return 'Count who a set of segment rules would match right now, with a sample, without saving anything. Uses '
            .'the same query the engine uses to fill rule segments, and the same validation the dashboard applies, so '
            .'an invalid rule comes back as an error saying what to fix. Rule shape: {"match":"all"|"any","conditions":[ '
            .'{"kind":"attribute","field":"plan","operator":"equals","value":"premium"}, '
            .'{"kind":"event","event_id":5,"performed":true,"within_days":30,"count_operator":"gte","count":3}, '
            .'{"kind":"segment","segment_id":9,"in":false}, {"kind":"group","match":"any","conditions":[...]} ]}. '
            .'Attribute operators: '.implode(', ', SegmentRuleQuery::OPERATORS).'. Groups nest '.SegmentRuleQuery::MAX_DEPTH.' levels deep.';
    }

    public function properties(): array
    {
        return [
            'rules' => ['type' => 'object', 'description' => 'The rule group to test.'],
            'segment_id' => ['type' => 'integer', 'description' => 'When testing new rules for an existing segment, its id, so a rule that refers to itself is caught.'],
            'sample' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'How many matches to show (default 10).'],
        ];
    }

    public function required(): array
    {
        return ['rules'];
    }

    public function rules(): array
    {
        return [
            'rules' => ['required', 'array'],
            'segment_id' => ['nullable', 'integer'],
            'sample' => ['nullable', 'integer', 'min:0', 'max:50'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspaceId = $context->workspace->id;
        $rules = $this->ruleValidator->validate($arguments['rules'], $workspaceId, $arguments['segment_id'] ?? null);
        $query = $this->ruleQuery->forWorkspace($workspaceId, $rules);
        $sample = (int) ($arguments['sample'] ?? 10);

        return [
            'rules_in_words' => $context->redactor->text((new GraphDescriber($context->workspace))->rules($rules)),
            'normalized_rules' => $rules,
            'matches' => (clone $query)->count(),
            'out_of' => Person::query()->where('workspace_id', $workspaceId)->count(),
            'sample' => $sample === 0 ? [] : $query->orderByDesc('updated_at')->limit($sample)->get()
                ->map(fn (Person $person) => $context->person($person))->values()->all(),
        ];
    }
}
