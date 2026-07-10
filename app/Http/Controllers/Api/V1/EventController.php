<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ingest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function store(Request $request, Ingest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'person_id' => ['nullable', 'string', 'max:150'],
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
