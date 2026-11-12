<?php

namespace TriggerEngage\Server\Mcp\Tools\Segments;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class GetSegment extends Tool
{
    public function name(): string
    {
        return 'get_segment';
    }

    public function title(): string
    {
        return 'Get a segment';
    }

    public function description(): string
    {
        return 'One segment in full: its rules (as stored and in plain words), member count, a sample of members, the '
            .'broadcasts sent to it, and the automations that check it (segment steps, or segment_entered/left triggers '
            .'filtered to it). Accepts the numeric id or the seg_… public id.';
    }

    public function properties(): array
    {
        return [
            'segment_id' => ['type' => ['integer', 'string'], 'description' => 'Numeric id or seg_… public id.'],
            'sample' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 50, 'description' => 'How many members to show (default 10).'],
        ];
    }

    public function required(): array
    {
        return ['segment_id'];
    }

    public function rules(): array
    {
        return ['segment_id' => ['required'], 'sample' => ['nullable', 'integer', 'min:0', 'max:50']];
    }

    public function handle(array $arguments, Context $context): array
    {
        $segment = Segment::query()
            ->where('workspace_id', $context->workspace->id)
            ->where(is_numeric($arguments['segment_id']) ? 'id' : 'public_id', $arguments['segment_id'])
            ->with('event:id,name')
            ->first()
            ?? throw new ToolError("No segment {$arguments['segment_id']} in this workspace. Try list_segments.");

        $describer = new GraphDescriber($context->workspace);
        $sample = (int) ($arguments['sample'] ?? 10);

        return [
            'segment' => [
                ...$segment->only('id', 'public_id', 'name', 'type', 'description'),
                'event' => $segment->event?->only('id', 'name'),
                'rules' => $segment->rules,
                'rules_in_words' => $segment->isRuleBased() ? $context->redactor->text($describer->rules($segment->rules)) : null,
                'recomputed_at' => $context->time($segment->recomputed_at),
                'created_at' => $context->time($segment->created_at),
                'url' => $context->url('segments.show', $segment),
            ],
            'people' => $segment->people()->count(),
            'members_sample' => $sample === 0 ? [] : $segment->people()
                ->orderByDesc('segment_person.added_at')
                ->limit($sample)
                ->get(['people.id', 'external_id', 'anonymous_id', 'email', 'phone', 'segment_person.added_at', 'segment_person.source'])
                ->map(fn (Person $person) => [
                    ...$context->person($person),
                    'added_at' => $context->time($person->pivot->added_at ?? $person->getAttribute('added_at')),
                    'source' => $person->pivot->source ?? $person->getAttribute('source'),
                ])->values()->all(),
            'broadcasts' => $segment->broadcasts()->latest()->limit(20)->get(['id', 'name', 'status', 'channel', 'started_at'])
                ->map(fn (Broadcast $broadcast) => [
                    ...$broadcast->only('id', 'name', 'status', 'channel'),
                    'started_at' => $context->time($broadcast->started_at),
                ])->values()->all(),
            'used_by_automations' => (new AutomationIndex($context->workspace))->usingSegment($segment->id),
        ];
    }
}
