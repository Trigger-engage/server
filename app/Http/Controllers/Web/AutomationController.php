<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\EditorFidelity;
use TriggerEngage\Server\Engine\JourneySteps;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\RunStep;

class AutomationController extends Controller
{
    public function __construct(protected JourneySteps $journeySteps) {}

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Automations/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
            'automations' => $workspace->automations()
                ->with('triggerEvent:id,name')
                ->withCount('runs')
                ->latest()
                ->get(['id', 'name', 'status', 'trigger_event_id', 'reentry_policy', 'active_version_id', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $validated = $request->validate($this->journeySteps->automationRules($workspace->id));

        $automation = $workspace->automations()->create($validated);

        return redirect()->route('engage.automations.edit', $automation)
            ->with('success', 'Automation draft created. Add steps and publish it.');
    }

    public function edit(Request $request, Automation $automation): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);
        $automation->load('triggerEvent:id,name', 'activeVersion:id,automation_id,graph,published_at');

        return Inertia::render('Automations/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'automation' => [
                ...$automation->only('id', 'name', 'status', 'reentry_policy'),
                'trigger_event' => $automation->triggerEvent?->only('id', 'name'),
                'steps' => $this->journeySteps->steps($automation->editorGraph()),
                'goal' => $this->journeySteps->goal($automation->editorGraph()),
                'published_at' => $automation->activeVersion?->published_at,
                // An unpublished draft (an AI agent's, through MCP) opens in
                // the editor in place of the live steps; publishing makes it
                // live, discarding drops it.
                'draft' => $automation->hasDraft() ? [
                    'saved_at' => $automation->draft_saved_at,
                    'saved_by' => $automation->draft_saved_by,
                ] : null,
            ],
            'abTests' => $this->abTestResults($automation),
            'templates' => $workspace->templates()
                ->orderBy('name')
                ->get(['id', 'channel', 'name', 'subject']),
            'channels' => $workspace->channels()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'driver', 'is_default']),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
            'segments' => $workspace->segments()->orderBy('name')->get(['id', 'name', 'type']),
            'triggerFilters' => $this->journeySteps->triggerFilters($automation->editorGraph()),
            // Non-empty when the stored graph holds shapes the step list cannot
            // express — the editor shows a banner and gates Publish on an
            // explicit acknowledgement instead of silently rewriting the graph.
            'fidelity' => EditorFidelity::issues($automation->activeVersion?->graph ?? []),
        ]);
    }

    public function publish(Request $request, Automation $automation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);

        // The fidelity guard: if the ACTIVE graph holds shapes the step list
        // cannot express, any publish from the editor rewrites the journey —
        // refuse unless the user has explicitly acknowledged that. This is what
        // stands between a seeded/API-authored journey and the silent
        // flattening that removed a live verification branch.
        $issues = EditorFidelity::issues($automation->activeVersion?->graph ?? []);

        if ($issues !== [] && ! $request->boolean('acknowledge_lossy')) {
            throw ValidationException::withMessages([
                'fidelity' => 'This journey contains steps the editor cannot represent: '
                    .collect($issues)->pluck('message')->implode(' ')
                    .' Publishing from here would rewrite it without them — tick the acknowledgement to publish anyway.',
            ]);
        }

        $validated = $request->validate([
            ...$this->journeySteps->rules($workspace->id),
            'acknowledge_lossy' => ['nullable', 'boolean'],
        ]);

        $graph = $this->journeySteps->build(
            $workspace->id,
            $validated['steps'],
            $validated['goal'] ?? null,
            $validated['trigger_filters'] ?? []
        );
        $automation->publish($graph);

        return back()->with('success', 'Automation published as a new immutable version.');
    }

    public function discardDraft(Request $request, Automation $automation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);
        $automation->update(['draft_graph' => null, 'draft_saved_at' => null, 'draft_saved_by' => null]);

        return back()->with('success', $automation->active_version_id
            ? 'Draft discarded. The editor shows the live journey again.'
            : 'Draft discarded.');
    }

    public function pause(Request $request, Automation $automation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);
        $automation->update(['status' => 'paused']);

        return back()->with('success', 'Automation paused. Existing runs are unchanged.');
    }

    protected function ensureWorkspaceOwns(int $workspaceId, Automation $automation): void
    {
        abort_unless($automation->workspace_id === $workspaceId, 404);
    }

    /**
     * Per-variant results for every A/B split in the published graph. Conversion
     * counts goal completions when the automation has a goal, otherwise runs that
     * finished the journey.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function abTestResults(Automation $automation): array
    {
        $graph = $automation->activeVersion?->graph ?? [];
        $splits = collect($graph['nodes'] ?? [])->where('type', 'split');

        if ($splits->isEmpty()) {
            return [];
        }

        $goalBased = ! empty($graph['goals']);

        return $splits->map(function (array $node) use ($automation, $goalBased): array {
            $steps = RunStep::query()
                ->where('node_id', $node['id'])
                ->where('type', 'split')
                ->whereHas('run', fn ($q) => $q->where('automation_id', $automation->id))
                ->with('run:id,status')
                ->get(['id', 'automation_run_id', 'node_id', 'output']);

            $reached = $goalBased
                ? RunGoalSubscription::query()
                    ->where('status', RunGoalSubscription::STATUS_REACHED)
                    ->whereIn('automation_run_id', $steps->pluck('automation_run_id'))
                    ->pluck('automation_run_id')
                    ->flip()
                : collect();

            $isConverted = fn (RunStep $step): bool => $goalBased
                ? $reached->has($step->automation_run_id)
                : $step->run?->status === AutomationRun::STATUS_COMPLETED;

            $variants = collect($node['config']['variants'] ?? [])->map(function (array $variant) use ($steps, $isConverted): array {
                $assigned = $steps->where('output.variant', $variant['key']);
                $entered = $assigned->count();
                $converted = $assigned->filter($isConverted)->count();

                return [
                    'key' => $variant['key'],
                    'type' => $variant['type'] ?? 'email',
                    'weight' => $variant['weight'] ?? 50,
                    'entered' => $entered,
                    'converted' => $converted,
                    'rate' => $entered ? round($converted / $entered * 100, 1) : 0.0,
                ];
            })->values()->all();

            return ['node_id' => $node['id'], 'goal_based' => $goalBased, 'variants' => $variants];
        })->values()->all();
    }
}
