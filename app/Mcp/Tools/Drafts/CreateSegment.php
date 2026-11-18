<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use Illuminate\Validation\Rule;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Engine\SegmentRuleQuery;
use TriggerEngage\Server\Engine\SegmentRuleValidator;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Segment;

class CreateSegment extends Tool
{
    public function __construct(
        protected SegmentRuleValidator $ruleValidator,
        protected SegmentManager $segments,
    ) {}

    public function name(): string
    {
        return 'create_segment';
    }

    public function title(): string
    {
        return 'Create a segment';
    }

    public function description(): string
    {
        return 'Create an audience. A rule segment is filled straight away from its rules (test them first with '
            .'preview_segment) and then kept up to date by the engine. A manual segment starts empty for the team to fill '
            .'in the dashboard. Filling it records no segment_entered events, so creating a segment starts no journey. '
            .'Rule shape: {"match":"all"|"any","conditions":[...]} with attribute, event, segment and group conditions '
            .'(attribute operators: '.implode(', ', SegmentRuleQuery::OPERATORS).').';
    }

    public function writes(): bool
    {
        return true;
    }

    public function properties(): array
    {
        return [
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'type' => ['type' => 'string', 'enum' => [Segment::TYPE_RULE, Segment::TYPE_MANUAL], 'description' => 'Default rule.'],
            'rules' => ['type' => 'object', 'description' => 'Required for a rule segment.'],
        ];
    }

    public function required(): array
    {
        return ['name'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'type' => ['nullable', Rule::in([Segment::TYPE_RULE, Segment::TYPE_MANUAL])],
            'rules' => ['nullable', 'array'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspace = $context->workspace;
        $type = $arguments['type'] ?? Segment::TYPE_RULE;

        if ($workspace->segments()->where('name', $arguments['name'])->exists()) {
            throw new ToolError("A segment named \"{$arguments['name']}\" already exists. Pick another name, or update_segment.");
        }

        if ($type === Segment::TYPE_RULE && blank($arguments['rules'] ?? null)) {
            throw new ToolError('A rule segment needs rules. Try them with preview_segment first.');
        }

        $rules = $type === Segment::TYPE_RULE ? $this->ruleValidator->validate($arguments['rules'], $workspace->id) : null;

        $segment = $workspace->segments()->create([
            'name' => $arguments['name'],
            'description' => $arguments['description'] ?? null,
            'type' => $type,
            'rules' => $rules,
        ]);

        $members = $segment->isRuleBased() ? $this->segments->recompute($segment, quiet: true) : 0;
        $describer = new GraphDescriber($workspace);

        Audit::record($context, 'segment.created', $segment, "Created {$type} segment \"{$segment->name}\" with {$members} people.", ['rules' => $rules]);

        return [
            'segment' => [
                ...$segment->only('id', 'public_id', 'name', 'type', 'description'),
                'rules_in_words' => $rules ? $context->redactor->text($describer->rules($rules)) : null,
            ],
            'people' => $members,
            'url' => $context->url('segments.show', $segment),
        ];
    }
}
