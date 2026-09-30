<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\AutomationRun;

/**
 * An until-time delay names a wall-clock time in the workspace's timezone.
 * The engine stores wake_at and compares it against now() in the HOST app's
 * timezone, so the two must be reconciled with whatever config('app.timezone')
 * is, not with UTC. Found by running the Mytherapist.ng journeys (host on
 * Africa/Lagos) end to end: every 10:00 email and 12:30 push left at 09:00
 * and 11:30.
 */
class UntilTimeHostTimezoneTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);

        parent::tearDown();
    }

    public function test_until_time_wakes_at_the_workspace_wall_clock_on_a_non_utc_host(): void
    {
        // A host app on Africa/Lagos (UTC+1): now() and every stored timestamp are Lagos wall clock.
        config(['app.timezone' => 'Africa/Lagos']);
        date_default_timezone_set('Africa/Lagos');
        $this->travelTo(Carbon::parse('2026-10-05 09:15:00', 'Africa/Lagos'));

        $run = $this->runParkedOnUntil('Africa/Lagos', '12:30');

        $this->assertSame('2026-10-05 12:30', $run->wake_at->format('Y-m-d H:i'), 'A 12:30 Lagos target on a Lagos host is 12:30, not 11:30.');

        $this->travelTo(Carbon::parse('2026-10-05 11:30:00', 'Africa/Lagos'));
        $this->artisan('engage:tick')->assertSuccessful();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->fresh()->status, 'The scheduler must not wake it an hour early.');

        $this->travelTo(Carbon::parse('2026-10-05 12:30:00', 'Africa/Lagos'));
        $this->artisan('engage:tick')->assertSuccessful();
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->fresh()->status);
    }

    public function test_until_time_still_maps_a_foreign_workspace_timezone_onto_a_utc_host(): void
    {
        $this->travelTo(Carbon::parse('2026-10-05 09:15:00', 'UTC'));

        $run = $this->runParkedOnUntil('Africa/Lagos', '12:30');

        $this->assertSame('2026-10-05 11:30', $run->wake_at->format('Y-m-d H:i'), '12:30 in Lagos is 11:30 on a UTC host.');
    }

    public function test_an_until_time_already_past_today_targets_tomorrow(): void
    {
        config(['app.timezone' => 'Africa/Lagos']);
        date_default_timezone_set('Africa/Lagos');
        $this->travelTo(Carbon::parse('2026-10-05 13:00:00', 'Africa/Lagos'));

        $run = $this->runParkedOnUntil('Africa/Lagos', '12:30');

        $this->assertSame('2026-10-06 12:30', $run->wake_at->format('Y-m-d H:i'));
    }

    protected function runParkedOnUntil(string $workspaceTimezone, string $until): AutomationRun
    {
        [$workspace, $key] = $this->makeWorkspace();
        $workspace->forceFill(['timezone' => $workspaceTimezone])->save();

        $this->makeAutomation($workspace, 'customer_sign_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'at', 'type' => 'delay', 'config' => ['until_time' => $until]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'at'],
                ['from' => 'at', 'to' => 'done'],
            ],
        ]);

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
        ], $this->authHeaders($workspace, $key))->assertAccepted();

        $run = AutomationRun::query()->latest('id')->firstOrFail();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertSame('at', $run->current_node_id);

        return $run;
    }
}
