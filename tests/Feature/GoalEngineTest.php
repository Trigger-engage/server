<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Jobs\ProcessEventOccurrence;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunEventWait;
use TriggerEngage\Server\Models\RunGoalSubscription;

class GoalEngineTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_correlated_goal_stops_a_delayed_run_and_wrong_value_does_not(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $this->makeAutomation($workspace, $trigger->name, $this->delayedGraphWithGoal($goal->id, [[
            'incoming_field' => 'care_plan_id',
            'operator' => 'equals',
            'source' => 'trigger_event',
            'source_field' => 'care_plan_id',
        ]]));
        $headers = $this->authHeaders($workspace, $key);

        $this->postJson('/api/v1/events', [
            'name' => $trigger->name,
            'person_id' => $person->external_id,
            'data' => ['care_plan_id' => 'plan-100'],
        ], $headers)->assertAccepted();

        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertSame(RunGoalSubscription::STATUS_ACTIVE, RunGoalSubscription::sole()->status);

        $this->postJson('/api/v1/events', [
            'name' => $goal->name,
            'person_id' => $person->external_id,
            'data' => ['care_plan_id' => 'plan-other'],
        ], $headers)->assertAccepted();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->refresh()->status);

        $this->postJson('/api/v1/events', [
            'name' => $goal->name,
            'person_id' => $person->external_id,
            'data' => ['care_plan_id' => 'plan-100'],
        ], $headers)->assertAccepted();

        $subscription = RunGoalSubscription::sole();
        $this->assertSame(RunGoalSubscription::STATUS_REACHED, $subscription->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertTrue($run->context['goal_reached']);
        $this->assertSame('plan-100', $run->context['goal']['payload']['care_plan_id']);
        $this->assertSame('completed', $run->steps()->where('type', 'goal')->sole()->status);
    }

    public function test_goal_cancels_a_node_event_wait_and_timeout_cannot_resume_it(): void
    {
        $this->freezeSecond();
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $waitedFor = Event::create(['workspace_id' => $workspace->id, 'name' => 'assessment_completed']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $this->makeAutomation($workspace, $trigger->name, [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'wait_for_event', 'config' => ['event_id' => $waitedFor->id, 'timeout_minutes' => 10]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'done', 'branch' => 'matched'],
                ['from' => 'wait', 'to' => 'done', 'branch' => 'timed_out'],
            ],
            'goals' => [$this->goalConfig($goal->id)],
        ]);
        $headers = $this->authHeaders($workspace, $key);

        $this->postJson('/api/v1/events', ['name' => $trigger->name, 'person_id' => $person->external_id], $headers);
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::STATUS_WAITING_EVENT, $run->status);

        $this->postJson('/api/v1/events', ['name' => $goal->name, 'person_id' => $person->external_id], $headers);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame(RunEventWait::STATUS_CANCELLED, RunEventWait::sole()->status);
        $this->assertSame('skipped', $run->steps()->where('node_id', 'wait')->sole()->status);

        $this->travel(11)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame(1, $run->steps()->where('type', 'goal')->count());
    }

    public function test_goal_cancels_a_pending_send_retry(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'patient-42',
            'email' => 'ada@example.com',
        ]);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $workspace->channels()->create([
            'type' => 'email',
            'driver' => 'mailer-that-does-not-exist',
            'name' => 'Broken channel',
            'is_default' => true,
        ]);
        $graph = $this->linearEmailGraph($template, $channel);
        $graph['nodes'][1]['config']['retry_attempts'] = 3;
        $graph['nodes'][1]['config']['retry_backoff_seconds'] = [1];
        $graph['goals'] = [$this->goalConfig($goal->id)];
        $this->makeAutomation($workspace, $trigger->name, $graph);
        $headers = $this->authHeaders($workspace, $key);

        $this->postJson('/api/v1/events', ['name' => $trigger->name, 'person_id' => $person->external_id], $headers);
        $run = AutomationRun::sole();
        $step = $run->steps()->where('node_id', 'send')->sole();
        $this->assertSame('retrying', $step->status);

        $this->postJson('/api/v1/events', ['name' => $goal->name, 'person_id' => $person->external_id], $headers);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame('skipped', $step->refresh()->status);

        $this->travel(2)->seconds();
        $this->artisan('engage:tick');
        $this->assertSame(1, Message::count());
        $this->assertSame(1, $step->refresh()->attempts);
    }

    public function test_replayed_goal_job_is_idempotent(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $this->makeAutomation($workspace, $trigger->name, $this->delayedGraphWithGoal($goal->id));
        $headers = $this->authHeaders($workspace, $key);

        $this->postJson('/api/v1/events', ['name' => $trigger->name, 'person_id' => $person->external_id], $headers);
        $this->postJson('/api/v1/events', ['name' => $goal->name, 'person_id' => $person->external_id], $headers);
        $occurrence = EventOccurrence::query()->where('event_id', $goal->id)->sole();
        (new ProcessEventOccurrence($occurrence->id))->handle();

        $this->assertSame(1, RunGoalSubscription::count());
        $this->assertSame(1, AutomationRun::sole()->steps()->where('type', 'goal')->count());
    }

    public function test_natural_completion_cancels_the_goal_subscription(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'care_plan_started']);
        $goal = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $this->makeAutomation($workspace, $trigger->name, [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'done']],
            'goals' => [$this->goalConfig($goal->id)],
        ]);

        $this->postJson('/api/v1/events', [
            'name' => $trigger->name,
            'person_id' => $person->external_id,
        ], $this->authHeaders($workspace, $key));

        $this->assertSame(AutomationRun::STATUS_COMPLETED, AutomationRun::sole()->status);
        $this->assertSame(RunGoalSubscription::STATUS_CANCELLED, RunGoalSubscription::sole()->status);
    }

    private function delayedGraphWithGoal(int $eventId, array $rules = []): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'delay', 'type' => 'delay', 'config' => ['hours' => 24]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'delay'],
                ['from' => 'delay', 'to' => 'done'],
            ],
            'goals' => [$this->goalConfig($eventId, $rules)],
        ];
    }

    private function goalConfig(int $eventId, array $rules = []): array
    {
        return [
            'id' => 'goal_1',
            'event_id' => $eventId,
            'match_rules' => $rules,
        ];
    }
}
