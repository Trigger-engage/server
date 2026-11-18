<?php

namespace TriggerEngage\Server\Mcp\Tools\Automations;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;

class ListAutomations extends Tool
{
    public function name(): string
    {
        return 'list_automations';
    }

    public function title(): string
    {
        return 'List automations';
    }

    public function description(): string
    {
        return 'The automations (journeys): status (draft = never published, active = starting runs, paused = starting '
            .'none), the event that starts them, the re-entry policy (every_time, one_active_run_per_person, '
            .'once_ever_per_person), when the live version was published, and how many runs are active, completed and '
            .'failed. get_automation shows the steps.';
    }

    public function properties(): array
    {
        return [
            'status' => ['type' => 'string', 'enum' => ['draft', 'active', 'paused']],
            'search' => ['type' => 'string', 'description' => 'Only automations whose name contains this text.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'in:draft,active,paused'],
            'search' => ['nullable', 'string', 'max:150'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $query = Automation::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($arguments['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->with('triggerEvent:id,name', 'activeVersion:id,automation_id,published_at')
            ->withCount([
                'versions',
                'runs as active_runs' => fn ($runs) => $runs->whereIn('status', AutomationRun::activeStatuses()),
                'runs as completed_runs' => fn ($runs) => $runs->where('status', AutomationRun::STATUS_COMPLETED),
                'runs as failed_runs' => fn ($runs) => $runs->where('status', AutomationRun::STATUS_FAILED),
                'runs as cancelled_runs' => fn ($runs) => $runs->where('status', AutomationRun::STATUS_CANCELLED),
            ])
            ->withMax('runs', 'created_at')
            ->orderBy('name');

        return $this->page($query, $arguments, fn (Automation $automation) => [
            ...$automation->only('id', 'name', 'status', 'reentry_policy'),
            'trigger_event' => $automation->triggerEvent?->only('id', 'name'),
            'versions' => (int) $automation->versions_count,
            'published_at' => $context->time($automation->activeVersion?->published_at),
            'runs' => [
                'active' => (int) $automation->active_runs,
                'completed' => (int) $automation->completed_runs,
                'failed' => (int) $automation->failed_runs,
                'cancelled' => (int) $automation->cancelled_runs,
            ],
            'last_run_started_at' => $context->time($automation->runs_max_created_at),
            'url' => $context->url('automations.edit', $automation),
        ]);
    }
}
