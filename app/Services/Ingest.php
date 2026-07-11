<?php

namespace App\Services;

use App\Jobs\ProcessEventOccurrence;
use App\Models\Event;
use App\Models\EventOccurrence;
use App\Models\Person;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

class Ingest
{
    /**
     * Upsert a person. "email" and "phone" are promoted to columns whether
     * they arrive top-level or inside attributes; everything else merges
     * into the attributes JSON.
     *
     * @param  array{email?: ?string, phone?: ?string, attributes?: array}  $payload
     */
    public function identify(Workspace $workspace, string $externalId, array $payload): Person
    {
        $attributes = $payload['attributes'] ?? [];

        $email = $payload['email'] ?? $attributes['email'] ?? null;
        $phone = $payload['phone'] ?? $attributes['phone'] ?? null;
        unset($attributes['email'], $attributes['phone']);

        $person = Person::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'external_id' => $externalId,
        ]);

        $person->fill([
            'email' => $email ?? $person->email,
            'phone' => $phone ?? $person->phone,
            'attributes' => array_merge($person->getAttribute('attributes') ?? [], $attributes),
        ])->save();

        return $person;
    }

    /**
     * Record an event occurrence and kick off automation matching.
     * Returns null when the idempotency key was already seen.
     *
     * @param  array{name: string, person_id: string, email?: ?string, phone?: ?string, attributes?: array, data?: array, idempotency_key?: ?string, occurred_at?: ?string}  $payload
     */
    public function track(Workspace $workspace, array $payload): ?EventOccurrence
    {
        $event = Event::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'name' => $payload['name'],
        ], [
            'first_seen_at' => now(),
        ]);

        $person = $this->identify($workspace, $payload['person_id'], [
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'attributes' => $payload['attributes'] ?? [],
        ]);

        $idempotencyKey = $payload['idempotency_key'] ?? null;

        if ($idempotencyKey && EventOccurrence::query()
            ->where('workspace_id', $workspace->id)
            ->where('idempotency_key', $idempotencyKey)
            ->exists()) {
            return null;
        }

        try {
            $occurrence = EventOccurrence::query()->create([
                'workspace_id' => $workspace->id,
                'event_id' => $event->id,
                'person_id' => $person->id,
                'payload' => $payload['data'] ?? [],
                'idempotency_key' => $idempotencyKey,
                'occurred_at' => isset($payload['occurred_at'])
                    ? Carbon::parse($payload['occurred_at'])
                    : now(),
            ]);
        } catch (QueryException $exception) {
            // Lost a race on the unique (workspace_id, idempotency_key) index.
            if ($idempotencyKey && str_contains(strtolower($exception->getMessage()), 'unique')) {
                return null;
            }

            throw $exception;
        }

        ProcessEventOccurrence::dispatch($occurrence->id);

        return $occurrence;
    }
}
