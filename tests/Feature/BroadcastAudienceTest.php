<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Services\BroadcastAudience;

class BroadcastAudienceTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    /**
     * The estimate's aggregate query must not carry the segment_person pivot
     * columns: MySQL (ONLY_FULL_GROUP_BY, its default) rejects plain columns
     * beside count()/sum() without a GROUP BY, which broke creating a broadcast
     * for a segment in production. SQLite accepts the query, so the SQL is
     * checked here; run this file against MySQL to see the failure itself.
     */
    public function test_estimate_counts_a_segment_without_selecting_pivot_columns(): void
    {
        [$workspace] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        $segment = $workspace->segments()->create(['name' => 'Hand-picked', 'type' => Segment::TYPE_MANUAL]);
        $segment->people()->attach([
            Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ok', 'email' => 'ok@example.com'])->id,
            Person::create(['workspace_id' => $workspace->id, 'external_id' => 'no-email'])->id,
            Person::create(['workspace_id' => $workspace->id, 'external_id' => 'gone', 'email' => 'gone@example.com', 'unsubscribed_at' => now()])->id,
        ], ['source' => 'manual', 'added_at' => now()]);
        // Someone outside the segment is not counted.
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'outside', 'email' => 'outside@example.com']);

        $broadcast = $workspace->broadcasts()->create([
            'name' => 'Draft', 'channel' => 'email', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => '<p>x</p>',
        ]);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $estimate = app(BroadcastAudience::class)->estimate($broadcast);

        $this->assertSame(
            ['total' => 3, 'sendable' => 1, 'no_destination' => 1, 'suppressed' => 1, 'unsubscribed_from_topic' => 0],
            $estimate,
        );

        $aggregate = collect($queries)->first(fn (string $sql) => str_contains($sql, 'as no_destination'));
        $this->assertNotNull($aggregate, 'The aggregate query did not run.');
        $this->assertStringNotContainsString('pivot_', $aggregate);
    }
}
