<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class RuleSegmentsTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_attribute_rule_materializes_matching_people_on_create(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'prime', 'attributes' => ['plan' => 'premium']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'free', 'attributes' => ['plan' => 'free']]);

        $this->post('/app/segments', [
            'name' => 'Premium members', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ]],
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(['prime'], $segment->people()->pluck('external_id')->all());
        $this->assertSame('rule', $segment->people()->first()->pivot->source);
    }

    public function test_behavioural_rule_finds_booked_but_not_attended(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $booked = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $attended = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_attended']);
        $headers = $this->authHeaders($workspace, $key);

        // no-show booked but never attended; loyal booked and attended.
        foreach (['no-show' => false, 'loyal' => true] as $id => $didAttend) {
            $this->postJson('/api/v1/events', ['name' => 'session_booked', 'person_id' => $id], $headers)->assertAccepted();
            if ($didAttend) {
                $this->postJson('/api/v1/events', ['name' => 'session_attended', 'person_id' => $id], $headers)->assertAccepted();
            }
        }

        $this->post('/app/segments', [
            'name' => 'No-shows', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'event', 'event_id' => $booked->id, 'performed' => true, 'within_days' => 30],
                ['kind' => 'event', 'event_id' => $attended->id, 'performed' => false, 'within_days' => 30],
            ]],
        ], $headers)->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['no-show'], $workspace->segments()->where('type', Segment::TYPE_RULE)->sole()->people()->pluck('external_id')->all());
    }

    public function test_membership_updates_incrementally_when_a_new_event_arrives(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $attended = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_attended']);
        $booked = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $headers = $this->authHeaders($workspace, $key);
        $this->postJson('/api/v1/events', ['name' => 'session_booked', 'person_id' => 'user-1'], $headers)->assertAccepted();

        $this->post('/app/segments', [
            'name' => 'No-shows', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'event', 'event_id' => $booked->id, 'performed' => true, 'within_days' => 0],
                ['kind' => 'event', 'event_id' => $attended->id, 'performed' => false, 'within_days' => 0],
            ]],
        ], $headers)->assertRedirect();
        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(1, $segment->people()->count());

        // Attending removes the person from the no-show audience without a full sweep.
        $this->postJson('/api/v1/events', ['name' => 'session_attended', 'person_id' => 'user-1'], $headers)->assertAccepted();
        $this->assertSame(0, $segment->people()->count());
    }

    public function test_tick_recomputes_time_based_membership_as_it_drifts(): void
    {
        $this->freezeSecond();
        [$workspace, $key] = $this->makeWorkspace();
        $login = Event::create(['workspace_id' => $workspace->id, 'name' => 'app_open']);
        $headers = $this->authHeaders($workspace, $key);
        $this->postJson('/api/v1/events', ['name' => 'app_open', 'person_id' => 'user-1'], $headers)->assertAccepted();

        // "Inactive": has NOT opened the app in the last 14 days.
        $this->post('/app/segments', [
            'name' => 'Dormant', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'event', 'event_id' => $login->id, 'performed' => false, 'within_days' => 14],
            ]],
        ], $headers)->assertRedirect();
        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(0, $segment->people()->count());

        // 15 days later they qualify, and the periodic sweep picks it up.
        $this->travel(15)->days();
        $this->artisan('engage:tick');
        $this->assertSame(1, $segment->people()->count());
    }

    public function test_rule_segments_cannot_be_edited_by_the_membership_api(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'paid']);
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        $segment = $workspace->segments()->create([
            'name' => 'Rule', 'type' => Segment::TYPE_RULE,
            'rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'x']]],
        ]);

        $this->putJson("/api/v1/segments/{$segment->public_id}/people/{$person->external_id}", [], $this->authHeaders($workspace, $key))
            ->assertUnprocessable();
    }

    public function test_editing_rules_recomputes_membership(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'a', 'attributes' => ['country' => 'NG']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'b', 'attributes' => ['country' => 'KE']]);
        $headers = $this->authHeaders($workspace, $key);
        $this->post('/app/segments', [
            'name' => 'Geo', 'type' => 'rule',
            'rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'country', 'operator' => 'equals', 'value' => 'NG']]],
        ], $headers)->assertRedirect();
        $segment = $workspace->segments()->where('type', Segment::TYPE_RULE)->sole();
        $this->assertSame(['a'], $segment->people()->pluck('external_id')->all());

        $this->put("/app/segments/{$segment->id}", [
            'name' => 'Geo', 'rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'country', 'operator' => 'equals', 'value' => 'KE']]],
        ], $headers)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(['b'], $segment->people()->pluck('external_id')->all());
    }
}
