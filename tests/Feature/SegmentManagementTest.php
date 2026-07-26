<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentManagementTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_nested_groups_combine_all_and_any_matching(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'lagos-premium', 'attributes' => ['plan' => 'premium', 'city' => 'Lagos']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'abuja-student', 'attributes' => ['plan' => 'student', 'city' => 'Abuja']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'lagos-free', 'attributes' => ['plan' => 'free', 'city' => 'Lagos']]);

        // city = Lagos AND (plan = premium OR plan = student)
        $this->post('/app/segments', [
            'name' => 'Lagos payers', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'city', 'operator' => 'equals', 'value' => 'Lagos'],
                ['kind' => 'group', 'match' => 'any', 'conditions' => [
                    ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
                    ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'student'],
                ]],
            ]],
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(['lagos-premium'], $segment->people()->pluck('external_id')->all());
    }

    public function test_event_count_conditions_narrow_performed_rules(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_attended']);

        $regular = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'regular']);
        $once = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'once']);

        foreach (range(1, 3) as $i) {
            EventOccurrence::create(['workspace_id' => $workspace->id, 'event_id' => $event->id, 'person_id' => $regular->id, 'payload' => [], 'occurred_at' => now()]);
        }
        EventOccurrence::create(['workspace_id' => $workspace->id, 'event_id' => $event->id, 'person_id' => $once->id, 'payload' => [], 'occurred_at' => now()]);

        $this->post('/app/segments', [
            'name' => 'Attended 3 plus', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'event', 'event_id' => $event->id, 'performed' => true, 'within_days' => 0, 'count_operator' => 'gte', 'count' => 3],
            ]],
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(['regular'], $segment->people()->pluck('external_id')->all());
    }

    public function test_segment_membership_condition_reads_other_segments(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $vip = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);
        $inVip = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'vip-lagos', 'attributes' => ['city' => 'Lagos']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'plain-lagos', 'attributes' => ['city' => 'Lagos']]);
        $vip->people()->attach($inVip->id, ['source' => 'api', 'added_at' => now()]);

        $this->post('/app/segments', [
            'name' => 'Lagos non-VIP', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'city', 'operator' => 'equals', 'value' => 'Lagos'],
                ['kind' => 'segment', 'segment_id' => $vip->id, 'in' => false],
            ]],
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $segment = $workspace->segments()->where('name', 'Lagos non-VIP')->sole();
        $this->assertSame(['plain-lagos'], $segment->people()->pluck('external_id')->all());
    }

    public function test_a_rule_segment_cannot_reference_itself(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        $this->post('/app/segments', [
            'name' => 'Loop', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'exists'],
            ]],
        ], $headers)->assertRedirect();

        $segment = $workspace->segments()->where('name', 'Loop')->sole();

        $this->put("/app/segments/{$segment->id}", [
            'name' => 'Loop',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'segment', 'segment_id' => $segment->id, 'in' => true],
            ]],
        ], $headers)->assertSessionHasErrors('rules');
    }

    public function test_preview_estimates_an_audience_without_saving(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'p1', 'attributes' => ['plan' => 'premium']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'p2', 'attributes' => ['plan' => 'premium']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'f1', 'attributes' => ['plan' => 'free']]);

        $response = $this->post('/app/segments/preview', [
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ]],
        ], $this->authHeaders($workspace, $key));

        $response->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(['p1', 'p2'], collect($response->json('sample'))->pluck('label')->all());
        // Nothing was created.
        $this->assertSame(1, $workspace->segments()->count()); // All people only
    }

    public function test_duplicating_a_rule_segment_copies_rules_and_recomputes(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'prime', 'attributes' => ['plan' => 'premium']]);
        $headers = $this->authHeaders($workspace, $key);

        $this->post('/app/segments', [
            'name' => 'Premium', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ]],
        ], $headers)->assertRedirect();

        $original = $workspace->segments()->where('name', 'Premium')->sole();

        $this->post("/app/segments/{$original->id}/duplicate", [], $headers)->assertRedirect();

        $copy = $workspace->segments()->where('name', 'Premium (copy)')->sole();
        $this->assertSame($original->rules, $copy->rules);
        $this->assertSame(['prime'], $copy->people()->pluck('external_id')->all());
    }

    public function test_manual_duplicate_snapshots_membership(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'member']);
        $manual = $workspace->segments()->create(['name' => 'Handpicked', 'type' => Segment::TYPE_MANUAL]);
        $manual->people()->attach($person->id, ['source' => 'api', 'added_at' => now()]);

        $this->post("/app/segments/{$manual->id}/duplicate", [], $this->authHeaders($workspace, $key))->assertRedirect();

        $copy = $workspace->segments()->where('name', 'Handpicked (copy)')->sole();
        $this->assertSame(['member'], $copy->people()->pluck('external_id')->all());
    }

    public function test_export_streams_members_as_csv(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-9', 'email' => 'nine@example.com']);
        $manual = $workspace->segments()->create(['name' => 'Exportable', 'type' => Segment::TYPE_MANUAL]);
        $manual->people()->attach($person->id, ['source' => 'api', 'added_at' => now()]);

        $response = $this->get("/app/segments/{$manual->id}/export", $this->authHeaders($workspace, $key));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('external_id,anonymous_id,email,phone,source,added_at', $csv);
        $this->assertStringContainsString('user-9', $csv);
        $this->assertStringContainsString('nine@example.com', $csv);
    }

    public function test_csv_import_matches_and_optionally_creates_people(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1', 'email' => 'one@example.com']);
        $manual = $workspace->segments()->create(['name' => 'Imported', 'type' => Segment::TYPE_MANUAL]);

        $file = UploadedFile::fake()->createWithContent('members.csv', implode("\n", [
            'external_id,email',
            'user-1,one@example.com',
            'user-2,two@example.com',
            ',',
        ]));

        $this->post("/app/segments/{$manual->id}/import", [
            'file' => $file,
            'create_missing' => true,
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['user-1', 'user-2'], $manual->people()->orderBy('external_id')->pluck('external_id')->all());
        $this->assertSame('import', $manual->people()->where('external_id', 'user-2')->first()->pivot->source);
        $this->assertNotNull(Person::where('workspace_id', $workspace->id)->where('external_id', 'user-2')->first());
    }

    public function test_csv_import_without_create_missing_skips_unknown_rows(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $manual = $workspace->segments()->create(['name' => 'Imported', 'type' => Segment::TYPE_MANUAL]);

        $file = UploadedFile::fake()->createWithContent('members.csv', "external_id\nghost-1\n");

        $this->post("/app/segments/{$manual->id}/import", [
            'file' => $file,
        ], $this->authHeaders($workspace, $key))->assertRedirect();

        $this->assertSame(0, $manual->people()->count());
        $this->assertNull(Person::where('workspace_id', $workspace->id)->where('external_id', 'ghost-1')->first());
    }

    public function test_broadcasts_page_preselects_the_segment_from_the_query_string(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $manual = $workspace->segments()->create(['name' => 'Handpicked', 'type' => Segment::TYPE_MANUAL]);
        $headers = $this->authHeaders($workspace, $key);

        $this->get("/app/broadcasts?segment={$manual->id}", $headers)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preselectedSegmentId', $manual->id));

        // A segment from another workspace is ignored, not leaked.
        [$other] = $this->makeWorkspace();
        $foreign = $other->segments()->create(['name' => 'Foreign', 'type' => Segment::TYPE_MANUAL]);

        $this->get("/app/broadcasts?segment={$foreign->id}", $headers)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('preselectedSegmentId', null));
    }

    public function test_import_is_rejected_for_rule_segments(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $rule = $workspace->segments()->create([
            'name' => 'Rules only', 'type' => Segment::TYPE_RULE,
            'rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'x', 'operator' => 'exists']]],
        ]);

        $file = UploadedFile::fake()->createWithContent('members.csv', "external_id\nuser-1\n");

        $this->post("/app/segments/{$rule->id}/import", ['file' => $file], $this->authHeaders($workspace, $key))
            ->assertStatus(422);
    }
}
