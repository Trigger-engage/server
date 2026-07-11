<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EventDefinitionController extends Controller
{
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
