<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Services\Ingest;

class BatchController extends Controller
{
    public function store(Request $request, Ingest $ingest): JsonResponse
    {
        $body = $request->json()->all();
        $items = array_is_list($body) ? $body : ($body['items'] ?? null);

        $validated = Validator::make(['items' => $items], [
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.type' => ['required', 'in:identify,event'],
            'items.*.person_id' => ['required_without:items.*.anonymous_id', 'nullable', 'string', 'max:150'],
            'items.*.anonymous_id' => ['nullable', 'string', 'max:150'],
            'items.*.name' => ['required_if:items.*.type,event', 'string', 'max:150'],
            'items.*.data' => ['nullable', 'array'],
            'items.*.email' => ['nullable', 'email'],
            'items.*.phone' => ['nullable', 'string', 'max:50'],
            'items.*.attributes' => ['nullable', 'array'],
            'items.*.properties' => ['nullable', 'array', 'max:200'],
            'items.*.idempotency_key' => ['nullable', 'string', 'max:150'],
            'items.*.occurred_at' => ['nullable', 'date'],
        ])->validate();

        $workspace = $request->attributes->get('workspace');
        $results = ['identified' => 0, 'tracked' => 0, 'duplicates' => 0, 'skipped' => 0];

        foreach ($validated['items'] as $item) {
            if ($item['type'] === 'identify') {
                // An identify needs a known external id; a bare anonymous_id has
                // nobody to attach to, so it is skipped rather than errored.
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
