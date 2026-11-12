<?php

namespace TriggerEngage\Server\Mcp\Tools\Automations;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Engine\EditorFidelity;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Redactor;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationVersion;

class GetAutomation extends Tool
{
    protected Redactor $redactor;

    public function name(): string
    {
        return 'get_automation';
    }

    public function title(): string
    {
        return 'Get an automation';
    }

    public function description(): string
    {
        return 'One automation\'s journey, step by step in plain words in the order a person moves through it: the '
            .'trigger and its filters, each delay, branch, wait, split and send (with template and channel names), the '
            .'goals that end it early, and for each step how many runs on that version completed, skipped or failed '
            .'it (the most common skip and failure reasons included). Also lists its versions and any shapes the '
            .'visual editor cannot display. Defaults to the live version.';
    }

    public function properties(): array
    {
        return [
            'automation_id' => ['type' => 'integer'],
            'version_id' => ['type' => 'integer', 'description' => 'An older published version to read instead of the live one.'],
            'include_graph' => ['type' => 'boolean', 'description' => 'Also return the raw graph JSON (default false).'],
        ];
    }

    public function required(): array
    {
        return ['automation_id'];
    }

    public function rules(): array
    {
        return [
            'automation_id' => ['required', 'integer'],
            'version_id' => ['nullable', 'integer'],
            'include_graph' => ['nullable', 'boolean'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Automation $automation */
        $automation = $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation');
        $automation->load('triggerEvent:id,name');

        $version = filled($arguments['version_id'] ?? null)
            ? $automation->versions()->find($arguments['version_id']) ?? throw new ToolError("Automation {$automation->id} has no version {$arguments['version_id']}.")
            : $automation->activeVersion;

        $graph = $version?->graph ?? [];
        $described = (new GraphDescriber($context->workspace))->describe($graph);
        $this->redactor = $context->redactor;
        $stats = $version ? $this->stepStats($version) : collect();

        $result = [
            'automation' => [
                ...$automation->only('id', 'name', 'status', 'reentry_policy'),
                'trigger_event' => $automation->triggerEvent?->only('id', 'name'),
                'url' => $context->url('automations.edit', $automation),
            ],
            'version' => $version ? [
                'id' => $version->id,
                'live' => $version->id === $automation->active_version_id,
                'published_at' => $context->time($version->published_at),
            ] : null,
            'note' => $version ? null : 'This automation has never been published, so it has no steps the engine runs yet.',
            'steps' => collect($described['steps'])->map(fn (array $step) => [
                ...$step,
                'summary' => $context->redactor->text($step['summary']),
                'outcomes' => $stats->get($step['id']),
            ])->all(),
            'goals' => $described['goals'],
            'runs_on_this_version' => $version ? $this->runCounts($version) : null,
            'versions' => $this->versions($automation, $context),
            'editor_issues' => EditorFidelity::issues($graph),
        ];

        if ($arguments['include_graph'] ?? false) {
            $result['graph'] = $graph;
        }

        return $result;
    }

    /**
     * Step outcomes for runs pinned to this version: counts per status and
     * the commonest reasons a step was skipped or failed.
     *
     * @return Collection<string, array<string, mixed>>
     */
    protected function stepStats(AutomationVersion $version)
    {
        $rows = DB::table('run_steps')
            ->join('automation_runs', 'automation_runs.id', '=', 'run_steps.automation_run_id')
            ->where('automation_runs.automation_version_id', $version->id)
            ->selectRaw('run_steps.node_id, run_steps.status, count(*) as total')
            ->groupBy('run_steps.node_id', 'run_steps.status')
            ->get();

        $reasons = DB::table('run_steps')
            ->join('automation_runs', 'automation_runs.id', '=', 'run_steps.automation_run_id')
            ->where('automation_runs.automation_version_id', $version->id)
            ->whereIn('run_steps.status', ['skipped', 'failed'])
            ->orderByDesc('run_steps.id')
            ->limit(500)
            ->get(['run_steps.node_id', 'run_steps.status', 'run_steps.output', 'run_steps.error'])
            ->groupBy('node_id');

        return $rows->groupBy('node_id')->map(function ($group, $nodeId) use ($reasons) {
            $counts = $group->mapWithKeys(fn ($row) => [$row->status => (int) $row->total])->all();
            $top = collect($reasons->get($nodeId, []))
                ->map(fn ($row) => $row->status.': '.$this->redactor->text($row->error ?: (json_decode((string) $row->output, true)['reason'] ?? 'no reason recorded')))
                ->countBy()->sortDesc()->take(3);

            return [
                'counts' => $counts,
                'top_problems' => $top->map(fn ($total, $reason) => ['reason' => $reason, 'recent' => $total])->values()->all(),
            ];
        });
    }

    /** @return array<string, int> */
    protected function runCounts(AutomationVersion $version): array
    {
        return DB::table('automation_runs')
            ->where('automation_version_id', $version->id)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function versions(Automation $automation, Context $context): array
    {
        $versions = $automation->versions()->orderByDesc('id')->limit(10)->get(['id', 'automation_id', 'published_at']);
        $runs = DB::table('automation_runs')
            ->whereIn('automation_version_id', $versions->pluck('id'))
            ->selectRaw('automation_version_id, count(*) as total')
            ->groupBy('automation_version_id')
            ->pluck('total', 'automation_version_id');

        return $versions->map(fn (AutomationVersion $item) => [
            'id' => $item->id,
            'published_at' => $context->time($item->published_at),
            'runs' => (int) ($runs[$item->id] ?? 0),
            'live' => $item->id === $automation->active_version_id,
        ])->values()->all();
    }
}
