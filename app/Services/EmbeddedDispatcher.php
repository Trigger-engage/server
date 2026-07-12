<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Support\Facades\Log;
use Throwable;
use TriggerEngage\Laravel\Contracts\Dispatcher;
use TriggerEngage\Server\Contracts\WorkspaceResolver;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class EmbeddedDispatcher implements Dispatcher
{
    public function __construct(protected Ingest $ingest, protected WorkspaceResolver $workspaces) {}

    public function identify(string $personId, array $attributes = [], ?string $anonymousId = null): void
    {
        $this->safely('identify', fn () => $this->ingest->identify($this->workspaces->resolve(), $personId, ['attributes' => $attributes, 'anonymous_id' => $anonymousId]));
    }

    public function setProperties(string $personId, array $properties): void
    {
        $this->safely('properties', fn () => $this->ingest->identify($this->workspaces->resolve(), $personId, ['properties' => $properties]));
    }

    public function event(string $name, array $data = [], ?string $person = null, ?string $anonymousId = null): void
    {
        if (blank($person) && blank($anonymousId)) {
            return;
        }

        $this->safely('event', fn () => $this->ingest->track($this->workspaces->resolve(), [
            'name' => $name,
            'person_id' => $person,
            'anonymous_id' => $anonymousId,
            'data' => $data,
            'idempotency_key' => (string) str()->ulid(),
        ]));
    }

    public function addToSegment(string $segmentId, string $personId): void
    {
        $this->membership($segmentId, $personId, true);
    }

    public function removeFromSegment(string $segmentId, string $personId): void
    {
        $this->membership($segmentId, $personId, false);
    }

    protected function membership(string $segmentId, string $personId, bool $add): void
    {
        $this->safely($add ? 'segment_add' : 'segment_remove', function () use ($segmentId, $personId, $add): void {
            $workspace = $this->workspaces->resolve();
            $segment = Segment::query()->where('workspace_id', $workspace->id)->where('public_id', $segmentId)->firstOrFail();
            $person = Person::query()->where('workspace_id', $workspace->id)->where('external_id', $personId)->firstOrFail();

            if ($segment->type !== Segment::TYPE_MANUAL) {
                throw new \LogicException('Only manual segment membership can be changed through the SDK.');
            }

            if ($add) {
                $segment->people()->syncWithoutDetaching([$person->id => ['source' => 'api', 'added_at' => now()]]);
            } else {
                $segment->people()->detach($person->id);
            }
        });
    }

    protected function safely(string $operation, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            Log::warning('trigger-engage embedded operation failed', [
                'operation' => $operation,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
