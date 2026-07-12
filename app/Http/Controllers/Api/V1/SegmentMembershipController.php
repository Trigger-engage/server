<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentMembershipController extends Controller
{
    public function store(Request $request, string $segment, string $externalId): JsonResponse
    {
        [$resolved, $person] = $this->resolve($request, $segment, $externalId);
        abort_if($resolved->type !== Segment::TYPE_MANUAL, 422, 'Only manual segment membership can be changed through the API.');
        $resolved->people()->syncWithoutDetaching([$person->id => ['source' => 'api', 'added_at' => now()]]);

        return response()->json(['segment' => $resolved->public_id, 'person_id' => $person->external_id, 'member' => true]);
    }

    public function destroy(Request $request, string $segment, string $externalId): JsonResponse
    {
        [$resolved, $person] = $this->resolve($request, $segment, $externalId);
        abort_if($resolved->type !== Segment::TYPE_MANUAL, 422, 'Only manual segment membership can be changed through the API.');
        $resolved->people()->detach($person->id);

        return response()->json(['segment' => $resolved->public_id, 'person_id' => $person->external_id, 'member' => false]);
    }

    protected function resolve(Request $request, string $segment, string $externalId): array
    {
        $workspaceId = $request->attributes->get('workspace')->id;
        $resolved = Segment::query()->where('workspace_id', $workspaceId)->where('public_id', $segment)->firstOrFail();
        $person = Person::query()->where('workspace_id', $workspaceId)->where('external_id', $externalId)->firstOrFail();

        return [$resolved, $person];
    }
}
