<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Person;

class PersonPropertiesTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_properties_merge_with_legacy_attributes_and_are_available_to_templates(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        $this->putJson('/api/v1/people/user-42', [
            'attributes' => ['first_name' => 'Ada', 'appointments' => 1],
            'properties' => ['appointments' => 3, 'plan' => 'wellness', 'preferences' => ['language' => 'en']],
        ], $headers)->assertOk()
            ->assertJsonPath('person.properties.appointments', 3)
            ->assertJsonPath('person.attributes.plan', 'wellness');

        $person = $workspace->people()->sole();
        $this->assertSame('Ada', $person->toContext()['first_name']);
        $this->assertSame(3, $person->toContext()['properties']['appointments']);
        $this->assertSame('wellness', $person->toContext()['plan']);
    }

    public function test_people_api_lists_searches_and_reads_only_current_workspace(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com', 'attributes' => ['appointments' => 2]]);
        Person::create(['workspace_id' => $other->id, 'external_id' => 'hidden', 'email' => 'hidden@example.com']);
        $headers = $this->authHeaders($workspace, $key);

        $this->getJson('/api/v1/people?search=ada', $headers)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.properties.appointments', 2);
        $this->getJson('/api/v1/people/ada', $headers)->assertOk()->assertJsonPath('person.email', 'ada@example.com');
        $this->getJson('/api/v1/people/hidden', $headers)->assertNotFound();
    }

    public function test_property_can_be_deleted_without_deleting_person(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'attributes' => ['appointments' => 2, 'plan' => 'care']]);

        $this->deleteJson('/api/v1/people/ada/properties/appointments', [], $this->authHeaders($workspace, $key))
            ->assertOk()->assertJsonPath('deleted', true)->assertJsonMissingPath('person.properties.appointments');

        $this->assertSame(['plan' => 'care'], $workspace->people()->sole()->properties());
    }

    public function test_management_profile_replaces_typed_properties_and_is_scoped(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'attributes' => ['old' => true]]);
        $private = Person::create(['workspace_id' => $other->id, 'external_id' => 'private']);
        $headers = $this->authHeaders($workspace, $key);

        $this->put('/app/people/'.$person->id, [
            'email' => 'ada@example.com',
            'phone' => '+2348000000000',
            'properties' => ['appointments' => 4, 'active' => true, 'preferences' => ['language' => 'en']],
        ], $headers)->assertRedirect();

        $this->assertSame(['appointments' => 4, 'active' => true, 'preferences' => ['language' => 'en']], $person->refresh()->properties());
        $this->get('/app/people', $headers)->assertInertia(fn (Assert $page) => $page->component('People/Index')->has('people.data', 1));
        $this->get('/app/people/'.$person->id, $headers)->assertInertia(fn (Assert $page) => $page->component('People/Show')->where('person.properties.appointments', 4));
        $this->get('/app/people/'.$private->id, $headers)->assertNotFound();
        $this->get('/app/people')->assertUnauthorized();
    }
}
