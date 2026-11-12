<?php

namespace TriggerEngage\Server\Mcp\Tools\Segments;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Segment;

class ListSegments extends Tool
{
    public function name(): string
    {
        return 'list_segments';
    }

    public function title(): string
    {
        return 'List segments';
    }

    public function description(): string
    {
        return 'The workspace\'s segments with their type (all = every profile; manual = hand-picked; event = everyone '
            .'who did an event; rule = computed from attribute, event and segment rules), member count, and for rule '
            .'segments the rules in plain words and when membership was last recomputed.';
    }

    public function properties(): array
    {
        return [
            'search' => ['type' => 'string', 'description' => 'Only segments whose name contains this text.'],
            'type' => ['type' => 'string', 'enum' => [Segment::TYPE_ALL, Segment::TYPE_MANUAL, Segment::TYPE_EVENT, Segment::TYPE_RULE]],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:150'],
            'type' => ['nullable', 'in:all,manual,event,rule'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $describer = new GraphDescriber($context->workspace);
        $query = Segment::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->when($arguments['type'] ?? null, fn ($query, $type) => $query->where('type', $type))
            ->with('event:id,name')
            ->withCount('people')
            ->orderByRaw('case when type = ? then 0 else 1 end', [Segment::TYPE_ALL])
            ->orderBy('name');

        return $this->page($query, $arguments, fn (Segment $segment) => [
            'id' => $segment->id,
            'public_id' => $segment->public_id,
            'name' => $segment->name,
            'type' => $segment->type,
            'description' => $segment->description,
            'people' => (int) $segment->people_count,
            'event' => $segment->event?->only('id', 'name'),
            'rules' => $segment->isRuleBased() ? $context->redactor->text($describer->rules($segment->rules)) : null,
            'recomputed_at' => $context->time($segment->recomputed_at),
        ]);
    }
}
