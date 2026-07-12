<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;

class EventDefinitionController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Events/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()
                ->withCount('occurrences')
                ->orderBy('name')
                ->get(['id', 'name', 'payload_schema', 'first_seen_at', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150', 'regex:/^[a-zA-Z0-9_.:-]+$/'],
            'payload_schema' => ['nullable', 'array'],
        ]);
        $workspace = $request->attributes->get('workspace');

        $workspace->events()->firstOrCreate(
            ['name' => $validated['name']],
            [
                'payload_schema' => $validated['payload_schema'] ?? null,
                'first_seen_at' => now(),
            ]
        );

        return back()->with('success', 'Event definition saved.');
    }
}
