<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\AutomationRun;

class RunController extends Controller
{
    public const STATUS_FILTERS = ['active', 'completed', 'failed', 'cancelled', 'step_failed'];

    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $status = $request->string('status')->toString();
        $filters = [
            'status' => in_array($status, self::STATUS_FILTERS, true) ? $status : '',
            'automation' => $request->integer('automation') ?: null,
            'person' => $request->integer('person') ?: null,
        ];

        $runs = AutomationRun::query()
            ->where('workspace_id', $workspace->id)
            ->when($filters['automation'], fn ($query, $automation) => $query->where('automation_id', $automation))
            ->when($filters['person'], fn ($query, $person) => $query->where('person_id', $person))
            ->when($filters['status'], fn ($query, $status) => match ($status) {
                'active' => $query->whereIn('status', AutomationRun::activeStatuses()),
                // A send that fails is skipped by default and the run still
                // completes green, so failed steps get their own filter.
                'step_failed' => $query->whereHas('steps', fn ($steps) => $steps->where('status', 'failed')),
                default => $query->where('status', $status),
            })
            ->with('automation:id,name', 'person:id,external_id,email', 'occurrence.event:id,name')
            ->withCount(['steps as failed_steps_count' => fn ($steps) => $steps->where('status', 'failed')])
            ->latest()->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Runs/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'runs' => $runs,
            'filters' => $filters,
            'automations' => $workspace->automations()->orderBy('name')->get(['id', 'name']),
            'scopedPerson' => $filters['person']
                ? $workspace->people()->find($filters['person'], ['id', 'external_id', 'email'])?->only('id', 'external_id', 'email')
                : null,
        ]);
    }

    public function show(Request $request, AutomationRun $run): Response
    {
        $workspace = $request->attributes->get('workspace');
        abort_unless($run->workspace_id === $workspace->id, 404);
        $run->load(
            'automation:id,name',
            'person:id,external_id,email,phone',
            'occurrence.event:id,name',
            'steps.message',
            'eventWaits.event:id,name',
            'eventWaits.matchedOccurrence:id,event_id,payload,occurred_at',
            'goalSubscriptions.event:id,name',
            'goalSubscriptions.reachedOccurrence:id,event_id,payload,occurred_at',
        );

        return Inertia::render('Runs/Show', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'run' => $run,
        ]);
    }
}
