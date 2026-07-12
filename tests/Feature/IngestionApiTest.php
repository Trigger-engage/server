<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Workspace;

class IngestionApiTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_rejects_missing_credentials(): void
    {
        $this->postJson('/api/v1/events', ['name' => 'x'])->assertStatus(401);
    }

    public function test_rejects_valid_key_paired_with_wrong_workspace(): void
    {
        [$workspaceA, $keyA] = $this->makeWorkspace();
        [$workspaceB] = $this->makeWorkspace();

        // Key A against workspace B: the combination is what authenticates.
        $this->postJson('/api/v1/events', ['name' => 'x'], [
            'Authorization' => 'Basic '.base64_encode($workspaceB->public_id.':'.$keyA),
        ])->assertStatus(401);

        $this->postJson('/api/v1/events', [
            'name' => 'x',
            'person_id' => 'user-1',
        ], $this->authHeaders($workspaceA, $keyA))
            ->assertStatus(202);
    }

    public function test_event_registers_definition_person_and_occurrence(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'data' => ['plan' => 'free'],
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        $this->assertDatabaseHas('events', [
            'workspace_id' => $workspace->id,
            'name' => 'customer_sign_up',
        ]);
        $this->assertDatabaseHas('people', [
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
        ]);
        $this->assertSame(1, EventOccurrence::count());
    }

    public function test_event_can_upsert_inline_person_attributes(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'email' => 'ada@example.com',
            'attributes' => ['first_name' => 'Ada'],
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        $person = Person::sole();
        $this->assertSame('ada@example.com', $person->email);
        $this->assertSame(['first_name' => 'Ada'], $person->getAttribute('attributes'));
    }

    public function test_event_requires_a_person(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up'], $this->authHeaders($workspace, $key))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('person_id');
    }

    public function test_idempotency_key_deduplicates(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $payload = [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'idempotency_key' => 'same-key',
        ];

        $this->postJson('/api/v1/events', $payload, $this->authHeaders($workspace, $key))
            ->assertStatus(202);
        $this->postJson('/api/v1/events', $payload, $this->authHeaders($workspace, $key))
            ->assertStatus(200)
            ->assertJson(['duplicate' => true]);

        $this->assertSame(1, EventOccurrence::count());
    }

    public function test_identify_upserts_and_merges_attributes(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        $this->putJson('/api/v1/people/user-42', [
            'attributes' => ['email' => 'ada@example.com', 'first_name' => 'Ada'],
        ], $headers)->assertOk();

        $this->putJson('/api/v1/people/user-42', [
            'attributes' => ['country' => 'NG'],
        ], $headers)->assertOk();

        $person = Person::firstWhere('external_id', 'user-42');

        $this->assertSame('ada@example.com', $person->email); // promoted to column
        $this->assertSame(
            ['first_name' => 'Ada', 'country' => 'NG'],
            $person->getAttribute('attributes')
        );
        $this->assertSame(1, Person::count());
    }

    public function test_batch_processes_identify_and_events(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->postJson('/api/v1/batch', ['items' => [
            ['type' => 'identify', 'person_id' => 'user-1', 'attributes' => ['email' => 'a@b.c']],
            ['type' => 'event', 'name' => 'customer_sign_up', 'person_id' => 'user-1'],
            ['type' => 'event', 'name' => 'customer_sign_up', 'person_id' => 'user-1', 'idempotency_key' => 'k1'],
            ['type' => 'event', 'name' => 'customer_sign_up', 'person_id' => 'user-1', 'idempotency_key' => 'k1'],
        ]], $this->authHeaders($workspace, $key))
            ->assertStatus(202)
            ->assertJson(['identified' => 1, 'tracked' => 2, 'duplicates' => 1]);
    }

    public function test_batch_accepts_the_spec_top_level_array_shape(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->postJson('/api/v1/batch', [
            ['type' => 'identify', 'person_id' => 'user-1', 'email' => 'ada@example.com'],
            ['type' => 'event', 'name' => 'customer_sign_up', 'person_id' => 'user-1'],
        ], $this->authHeaders($workspace, $key))
            ->assertAccepted()
            ->assertJson(['identified' => 1, 'tracked' => 1]);
    }

    public function test_person_deletion_is_workspace_scoped(): void
    {
        [$workspaceA, $keyA] = $this->makeWorkspace();
        [$workspaceB, $keyB] = $this->makeWorkspace();

        Person::create(['workspace_id' => $workspaceA->id, 'external_id' => 'user-42']);
        Person::create(['workspace_id' => $workspaceB->id, 'external_id' => 'user-42']);

        $this->deleteJson('/api/v1/people/user-42', [], $this->authHeaders($workspaceA, $keyA))
            ->assertOk()
            ->assertJson(['deleted' => true]);

        $this->assertSame(0, Person::where('workspace_id', $workspaceA->id)->count());
        $this->assertSame(1, Person::where('workspace_id', $workspaceB->id)->count());
    }
}
