<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;

class AnalyticsTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    private function message($workspace, $person, array $overrides = []): Message
    {
        return Message::create(array_merge([
            'workspace_id' => $workspace->id,
            'person_id' => $person->id,
            'channel' => 'email',
            'to_address' => 'a@example.com',
            'status' => 'sent',
        ], $overrides));
    }

    public function test_analytics_returns_zero_filled_series_for_the_window(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1', 'email' => 'a@example.com']);
        $this->message($workspace, $person, ['status' => 'delivered', 'delivered_at' => now()]);
        $this->message($workspace, $person, ['status' => 'bounced']);

        $this->get('/app/analytics', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Analytics')
                ->where('days', 30)
                ->has('series.messages', 30)
                ->has('series.delivered', 30)
                ->where('totals.messages.current', 2)
                ->where('totals.delivered.current', 1)
                ->where('totals.failed.current', 1)
                ->has('funnel', 4)
                ->has('channels', 1)
                ->where('channels.0.channel', 'email')
                ->where('channels.0.delivered', 1)
                ->where('channels.0.failed', 1)
            );
    }

    public function test_range_parameter_changes_the_window_length(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->get('/app/analytics?days=7', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where('days', 7)->has('series.messages', 7));

        // An invalid range falls back to the 30-day default.
        $this->get('/app/analytics?days=999', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where('days', 30));
    }

    public function test_analytics_is_workspace_scoped(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $mine = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'mine']);
        $theirs = Person::create(['workspace_id' => $other->id, 'external_id' => 'theirs']);
        $this->message($workspace, $mine);
        $this->message($other, $theirs);

        $this->get('/app/analytics', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where('totals.messages.current', 1));
        $this->get('/app/analytics')->assertUnauthorized();
    }
}
