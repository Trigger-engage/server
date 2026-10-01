<?php

namespace Tests\Feature;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\RunEngine;
use TriggerEngage\Server\Jobs\AdvanceAutomationRun;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Workspace;

/**
 * A run is `running` only while a worker walks it. When its AdvanceAutomationRun
 * job is lost — refused its WithoutOverlapping lock by a cache store that drops
 * writes (Mytherapist.ng production, Oct 2026: thousands of runs, nothing sent),
 * dropped by the queue, or killed with its worker — engage:tick has to pick it
 * up again, and must never resend, double-walk, or send days late.
 */
class StalledRunRecoveryTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected Workspace $workspace;

    protected string $key;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->freezeSecond();

        [$this->workspace, $this->key] = $this->makeWorkspace();
        $this->makeAutomation($this->workspace, 'customer_sign_up', $this->linearEmailGraph(
            $this->makeEmailTemplate($this->workspace),
            $this->makeLogEmailChannel($this->workspace),
        ));
    }

    public function test_a_run_whose_advance_job_was_dropped_sends_once_the_store_grants_locks(): void
    {
        $this->refuseCacheLocks();
        $run = $this->signUp('user-1');

        $this->assertSame([AutomationRun::STATUS_RUNNING, 'trigger'], [$run->status, $run->current_node_id], 'The dropped job leaves the run on its trigger.');
        Mail::assertNothingSent();

        $this->travel(16)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();
        $this->assertSame('trigger', $run->refresh()->current_node_id, 'While the store still refuses locks, the retry is dropped too, harmlessly.');

        $this->grantCacheLocks();
        $this->travel(1)->minutes();
        $this->artisan('engage:tick')
            ->expectsOutputToContain('resumed 1 stalled run(s)')
            ->assertSuccessful();

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);
        Mail::assertSent(TemplatedMail::class, 1);
    }

    public function test_a_run_is_only_resumed_once_it_has_sat_idle_past_the_lock_expiry(): void
    {
        $this->refuseCacheLocks();
        $run = $this->signUp('user-1');
        $this->grantCacheLocks();
        Queue::fake();

        $this->travel(14)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->travel(1)->minutes();
        $this->artisan('engage:tick')->assertSuccessful();
        Queue::assertPushed(AdvanceAutomationRun::class, fn (AdvanceAutomationRun $job) => $job->runId === $run->id);
    }

    public function test_a_run_with_a_send_in_flight_is_left_to_the_send_reconciliation(): void
    {
        $this->refuseCacheLocks();
        $run = $this->signUp('user-1');
        $this->grantCacheLocks();
        $this->travel(20)->minutes();

        // A worker reserved the send a moment ago and may still be talking to
        // the provider; only the message ledger can say whether it went out.
        RunStep::create([
            'automation_run_id' => $run->id,
            'node_id' => 'send',
            'type' => 'send_email',
            'status' => 'processing',
            'executed_at' => now(),
        ]);
        Queue::fake();

        $this->artisan('engage:tick')->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(AutomationRun::STATUS_RUNNING, $run->refresh()->status);
    }

    public function test_a_run_stalled_past_the_give_up_window_fails_with_a_reason_instead_of_sending_late(): void
    {
        $this->refuseCacheLocks();
        $run = $this->signUp('user-1');
        $this->grantCacheLocks();

        $this->travel(73)->hours();
        $this->artisan('engage:tick')
            ->expectsOutputToContain('gave up on 1')
            ->assertSuccessful();

        $run->refresh();
        $this->assertSame(AutomationRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Stalled for more than 72 hours', $run->context['failure'] ?? '');
        Mail::assertNothingSent();
    }

    public function test_resumes_are_capped_per_tick(): void
    {
        config(['trigger-engage-server.stalled_runs.per_tick' => 1]);
        $this->refuseCacheLocks();
        $this->signUp('user-1');
        $this->signUp('user-2');
        $this->grantCacheLocks();
        $this->travel(16)->minutes();
        Queue::fake();

        $this->artisan('engage:tick')->assertSuccessful();

        Queue::assertPushed(AdvanceAutomationRun::class, 1);
    }

    public function test_a_run_that_throws_is_failed_with_its_reason_and_the_tick_carries_on(): void
    {
        $this->refuseCacheLocks();
        $broken = $this->signUp('user-1');
        $healthy = $this->signUp('user-2');
        $this->grantCacheLocks();
        $this->travel(16)->minutes();

        $real = $this->app->make(RunEngine::class);
        $this->app->instance(RunEngine::class, new class($real, $broken->id) extends RunEngine
        {
            public function __construct(private RunEngine $real, private int $brokenRunId) {}

            public function advance(AutomationRun $run): void
            {
                if ($run->id === $this->brokenRunId) {
                    throw new RuntimeException('Template 41 no longer exists');
                }

                $this->real->advance($run);
            }
        });

        // The sync queue runs the job inline and rethrows; the tick must not
        // stop at the first broken run.
        $this->artisan('engage:tick')->assertSuccessful();

        $broken->refresh();
        $this->assertSame(AutomationRun::STATUS_FAILED, $broken->status);
        $this->assertSame('Advancing the run failed: Template 41 no longer exists', $broken->context['failure'] ?? null);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $healthy->refresh()->status);
        Mail::assertSent(TemplatedMail::class, 1);
    }

    // ------------------------------------------------------------------

    protected function signUp(string $personId): AutomationRun
    {
        $headers = $this->authHeaders($this->workspace, $this->key);
        $this->putJson("/api/v1/people/{$personId}", [
            'attributes' => ['email' => "{$personId}@example.com", 'first_name' => 'Ada'],
        ], $headers)->assertOk();
        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => $personId,
            'data' => ['plan' => 'free'],
        ], $headers)->assertStatus(202);

        return AutomationRun::query()->latest('id')->firstOrFail();
    }

    /**
     * A default store that grants no lock and says nothing about it, like a
     * store dropping writes. The job middleware resolves the store from the
     * container, so the bound `cache.store` singleton is reset as well.
     */
    protected function refuseCacheLocks(): void
    {
        $store = new class extends ArrayStore
        {
            public function lock($name, $seconds = 0, $owner = null)
            {
                return new class($name, $seconds) extends Lock
                {
                    public function acquire()
                    {
                        return false;
                    }

                    public function release()
                    {
                        return false;
                    }

                    public function forceRelease() {}

                    protected function getCurrentOwner()
                    {
                        return '';
                    }
                };
            }
        };

        Cache::extend('refuses_locks', fn () => Cache::repository($store));
        config([
            'cache.stores.refuses_locks' => ['driver' => 'refuses_locks'],
            'cache.default' => 'refuses_locks',
        ]);
        $this->app->forgetInstance('cache.store');
    }

    protected function grantCacheLocks(): void
    {
        config(['cache.default' => 'array']);
        $this->app->forgetInstance('cache.store');
    }
}
