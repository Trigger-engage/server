<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Message;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Dashboard', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'counts' => [
                'automations' => $workspace->automations()->count(),
                'events' => $workspace->events()->count(),
                'templates' => $workspace->templates()->count(),
                'channels' => $workspace->channels()->count(),
                'segments' => $workspace->segments()->count(),
                'broadcasts' => $workspace->broadcasts()->count(),
                'people' => $workspace->people()->count(),
            ],
            'automations' => $workspace->automations()
                ->with('triggerEvent:id,name')
                ->withCount('runs')
                ->latest()
                ->limit(5)
                ->get(['id', 'name', 'status', 'trigger_event_id', 'reentry_policy', 'active_version_id', 'updated_at']),
            'metrics' => [
                'runs_30d' => AutomationRun::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'messages_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'delivered_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->where('status', 'delivered')->count(),
                'failed_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['failed', 'bounced'])->count(),
            ],
            'recentRuns' => AutomationRun::query()->where('workspace_id', $workspace->id)
                ->with('automation:id,name', 'person:id,external_id')->latest()->limit(6)
                ->get(['id', 'automation_id', 'person_id', 'status', 'created_at']),
        ]);
    }
}
