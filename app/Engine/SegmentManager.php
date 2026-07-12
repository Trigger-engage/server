<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentManager
{
    /** Rule segments not recomputed within this window are swept by engage:tick. */
    protected const RECOMPUTE_STALE_MINUTES = 5;

    public function __construct(protected SegmentRuleQuery $ruleQuery) {}

    public function matchOccurrence(EventOccurrence $occurrence): int
    {
        $attached = 0;

        Segment::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('type', Segment::TYPE_EVENT)
            ->where('event_id', $occurrence->event_id)
            ->each(function (Segment $segment) use ($occurrence, &$attached): void {
                $attached += DB::table('segment_person')->insertOrIgnore([
                    'segment_id' => $segment->id,
                    'person_id' => $occurrence->person_id,
                    'source' => 'event',
                    'event_occurrence_id' => $occurrence->id,
                    'added_at' => now(),
                ]);
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
        Segment::query()
            ->where('workspace_id', $person->workspace_id)
            ->where('type', Segment::TYPE_RULE)
            ->each(fn (Segment $segment) => $this->applyRuleToPerson($segment, $person));
    }

    protected function applyRuleToPerson(Segment $segment, Person $person): void
    {
        $isMember = $this->ruleQuery->forWorkspace($segment->workspace_id, $segment->rules ?? [])
            ->whereKey($person->id)
            ->exists();

        if ($isMember) {
            DB::table('segment_person')->insertOrIgnore([
                'segment_id' => $segment->id,
                'person_id' => $person->id,
                'source' => 'rule',
                'added_at' => now(),
            ]);

            return;
        }

        DB::table('segment_person')
            ->where('segment_id', $segment->id)
            ->where('person_id', $person->id)
            ->where('source', 'rule')
            ->delete();
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
}
