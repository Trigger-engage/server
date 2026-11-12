<?php

namespace TriggerEngage\Server\Mcp\Tools\Runs;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\AutomationRun;

class ListRuns extends Tool
{
    public const STATUSES = ['active', 'running', 'waiting', 'waiting_event', 'completed', 'cancelled', 'failed', 'step_failed', 'stalled', 'overdue'];

    public function name(): string
    {
        return 'list_runs';
    }

    public function title(): string
    {
        return 'List automation runs';
    }

    public function description(): string
    {
        return 'Runs are one person\'s trip through one automation. Filter by automation, person and status: active '
            .'(running, waiting on a delay, or waiting for an event), completed, cancelled (a goal ended it), failed, '
            .'step_failed (finished but a step failed along the way), stalled (marked running but untouched for longer '
            .'than the stalled-run threshold), or overdue (a delay that should have woken over 5 minutes ago). Newest first.';
    }

    public function properties(): array
    {
        return [
            'automation_id' => ['type' => 'integer'],
            ...$this->personProperties('Only runs for this person'),
            'status' => ['type' => 'string', 'enum' => self::STATUSES],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'Only runs started in the last N days.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'automation_id' => ['nullable', 'integer'],
            ...$this->personRules(required: false),
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $person = $this->hasPerson($arguments) ? $this->findPerson($arguments, $context) : null;
        $stalledAfter = (int) config('trigger-engage-server.stalled_runs.after_minutes', 15);

        $query = AutomationRun::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['automation_id'] ?? null, fn ($query, $automation) => $query->where('automation_id', $automation))
            ->when($person, fn ($query) => $query->where('person_id', $person->id))
            ->when($arguments['days'] ?? null, fn ($query, $days) => $query->where('created_at', '>=', now()->subDays($days)))
            ->when($arguments['status'] ?? null, fn ($query, $status) => match ($status) {
                'active' => $query->whereIn('status', AutomationRun::activeStatuses()),
                'step_failed' => $query->whereHas('steps', fn ($steps) => $steps->where('status', 'failed')),
                'stalled' => $query->where('status', AutomationRun::STATUS_RUNNING)->where('updated_at', '<', now()->subMinutes($stalledAfter)),
                'overdue' => $query->where('status', AutomationRun::STATUS_WAITING)->where('wake_at', '<', now()->subMinutes(5)),
                default => $query->where('status', $status),
            })
            ->with('automation:id,name', 'person:id,external_id,anonymous_id,email,phone', 'occurrence.event:id,name')
            ->withCount(['steps as failed_steps' => fn ($steps) => $steps->where('status', 'failed')])
            ->orderByDesc('id');

        return $this->page($query, $arguments, fn (AutomationRun $run) => [
            'id' => $run->id,
            'automation' => $run->automation?->only('id', 'name'),
            'person' => $context->person($run->person),
            'status' => $run->status,
            'current_node_id' => $run->current_node_id,
            'wake_at' => $context->time($run->wake_at),
            'trigger_event' => $run->occurrence?->event?->name,
            'failed_steps' => (int) $run->failed_steps,
            'started_at' => $context->time($run->created_at),
            'updated_at' => $context->time($run->updated_at),
        ]);
    }
}
