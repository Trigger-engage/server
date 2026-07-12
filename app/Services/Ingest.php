<?php

namespace TriggerEngage\Server\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Jobs\ProcessEventOccurrence;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\Suppression;
use TriggerEngage\Server\Models\Workspace;

class Ingest
{
    /**
     * Upsert a person. "email" and "phone" are promoted to columns whether
     * they arrive top-level or inside attributes; everything else merges
     * into the attributes JSON. When "anonymous_id" is supplied, any existing
     * anonymous profile for that device is folded into this identified person.
     *
     * @param  array{email?: ?string, phone?: ?string, attributes?: array, properties?: array, anonymous_id?: ?string}  $payload
     */
    public function identify(Workspace $workspace, string $externalId, array $payload): Person
    {
        $anonymousId = $payload['anonymous_id'] ?? null;

        return DB::transaction(function () use ($workspace, $externalId, $payload, $anonymousId): Person {
            $person = Person::query()->firstOrCreate([
                'workspace_id' => $workspace->id,
                'external_id' => $externalId,
            ]);

            // Fold the anonymous history in first so incoming attributes below
            // still win over anything carried over from the anonymous profile.
            if (filled($anonymousId)) {
                $this->mergeAnonymousInto($workspace, $person, $anonymousId);
            }

            $this->fillProfile($person, $payload)->save();

            return $person;
        });
    }

    /**
     * Promote email/phone to columns (whether top-level or inside attributes)
     * and merge everything else into the attributes JSON, without persisting.
     */
    protected function fillProfile(Person $person, array $payload): Person
    {
        $attributes = array_merge($payload['attributes'] ?? [], $payload['properties'] ?? []);
        $email = $payload['email'] ?? $attributes['email'] ?? null;
        $phone = $payload['phone'] ?? $attributes['phone'] ?? null;
        unset($attributes['email'], $attributes['phone']);

        return $person->fill([
            'email' => $email ?? $person->email,
            'phone' => $phone ?? $person->phone,
            'attributes' => array_merge($person->getAttribute('attributes') ?? [], $attributes),
        ]);
    }

    /** Replace all custom properties while retaining identity columns. */
    public function replaceProperties(Workspace $workspace, Person $person, array $properties): Person
    {
        abort_unless($person->workspace_id === $workspace->id, 404);
        $email = $properties['email'] ?? null;
        $phone = $properties['phone'] ?? null;
        unset($properties['email'], $properties['phone']);

        $person->update([
            'email' => $email ?? $person->email,
            'phone' => $phone ?? $person->phone,
            'attributes' => $properties,
        ]);

        return $person->refresh();
    }

    /**
     * Record an event occurrence and kick off automation matching.
     * Returns null when the idempotency key was already seen.
     *
     * @param  array{name: string, person_id?: ?string, anonymous_id?: ?string, email?: ?string, phone?: ?string, attributes?: array, data?: array, idempotency_key?: ?string, occurred_at?: ?string}  $payload
     */
    public function track(Workspace $workspace, array $payload): ?EventOccurrence
    {
        $event = Event::query()->firstOrCreate([
            'workspace_id' => $workspace->id,
            'name' => $payload['name'],
        ], [
            'first_seen_at' => now(),
        ]);

        $person = $this->resolvePerson($workspace, $payload);

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
                'person_id' => $person?->id,
                'anonymous_id' => $payload['anonymous_id'] ?? $person?->anonymous_id,
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

    /**
     * Resolve the person an event belongs to. A known person_id identifies (and
     * merges any anonymous history); otherwise a bare anonymous_id gets its own
     * pre-identity profile so the events aren't lost.
     */
    protected function resolvePerson(Workspace $workspace, array $payload): ?Person
    {
        if (filled($payload['person_id'] ?? null)) {
            return $this->identify($workspace, $payload['person_id'], [
                'email' => $payload['email'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'attributes' => $payload['attributes'] ?? [],
                'properties' => $payload['properties'] ?? [],
                'anonymous_id' => $payload['anonymous_id'] ?? null,
            ]);
        }

        if (filled($payload['anonymous_id'] ?? null)) {
            $person = Person::query()->firstOrCreate(
                ['workspace_id' => $workspace->id, 'anonymous_id' => $payload['anonymous_id']],
                ['external_id' => null]
            );

            // Anonymous profiles still accumulate attributes/contact info so the
            // data is already there to carry over when they later identify.
            $this->fillProfile($person, $payload)->save();

            return $person;
        }

        return null;
    }

    /**
     * Fold an anonymous profile (identified only by its device anonymous_id)
     * into a now-known person: reassign its events, messages, runs, suppressions
     * and segment memberships, merge its attributes underneath the known ones,
     * then delete the empty shell.
     */
    protected function mergeAnonymousInto(Workspace $workspace, Person $person, string $anonymousId): void
    {
        $anonymous = Person::query()
            ->where('workspace_id', $workspace->id)
            ->where('anonymous_id', $anonymousId)
            ->where('id', '!=', $person->id)
            ->lockForUpdate()
            ->first();

        if (! $anonymous) {
            $this->claimAnonymousId($workspace, $person, $anonymousId);

            return;
        }

        EventOccurrence::query()->where('person_id', $anonymous->id)->update(['person_id' => $person->id]);
        Message::query()->where('person_id', $anonymous->id)->update(['person_id' => $person->id]);
        AutomationRun::query()->where('person_id', $anonymous->id)->update(['person_id' => $person->id]);
        RunGoalSubscription::query()->where('person_id', $anonymous->id)->update(['person_id' => $person->id]);

        $this->reassignRespectingUnique(
            Suppression::query()->where('person_id', $anonymous->id)->get(),
            fn (Suppression $row) => Suppression::query()
                ->where('person_id', $person->id)
                ->where('channel', $row->channel)
                ->exists(),
            fn (Suppression $row) => $row->update(['person_id' => $person->id]),
        );

        DB::table('segment_person')->where('person_id', $anonymous->id)->get()->each(function ($row) use ($person): void {
            $alreadyMember = DB::table('segment_person')
                ->where('segment_id', $row->segment_id)
                ->where('person_id', $person->id)
                ->exists();

            $alreadyMember
                ? DB::table('segment_person')->where('id', $row->id)->delete()
                : DB::table('segment_person')->where('id', $row->id)->update(['person_id' => $person->id]);
        });

        // Known identity wins; the anonymous profile only fills the gaps.
        $person->setAttribute('attributes', array_merge(
            $anonymous->getAttribute('attributes') ?? [],
            $person->getAttribute('attributes') ?? [],
        ));
        $person->email ??= $anonymous->email;
        $person->phone ??= $anonymous->phone;
        $person->unsubscribed_at ??= $anonymous->unsubscribed_at;

        $anonymous->delete();
        $person->anonymous_id = $anonymousId;
    }

    /** Claim a device id for this person only if no other profile holds it. */
    protected function claimAnonymousId(Workspace $workspace, Person $person, string $anonymousId): void
    {
        if ($person->anonymous_id === $anonymousId) {
            return;
        }

        $taken = Person::query()
            ->where('workspace_id', $workspace->id)
            ->where('anonymous_id', $anonymousId)
            ->where('id', '!=', $person->id)
            ->exists();

        if (! $taken) {
            $person->anonymous_id = $anonymousId;
        }
    }

    /**
     * @param  Collection<int, mixed>  $rows
     */
    protected function reassignRespectingUnique($rows, callable $collides, callable $reassign): void
    {
        $rows->each(fn ($row) => $collides($row) ? $row->delete() : $reassign($row));
    }
}
