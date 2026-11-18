<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Engine\SegmentRuleValidator;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Segment;

class UpdateSegment extends Tool
{
    public function __construct(
        protected SegmentRuleValidator $ruleValidator,
        protected SegmentManager $segments,
    ) {}

    public function name(): string
    {
        return 'update_segment';
    }

    public function title(): string
    {
        return 'Update a segment';
    }

    public function description(): string
    {
        return 'Rename a segment, change its description, or replace a rule segment\'s rules (membership is recomputed '
            .'without recording segment_entered or segment_left events). New rules are refused for a segment that a live '
            .'journey checks or starts from, because who enters that journey would change. Create a new segment and point '
            .'a journey draft at it instead. The All people segment can\'t be changed.';
    }

    public function writes(): bool
    {
        return true;
    }

    public function annotations(): array
    {
        return [...parent::annotations(), 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function properties(): array
    {
        return [
            'segment_id' => ['type' => ['integer', 'string'], 'description' => 'Numeric id or seg_… public id.'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'rules' => ['type' => 'object', 'description' => 'New rules for a rule segment (replaces the old ones).'],
        ];
    }

    public function required(): array
    {
        return ['segment_id'];
    }

    public function rules(): array
    {
        return [
            'segment_id' => ['required'],
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:500'],
            'rules' => ['sometimes', 'array'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspace = $context->workspace;
        $segment = Segment::query()
            ->where('workspace_id', $workspace->id)
            ->where(is_numeric($arguments['segment_id']) ? 'id' : 'public_id', $arguments['segment_id'])
            ->first() ?? throw new ToolError("No segment {$arguments['segment_id']} in this workspace.");

        if ($segment->isAllPeople()) {
            throw new ToolError('The All people segment is kept by the system and can\'t be changed.');
        }

        $changes = [];
        if (array_key_exists('name', $arguments) && $arguments['name'] !== $segment->name) {
            if ($workspace->segments()->where('name', $arguments['name'])->whereKeyNot($segment->id)->exists()) {
                throw new ToolError("A segment named \"{$arguments['name']}\" already exists.");
            }
            $changes['name'] = $arguments['name'];
        }
        if (array_key_exists('description', $arguments)) {
            $changes['description'] = $arguments['description'];
        }

        $rulesChanged = array_key_exists('rules', $arguments);
        if ($rulesChanged) {
            if (! $segment->isRuleBased()) {
                throw new ToolError("Segment \"{$segment->name}\" is a {$segment->type} segment; only rule segments have rules.");
            }

            $live = (new AutomationIndex($workspace))->liveUsingSegment($segment->id);
            if ($live !== []) {
                throw new ToolError('Live journeys use segment "'.$segment->name.'": '
                    .collect($live)->map(fn ($automation) => '"'.$automation['name'].'" ('.$automation['id'].')')->implode(', ')
                    .'. New rules would change who enters them. Create a new segment and point a journey draft at it.');
            }

            // Its members feed other segments' rules; those recompute later and
            // record events as people move, which journeys can react to.
            $dependents = Segment::query()
                ->where('workspace_id', $workspace->id)
                ->where('type', Segment::TYPE_RULE)
                ->whereKeyNot($segment->id)
                ->get(['id', 'name', 'rules'])
                ->filter(fn (Segment $other) => $this->refersTo($other->rules ?? [], $segment->id));
            if ($dependents->isNotEmpty()) {
                throw new ToolError('Segment "'.$segment->name.'" is part of the rules of '
                    .$dependents->map(fn (Segment $other) => '"'.$other->name.'"')->implode(', ')
                    .'. New rules would move people in and out of those too. Create a new segment instead.');
            }

            $changes['rules'] = $this->ruleValidator->validate($arguments['rules'], $workspace->id, $segment->id);
        }

        if ($changes === []) {
            throw new ToolError('Nothing to change: give a new name, description or rules.');
        }

        $segment->update($changes);
        $members = $rulesChanged ? $this->segments->recompute($segment->refresh(), quiet: true) : $segment->people()->count();

        Audit::record($context, 'segment.updated', $segment, "Updated segment \"{$segment->name}\": ".implode(', ', array_keys($changes)).'.', ['fields' => array_keys($changes)]);

        return [
            'segment' => [
                ...$segment->only('id', 'public_id', 'name', 'type', 'description'),
                'rules_in_words' => $segment->isRuleBased() ? $context->redactor->text((new GraphDescriber($workspace))->rules($segment->rules)) : null,
            ],
            'changed' => array_keys($changes),
            'people' => $members,
            'url' => $context->url('segments.show', $segment),
        ];
    }

    /** Whether a rule group mentions the segment, however deeply nested. */
    protected function refersTo(array $group, int $segmentId): bool
    {
        foreach ($group['conditions'] ?? [] as $condition) {
            if (! is_array($condition)) {
                continue;
            }

            if (($condition['kind'] ?? null) === 'segment' && (int) ($condition['segment_id'] ?? 0) === $segmentId) {
                return true;
            }

            if (($condition['kind'] ?? null) === 'group' && $this->refersTo($condition, $segmentId)) {
                return true;
            }
        }

        return false;
    }
}
