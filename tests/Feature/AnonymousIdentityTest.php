<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class AnonymousIdentityTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_anonymous_event_creates_a_pre_identity_profile(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->postJson('/api/v1/events', ['name' => 'page_view', 'anonymous_id' => 'device-1', 'data' => ['path' => '/pricing']], $this->authHeaders($workspace, $key))
            ->assertAccepted();

        $anon = Person::query()->where('workspace_id', $workspace->id)->where('anonymous_id', 'device-1')->first();
        $this->assertNotNull($anon);
        $this->assertNull($anon->external_id);
        $this->assertSame(1, EventOccurrence::where('person_id', $anon->id)->count());
    }

    public function test_event_requires_a_person_or_anonymous_id(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->postJson('/api/v1/events', ['name' => 'page_view'], $this->authHeaders($workspace, $key))
            ->assertUnprocessable();
    }

    public function test_identify_merges_anonymous_history_into_the_known_person(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        // Two anonymous events on the same device before signup.
        $this->postJson('/api/v1/events', ['name' => 'page_view', 'anonymous_id' => 'device-1'], $headers)->assertAccepted();
        $this->postJson('/api/v1/events', ['name' => 'pricing_view', 'anonymous_id' => 'device-1', 'attributes' => ['plan_interest' => 'premium']], $headers)->assertAccepted();
        $anon = Person::query()->where('anonymous_id', 'device-1')->sole();

        // They sign up: the identify call carries the device id.
        $this->putJson('/api/v1/people/user-1', ['anonymous_id' => 'device-1', 'attributes' => ['email' => 'a@example.com', 'plan_interest' => 'enterprise']], $headers)
            ->assertOk();

        $user = Person::query()->where('external_id', 'user-1')->sole();
        $this->assertSame('device-1', $user->anonymous_id);
        $this->assertSame(2, $user->occurrences()->count(), 'pre-signup events reassign to the identified person');
        $this->assertSame('a@example.com', $user->email);
        // Known identity wins over the anonymous value on conflict.
        $this->assertSame('enterprise', $user->properties()['plan_interest']);
        // The anonymous shell is gone; only the identified person remains.
        $this->assertNull(Person::find($anon->id));
        $this->assertSame(1, Person::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_merge_does_not_duplicate_shared_segment_membership(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);

        // Anonymous profile joins the segment, then the identified person exists and also joins.
        $this->postJson('/api/v1/events', ['name' => 'page_view', 'anonymous_id' => 'device-1'], $headers)->assertAccepted();
        $anon = Person::query()->where('anonymous_id', 'device-1')->sole();
        $known = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        $segment->people()->attach([
            $anon->id => ['source' => 'api', 'added_at' => now()],
            $known->id => ['source' => 'api', 'added_at' => now()],
        ]);

        $this->putJson('/api/v1/people/user-1', ['anonymous_id' => 'device-1'], $headers)->assertOk();

        $this->assertSame(1, $segment->people()->count());
        $this->assertSame(['user-1'], $segment->people()->pluck('external_id')->all());
    }

    public function test_later_anonymous_events_still_attribute_to_the_identified_person(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $this->putJson('/api/v1/people/user-1', ['anonymous_id' => 'device-1', 'attributes' => ['email' => 'a@example.com']], $headers)->assertOk();
        $user = Person::query()->where('external_id', 'user-1')->sole();

        // A late event fired with only the device id lands on the same person.
        $this->postJson('/api/v1/events', ['name' => 'app_open', 'anonymous_id' => 'device-1'], $headers)->assertAccepted();

        $this->assertSame(1, $user->occurrences()->count());
        $this->assertSame(1, Person::query()->where('workspace_id', $workspace->id)->count());
    }
}
