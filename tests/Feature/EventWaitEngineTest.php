<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Jobs\ProcessEventOccurrence;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunEventWait;

class EventWaitEngineTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_correlated_event_resumes_the_run_and_wrong_value_does_not(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'patient-42',
        ]);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $this->makeAutomation(
            $workspace,
            $trigger->name,
            $this->eventWaitGraph($completed->id, matchRules: [[
                'incoming_field' => 'appointment_id',
                'operator' => 'equals',
                'source' => 'trigger_event',
                'source_field' => 'appointment_id',
            ]])
        );

        $headers = $this->authHeaders($workspace, $key);
        $this->postJson('/api/v1/events', [
            'name' => $trigger->name,
            'person_id' => $person->external_id,
            'data' => ['appointment_id' => 'appt-100'],
        ], $headers)->assertAccepted();

        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::STATUS_WAITING_EVENT, $run->status);
        $this->assertSame('waiting', $run->steps()->sole()->status);

        $this->postJson('/api/v1/events', [
            'name' => $completed->name,
            'person_id' => $person->external_id,
            'data' => ['appointment_id' => 'appt-other'],
        ], $headers)->assertAccepted();
        $this->assertSame(AutomationRun::STATUS_WAITING_EVENT, $run->refresh()->status);

        $this->postJson('/api/v1/events', [
            'name' => $completed->name,
            'person_id' => $person->external_id,
            'data' => ['appointment_id' => 'appt-100'],
        ], $headers)->assertAccepted();

        $wait = RunEventWait::sole();
        $this->assertSame(RunEventWait::STATUS_MATCHED, $wait->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame('matched', $run->context['branch:wait']);
        $this->assertSame('appt-100', $run->context['wait_event:wait']['appointment_id']);
        $this->assertSame('completed', $run->steps()->where('node_id', 'wait')->sole()->status);
    }

    public function test_due_wait_takes_the_timeout_branch(): void
    {
        $this->freezeSecond();
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $this->makeAutomation($workspace, $trigger->name, $this->eventWaitGraph($completed->id, timeoutMinutes: 10));

        $this->postJson('/api/v1/events', [
            'name' => $trigger->name,
            'person_id' => $person->external_id,
        ], $this->authHeaders($workspace, $key))->assertAccepted();

        $run = AutomationRun::sole();
        $this->travel(11)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();

        $this->assertSame(RunEventWait::STATUS_TIMED_OUT, RunEventWait::sole()->status);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        $this->assertSame('timed_out', $run->context['branch:wait']);
    }

    public function test_occurrence_recorded_before_deadline_beats_a_delayed_queue_job(): void
    {
        $this->freezeSecond();
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $this->makeAutomation($workspace, $trigger->name, $this->eventWaitGraph($completed->id, timeoutMinutes: 10));

        $this->postJson('/api/v1/events', [
            'name' => $trigger->name,
            'person_id' => $person->external_id,
        ], $this->authHeaders($workspace, $key))->assertAccepted();

        $occurrence = EventOccurrence::create([
            'workspace_id' => $workspace->id,
            'event_id' => $completed->id,
            'person_id' => $person->id,
            'payload' => [],
            'occurred_at' => now()->addMinutes(9),
        ]);

        $this->travel(11)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();

        $wait = RunEventWait::sole();
        $this->assertSame(RunEventWait::STATUS_MATCHED, $wait->status);
        $this->assertSame($occurrence->id, $wait->matched_occurrence_id);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, AutomationRun::sole()->status);
    }

    public function test_replayed_occurrence_cannot_claim_a_wait_twice(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $this->makeAutomation($workspace, $trigger->name, $this->eventWaitGraph($completed->id));

        $headers = $this->authHeaders($workspace, $key);
        $this->postJson('/api/v1/events', ['name' => $trigger->name, 'person_id' => $person->external_id], $headers);
        $this->postJson('/api/v1/events', ['name' => $completed->name, 'person_id' => $person->external_id], $headers);
        $occurrence = EventOccurrence::query()->where('event_id', $completed->id)->sole();

        (new ProcessEventOccurrence($occurrence->id))->handle();

        $this->assertSame(1, RunEventWait::count());
        $this->assertSame($occurrence->id, RunEventWait::sole()->matched_occurrence_id);
        $this->assertSame(2, AutomationRun::sole()->steps()->count()); // wait + exit
    }

    public function test_one_active_policy_treats_an_event_wait_as_active(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'patient-42']);
        $trigger = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_booked']);
        $completed = Event::create(['workspace_id' => $workspace->id, 'name' => 'appointment_completed']);
        $this->makeAutomation(
            $workspace,
            $trigger->name,
            $this->eventWaitGraph($completed->id),
            Automation::REENTRY_ONE_ACTIVE,
        );

        $headers = $this->authHeaders($workspace, $key);
        $payload = ['name' => $trigger->name, 'person_id' => $person->external_id];
        $this->postJson('/api/v1/events', $payload, $headers);
        $this->postJson('/api/v1/events', $payload, $headers);

        $this->assertSame(1, AutomationRun::count());
        $this->assertSame(AutomationRun::STATUS_WAITING_EVENT, AutomationRun::sole()->status);
    }

    private function eventWaitGraph(int $eventId, int $timeoutMinutes = 60, array $matchRules = []): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'wait_for_event', 'config' => [
                    'event_id' => $eventId,
                    'timeout_minutes' => $timeoutMinutes,
                    'match_rules' => $matchRules,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'done', 'branch' => 'matched'],
                ['from' => 'wait', 'to' => 'done', 'branch' => 'timed_out'],
            ],
        ];
    }
}
