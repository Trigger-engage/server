<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Services\Ingest;

class EventController extends Controller
{
    public function store(Request $request, Ingest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'person_id' => ['required_without:anonymous_id', 'nullable', 'string', 'max:150'],
            'anonymous_id' => ['required_without:person_id', 'nullable', 'string', 'max:150'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'attributes' => ['nullable', 'array'],
            'properties' => ['nullable', 'array', 'max:200'],
            'data' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:150'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $occurrence = $ingest->track($request->attributes->get('workspace'), $validated);

        if (! $occurrence) {
            return response()->json(['accepted' => true, 'duplicate' => true], 200);
        }

        return response()->json([
            'accepted' => true,
            'occurrence_id' => $occurrence->id,
        ], 202);
    }
}
