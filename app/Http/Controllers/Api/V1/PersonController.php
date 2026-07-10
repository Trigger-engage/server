<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Person;
use App\Services\Ingest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PersonController extends Controller
{
    public function update(Request $request, Ingest $ingest, string $externalId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'attributes' => ['nullable', 'array'],
        ]);

        $person = $ingest->identify($request->attributes->get('workspace'), $externalId, $validated);

        return response()->json([
            'person' => [
                'external_id' => $person->external_id,
                'email' => $person->email,
                'phone' => $person->phone,
                'attributes' => $person->getAttribute('attributes'),
            ],
        ]);
    }

    public function destroy(Request $request, string $externalId): JsonResponse
    {
        $deleted = Person::query()
            ->where('workspace_id', $request->attributes->get('workspace')->id)
            ->where('external_id', $externalId)
            ->delete();

        return response()->json(['deleted' => (bool) $deleted]);
    }
}
