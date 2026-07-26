<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Jobs\ProcessEventOccurrence;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentManager
{
    /** Rule segments not recomputed within this window are swept by engage:tick. */
    protected const RECOMPUTE_STALE_MINUTES = 5;

    /**
     * Rule segments can reference other segments' materialized membership, so a
     * change in one pass can flip another segment's verdict. Passes are bounded;
     * chains longer than this converge on the next sweep instead.
     */
    protected const MAX_SYNC_PASSES = 3;

    public const EVENT_ENTERED = 'segment_entered';

    public const EVENT_LEFT = 'segment_left';

    public function __construct(protected SegmentRuleQuery $ruleQuery) {}

    public function matchOccurrence(EventOccurrence $occurrence): int
    {
        $attached = 0;

        Segment::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('type', Segment::TYPE_EVENT)
            ->where('event_id', $occurrence->event_id)
            ->each(function (Segment $segment) use ($occurrence, &$attached): void {
                $inserted = DB::table('segment_person')->insertOrIgnore([
                    'segment_id' => $segment->id,
                    'person_id' => $occurrence->person_id,
                    'source' => 'event',
                    'event_occurrence_id' => $occurrence->id,
                    'added_at' => now(),
                ]);

                if ($inserted) {
                    $this->recordMembershipChange($segment, [$occurrence->person_id], entered: true);
                }

                $attached += $inserted;
            });

        return $attached;
    }

    /**
     * Re-evaluate a single person against every rule segment in their workspace.
     * Called when a person's attributes or event history change so behavioural
     * audiences stay current without a full sweep.
     */
    public function syncPersonRuleSegments(Person $person): void
    {
        for ($pass = 0; $pass < self::MAX_SYNC_PASSES; $pass++) {
            $changed = false;

            Segment::query()
                ->where('workspace_id', $person->workspace_id)
                ->where('type', Segment::TYPE_RULE)
                ->each(function (Segment $segment) use ($person, &$changed): void {
                    $changed = $this->applyRuleToPerson($segment, $person) || $changed;
                });

            if (! $changed) {
                return;
            }
        }
    }

    /** @return bool whether overall membership actually changed */
    protected function applyRuleToPerson(Segment $segment, Person $person): bool
    {
        $isMember = $this->ruleQuery->forWorkspace($segment->workspace_id, $segment->rules ?? [])
            ->whereKey($person->id)
            ->exists();

        if ($isMember) {
            $inserted = DB::table('segment_person')->insertOrIgnore([
                'segment_id' => $segment->id,
                'person_id' => $person->id,
                'source' => 'rule',
                'added_at' => now(),
            ]);

            if ($inserted) {
                $this->recordMembershipChange($segment, [$person->id], entered: true);
            }

            return (bool) $inserted;
        }

        $deleted = DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->where('person_id', $person->id)
            ->where('source', 'rule')
            ->delete();

        if ($deleted) {
            $this->recordMembershipChange($segment, [$person->id], entered: false);
        }

        return (bool) $deleted;
    }

    /**
     * Fully recompute a rule segment's membership. Only rows this manager owns
     * (source=rule) are touched, so manual/event overlaps are left alone.
     */
    public function recompute(Segment $segment): int
    {
        if (! $segment->isRuleBased()) {
            return 0;
        }

        $before = DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->pluck('person_id');

        $matchingIds = $this->ruleQuery
            ->forWorkspace($segment->workspace_id, $segment->rules ?? [])
            ->pluck('people.id');

        $now = now();

        DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->where('source', 'rule')
            ->when($matchingIds->isNotEmpty(), fn ($q) => $q->whereNotIn('person_id', $matchingIds))
            ->delete();

        $matchingIds
            ->chunk(500)
            ->each(function ($chunk) use ($segment, $now): void {
                DB::table('segment_person')->insertOrIgnore(
                    $chunk->map(fn ($id) => [
                        'segment_id' => $segment->id,
                        'person_id' => $id,
                        'source' => 'rule',
                        'added_at' => $now,
                    ])->all()
                );
            });

        $segment->forceFill(['recomputed_at' => $now])->saveQuietly();

        $after = DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->pluck('person_id');

        $this->recordMembershipChange($segment, $after->diff($before)->values()->all(), entered: true);
        $this->recordMembershipChange($segment, $before->diff($after)->values()->all(), entered: false);

        return $matchingIds->count();
    }

    /** Recompute rule segments whose materialized membership has gone stale. */
    public function recomputeStale(): int
    {
        $recomputed = 0;

        Segment::query()
            ->where('type', Segment::TYPE_RULE)
            ->where(fn ($q) => $q
                ->whereNull('recomputed_at')
                ->orWhere('recomputed_at', '<=', now()->subMinutes(self::RECOMPUTE_STALE_MINUTES)))
            ->each(function (Segment $segment) use (&$recomputed): void {
                $this->recompute($segment);
                $recomputed++;
            });

        return $recomputed;
    }

    /** Explicit (manual/API) membership add; reports the change when it is real. */
    public function addMember(Segment $segment, Person $person, string $source = 'api'): bool
    {
        $inserted = DB::table('segment_person')->insertOrIgnore([
            'segment_id' => $segment->id,
            'person_id' => $person->id,
            'source' => $source,
            'added_at' => now(),
        ]);

        if ($inserted) {
            $this->recordMembershipChange($segment, [$person->id], entered: true);
        }

        return (bool) $inserted;
    }

    /** Explicit (manual/API) membership removal; reports the change when it is real. */
    public function removeMember(Segment $segment, Person $person): bool
    {
        $deleted = DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->where('person_id', $person->id)
            ->delete();

        if ($deleted) {
            $this->recordMembershipChange($segment, [$person->id], entered: false);
        }

        return (bool) $deleted;
    }

    /**
     * Record a person's real membership change as a segment_entered /
     * segment_left occurrence so automations can trigger on audience movement.
     * Callers only report changes they have confirmed (insert/delete counts),
     * which is what keeps event-bound segments listening to these events from
     * looping: re-adding an existing member records nothing.
     *
     * @param  array<int, int|string|null>  $personIds
     */
    public function recordMembershipChange(Segment $segment, array $personIds, bool $entered): void
    {
        // Entering "All people" is just person-creation; recording it would add
        // an occurrence + matcher job for every profile with no audience value.
        if ($segment->isAllPeople()) {
            return;
        }

        $personIds = array_values(array_filter($personIds));

        if ($personIds === []) {
            return;
        }

        $event = Event::query()->firstOrCreate([
            'workspace_id' => $segment->workspace_id,
            'name' => $entered ? self::EVENT_ENTERED : self::EVENT_LEFT,
        ], [
            'first_seen_at' => now(),
        ]);

        foreach ($personIds as $personId) {
            $occurrence = EventOccurrence::query()->create([
                'workspace_id' => $segment->workspace_id,
                'event_id' => $event->id,
                'person_id' => $personId,
                'payload' => [
                    'segment_id' => $segment->id,
                    'segment_public_id' => $segment->public_id,
                    'segment_name' => $segment->name,
                ],
                'occurred_at' => now(),
            ]);

            ProcessEventOccurrence::dispatch($occurrence->id);
        }
    }
}
