<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Ingest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BatchController extends Controller
{
    public function store(Request $request, Ingest $ingest): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'max:500'],
            'items.*.type' => ['required', 'in:identify,event'],
            'items.*.person_id' => ['nullable', 'string', 'max:150'],
            'items.*.name' => ['required_if:items.*.type,event', 'string', 'max:150'],
            'items.*.data' => ['nullable', 'array'],
            'items.*.email' => ['nullable', 'email'],
            'items.*.phone' => ['nullable', 'string', 'max:50'],
            'items.*.attributes' => ['nullable', 'array'],
            'items.*.idempotency_key' => ['nullable', 'string', 'max:150'],
            'items.*.occurred_at' => ['nullable', 'date'],
        ]);

        $workspace = $request->attributes->get('workspace');
        $results = ['identified' => 0, 'tracked' => 0, 'duplicates' => 0, 'skipped' => 0];

        foreach ($validated['items'] as $item) {
            if ($item['type'] === 'identify') {
                if (blank($item['person_id'] ?? null)) {
                    $results['skipped']++;

                    continue;
                }

                $ingest->identify($workspace, $item['person_id'], $item);
                $results['identified']++;

                continue;
            }

            $ingest->track($workspace, $item)
                ? $results['tracked']++
                : $results['duplicates']++;
        }

        return response()->json($results, 202);
    }
}
