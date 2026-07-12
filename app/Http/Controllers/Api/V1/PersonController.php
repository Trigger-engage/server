<?php

namespace TriggerEngage\Server\Http\Controllers\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Services\Ingest;

class PersonController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $workspace = $request->attributes->get('workspace');
        $people = Person::query()->where('workspace_id', $workspace->id)
            ->when($request->string('search')->toString(), fn ($query, $search) => $query->where(function ($nested) use ($search) {
                $nested->where('external_id', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%");
            }))
            ->latest()->paginate(min(100, max(1, $request->integer('per_page', 25))));

        return response()->json($people->through(fn (Person $person) => $this->resource($person)));
    }

    public function show(Request $request, string $externalId): JsonResponse
    {
        $person = $this->resolve($request, $externalId);

        return response()->json(['person' => $this->resource($person)]);
    }

    public function update(Request $request, Ingest $ingest, string $externalId): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'attributes' => ['nullable', 'array'],
            'properties' => ['nullable', 'array', 'max:200'],
            'anonymous_id' => ['nullable', 'string', 'max:150'],
        ]);

        $person = $ingest->identify($request->attributes->get('workspace'), $externalId, $validated);
        app(SegmentManager::class)->syncPersonRuleSegments($person);

        return response()->json([
            'person' => $this->resource($person),
        ]);
    }

    public function destroyProperty(Request $request, string $externalId, string $property): JsonResponse
    {
        $person = $this->resolve($request, $externalId);
        $properties = $person->properties();
        $deleted = array_key_exists($property, $properties);
        unset($properties[$property]);
        $person->update(['attributes' => $properties]);
        app(SegmentManager::class)->syncPersonRuleSegments($person);

        return response()->json(['deleted' => $deleted, 'person' => $this->resource($person->refresh())]);
    }

    public function destroy(Request $request, string $externalId): JsonResponse
    {
        $deleted = Person::query()
            ->where('workspace_id', $request->attributes->get('workspace')->id)
            ->where('external_id', $externalId)
            ->delete();

        return response()->json(['deleted' => (bool) $deleted]);
    }

    protected function resolve(Request $request, string $externalId): Person
    {
        return Person::query()->where('workspace_id', $request->attributes->get('workspace')->id)->where('external_id', $externalId)->firstOrFail();
    }

    protected function resource(Person $person): array
    {
        return [
            'external_id' => $person->external_id,
            'anonymous_id' => $person->anonymous_id,
            'email' => $person->email,
            'phone' => $person->phone,
            'properties' => $person->properties(),
            'attributes' => $person->properties(),
            'created_at' => $person->created_at,
            'updated_at' => $person->updated_at,
        ];
    }
}
