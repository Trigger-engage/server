<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\AutomationRun;

class RunController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Runs/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'runs' => AutomationRun::query()
                ->where('workspace_id', $workspace->id)
                ->with('automation:id,name', 'person:id,external_id,email', 'occurrence.event:id,name')
                ->latest()
                ->paginate(25)
                ->withQueryString(),
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
