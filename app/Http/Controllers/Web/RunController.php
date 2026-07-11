<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RunController extends Controller
{
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
