<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;

class AbTestEngineTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private function publishSplit($workspace, string $key, array $variants, ?array $goal = null): Automation
    {
        $automation = $this->makeAutomationDraft($workspace, 'customer_sign_up');
        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [['type' => 'split', 'variants' => $variants]],
            'goal' => $goal,
        ], $this->authHeaders($workspace, $key))->assertRedirect()->assertSessionHasNoErrors();

        return $automation->refresh();
    }

    private function makeAutomationDraft($workspace, string $event): Automation
    {
        $eventModel = Event::firstOrCreate(['workspace_id' => $workspace->id, 'name' => $event], ['first_seen_at' => now()]);

        return $workspace->automations()->create([
            'name' => 'ab_'.$event, 'trigger_event_id' => $eventModel->id, 'reentry_policy' => Automation::REENTRY_EVERY_TIME,
        ]);
    }

    public function test_split_sends_exactly_one_variant_per_person_and_uses_both(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $a = $this->makeEmailTemplate($workspace, 'Variant A', '<p>A</p>');
        $b = $this->makeEmailTemplate($workspace, 'Variant B', '<p>B</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $headers = $this->authHeaders($workspace, $key);

        $this->publishSplit($workspace, $key, [
            ['key' => 'A', 'weight' => 50, 'type' => 'email', 'template_id' => $a->id, 'channel_id' => $channel->id],
            ['key' => 'B', 'weight' => 50, 'type' => 'email', 'template_id' => $b->id, 'channel_id' => $channel->id],
        ]);

        for ($i = 0; $i < 12; $i++) {
            Person::create(['workspace_id' => $workspace->id, 'external_id' => "user-$i", 'email' => "user$i@example.com"]);
            $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => "user-$i"], $headers)->assertAccepted();
        }

        // Exactly one message per person, and both variants actually fire.
        $this->assertSame(12, Message::count());
        $subjects = Message::pluck('subject')->unique()->sort()->values()->all();
        $this->assertSame(['Variant A', 'Variant B'], $subjects);
        Mail::assertSentCount(12);
    }

    public function test_variant_assignment_is_deterministic_per_person(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $a = $this->makeEmailTemplate($workspace, 'Variant A', '<p>A</p>');
        $b = $this->makeEmailTemplate($workspace, 'Variant B', '<p>B</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $headers = $this->authHeaders($workspace, $key);
        $this->publishSplit($workspace, $key, [
            ['key' => 'A', 'weight' => 50, 'type' => 'email', 'template_id' => $a->id, 'channel_id' => $channel->id],
            ['key' => 'B', 'weight' => 50, 'type' => 'email', 'template_id' => $b->id, 'channel_id' => $channel->id],
        ]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'stable', 'email' => 'stable@example.com']);

        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'stable'], $headers)->assertAccepted();
        $first = Message::sole()->subject;

        // Re-running the same person through a fresh run yields the same variant.
        Message::query()->delete();
        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'stable'], $headers)->assertAccepted();
        $this->assertSame($first, Message::sole()->subject);
    }

    public function test_edit_page_reports_per_variant_results(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $a = $this->makeEmailTemplate($workspace, 'Variant A', '<p>A</p>');
        $b = $this->makeEmailTemplate($workspace, 'Variant B', '<p>B</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $headers = $this->authHeaders($workspace, $key);
        $automation = $this->publishSplit($workspace, $key, [
            ['key' => 'A', 'weight' => 50, 'type' => 'email', 'template_id' => $a->id, 'channel_id' => $channel->id],
            ['key' => 'B', 'weight' => 50, 'type' => 'email', 'template_id' => $b->id, 'channel_id' => $channel->id],
        ]);

        for ($i = 0; $i < 6; $i++) {
            Person::create(['workspace_id' => $workspace->id, 'external_id' => "user-$i", 'email' => "user$i@example.com"]);
            $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => "user-$i"], $headers)->assertAccepted();
        }

        $this->get("/app/automations/{$automation->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Automations/Edit')
            ->has('abTests', 1)
            ->where('abTests.0.node_id', 'step_1')
            ->has('abTests.0.variants', 2)
            ->where('abTests.0.variants.0.entered', fn ($n) => $n >= 0)
        );

        // Entered counts across variants sum to the number of runs.
        $abTests = $this->get("/app/automations/{$automation->id}", $headers)->viewData('page')['props']['abTests'];
        $entered = array_sum(array_column($abTests[0]['variants'], 'entered'));
        $this->assertSame(6, $entered);
    }
}
