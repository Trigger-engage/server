<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Event;

class ManagementUiTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_dashboard_requires_workspace_credentials(): void
    {
        $this->get('/app')->assertUnauthorized();
    }

    public function test_dashboard_is_scoped_to_authenticated_workspace(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$otherWorkspace] = $this->makeWorkspace();
        Event::create(['workspace_id' => $workspace->id, 'name' => 'visible_event']);
        Event::create(['workspace_id' => $otherWorkspace->id, 'name' => 'hidden_event']);

        $this->get('/app', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard')
                ->where('workspace.public_id', $workspace->public_id)
                ->where('counts.events', 1)
            );
    }

    public function test_management_sections_are_workspace_scoped_and_use_dedicated_pages(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$otherWorkspace] = $this->makeWorkspace();
        Event::create(['workspace_id' => $workspace->id, 'name' => 'visible_event']);
        Event::create(['workspace_id' => $otherWorkspace->id, 'name' => 'hidden_event']);
        $headers = $this->authHeaders($workspace, $key);

        $this->get('/app/events', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Events/Index')
            ->has('events', 1)
            ->where('events.0.name', 'visible_event'));
        $this->get('/app/automations', $headers)->assertInertia(fn (Assert $page) => $page->component('Automations/Index'));
        $this->get('/app/templates', $headers)->assertInertia(fn (Assert $page) => $page->component('Templates/Index'));
        $this->get('/app/channels', $headers)->assertInertia(fn (Assert $page) => $page->component('Channels/Index'));
        $this->get('/app/runs', $headers)->assertInertia(fn (Assert $page) => $page->component('Runs/Index'));
    }

    public function test_management_sections_require_workspace_credentials(): void
    {
        foreach (['events', 'automations', 'templates', 'channels', 'runs'] as $section) {
            $this->get('/app/'.$section)->assertUnauthorized();
        }
    }

    public function test_workspace_can_build_and_publish_a_linear_automation(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        $this->post('/app/events', ['name' => 'customer_sign_up'], $headers)->assertRedirect();
        $this->post('/app/templates', [
            'channel' => 'email',
            'name' => 'Welcome',
            'subject' => 'Welcome {{ person.first_name }}',
            'body' => '<p>Hello</p>',
        ], $headers)->assertRedirect();
        $this->post('/app/channels', [
            'type' => 'email',
            'name' => 'Local log',
            'driver' => 'log',
            'is_default' => true,
        ], $headers)->assertRedirect();

        $this->post('/app/automations', [
            'name' => 'Welcome sequence',
            'trigger_event_id' => $workspace->events()->sole()->id,
            'reentry_policy' => Automation::REENTRY_ONCE_EVER,
        ], $headers)->assertRedirect();

        $automation = $workspace->automations()->sole();

        $this->put('/app/automations/'.$automation->id.'/publish', [
            'steps' => [
                ['type' => 'delay', 'days' => 0, 'hours' => 0, 'minutes' => 10],
                [
                    'type' => 'send_email',
                    'template_id' => $workspace->templates()->sole()->id,
                    'channel_id' => $workspace->channels()->sole()->id,
                    'retry_attempts' => 3,
                    'on_failure' => 'continue',
                ],
            ],
        ], $headers)->assertRedirect();

        $automation->refresh();
        $this->assertSame('active', $automation->status);
        $this->assertSame(
            ['trigger', 'delay', 'send_email', 'exit'],
            collect($automation->activeVersion->graph['nodes'])->pluck('type')->all()
        );

        $this->get('/app/automations/'.$automation->id, $headers)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Automations/Edit')
                ->where('automation.name', 'Welcome sequence')
                ->has('automation.steps', 2)
            );
    }

    public function test_automation_routes_cannot_cross_workspace_boundaries(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$otherWorkspace] = $this->makeWorkspace();
        $event = Event::create(['workspace_id' => $otherWorkspace->id, 'name' => 'private_event']);
        $automation = $otherWorkspace->automations()->create([
            'name' => 'Private',
            'trigger_event_id' => $event->id,
        ]);

        $this->get('/app/automations/'.$automation->id, $this->authHeaders($workspace, $key))
            ->assertNotFound();
    }

    public function test_workspace_can_publish_an_event_wait_with_two_outcomes(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $template = $this->makeEmailTemplate($workspace, 'Still need help?', '<p>We are here.</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $automation = $workspace->automations()->create([
            'name' => 'Appointment follow-up',
            'trigger_event_id' => $trigger->id,
        ]);

        $this->put('/app/automations/'.$automation->id.'/publish', [
            'steps' => [[
                'type' => 'wait_for_event',
                'event_id' => $completed->id,
                'timeout_days' => 2,
                'timeout_action' => 'send_email',
                'timeout_template_id' => $template->id,
                'timeout_channel_id' => $channel->id,
                'incoming_field' => 'appointment_id',
                'trigger_field' => 'appointment_id',
                'match_operator' => 'equals',
            ]],
        ], $this->authHeaders($workspace, $key))->assertRedirect();

        $graph = $automation->refresh()->activeVersion->graph;
        $wait = collect($graph['nodes'])->firstWhere('type', 'wait_for_event');
        $timeoutNode = collect($graph['nodes'])->first(
            fn (array $node) => ($node['config']['generated_for_wait'] ?? null) === $wait['id']
        );
        $edges = collect($graph['edges'])->where('from', $wait['id']);

        $this->assertSame(['matched', 'timed_out'], $edges->pluck('branch')->sort()->values()->all());
        $this->assertSame('appointment_id', $wait['config']['match_rules'][0]['incoming_field']);
        $this->assertSame('send_email', $wait['config']['timeout_action']);
        $this->assertSame('send_email', $timeoutNode['type']);
        $this->assertSame($template->id, $timeoutNode['config']['template_id']);
        $this->assertTrue(collect($graph['edges'])->contains(
            fn (array $edge) => $edge['from'] === $timeoutNode['id'] && $edge['to'] === 'exit'
        ));

        $this->get('/app/automations/'.$automation->id, $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->has('events', 2)
                ->has('automation.steps', 1)
                ->where('automation.steps.0.type', 'wait_for_event')
            );
    }

    public function test_workspace_can_publish_an_automation_wide_goal(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $automation = $workspace->automations()->create([
            'name' => 'Care conversion',
            'trigger_event_id' => $trigger->id,
        ]);

        $this->put('/app/automations/'.$automation->id.'/publish', [
            'steps' => [['type' => 'delay', 'days' => 7]],
            'goal' => [
                'enabled' => true,
                'event_id' => $goal->id,
                'incoming_field' => 'care_plan_id',
                'trigger_field' => 'care_plan_id',
                'match_operator' => 'equals',
            ],
        ], $this->authHeaders($workspace, $key))->assertRedirect();

        $publishedGoal = $automation->refresh()->activeVersion->graph['goals'][0];
        $this->assertSame('session_booked', $publishedGoal['event_name']);
        $this->assertSame('care_plan_id', $publishedGoal['match_rules'][0]['incoming_field']);

        $this->get('/app/automations/'.$automation->id, $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->where('automation.goal.enabled', true)
                ->where('automation.goal.event_id', $goal->id)
                ->where('automation.goal.trigger_field', 'care_plan_id')
            );
    }
}
