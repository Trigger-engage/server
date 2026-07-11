<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Models\Message;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Dashboard', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()
                ->orderBy('name')
                ->get(['id', 'name', 'payload_schema', 'first_seen_at']),
            'templates' => $workspace->templates()
                ->orderBy('name')
                ->get(['id', 'channel', 'name', 'subject', 'body', 'layout', 'updated_at']),
            'channels' => $workspace->channels()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'driver', 'is_default']),
            'automations' => $workspace->automations()
                ->with('triggerEvent:id,name')
                ->withCount('runs')
                ->latest()
                ->get(['id', 'name', 'status', 'trigger_event_id', 'reentry_policy', 'active_version_id', 'updated_at']),
            'metrics' => [
                'runs_30d' => AutomationRun::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'messages_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->count(),
                'delivered_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->where('status', 'delivered')->count(),
                'failed_30d' => Message::query()->where('workspace_id', $workspace->id)->where('created_at', '>=', now()->subDays(30))->whereIn('status', ['failed', 'bounced'])->count(),
            ],
            'recentRuns' => AutomationRun::query()->where('workspace_id', $workspace->id)
                ->with('automation:id,name', 'person:id,external_id')->latest()->limit(10)
                ->get(['id', 'automation_id', 'person_id', 'status', 'created_at']),
        ]);
    }
}
