<?php

namespace TriggerEngage\Server\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Bus;
use Throwable;
use TriggerEngage\Server\Engine\EventWaitManager;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Jobs\AdvanceAutomationRun;
use TriggerEngage\Server\Jobs\PollExpoPushReceipts;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\RunEventWait;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\RunStep;

class EngageTick extends Command
{
    protected $signature = 'engage:tick';

    protected $description = 'Wake automation runs whose delay has elapsed';

    public function handle(EventWaitManager $eventWaits, SegmentManager $segments): int
    {
        $this->recoverStaleSendReservations();
        [$redispatched, $abandoned] = $this->resumeStalledRuns();
        $this->cancelFinishedGoalSubscriptions();
        $this->collectExpoPushReceipts();

        // Behavioural audiences whose conditions are time-bound (e.g. "inactive
        // for 14 days") drift purely as time passes, so sweep stale ones here.
        $recomputedSegments = $segments->recomputeStale();

        $due = AutomationRun::query()
            ->where('status', AutomationRun::STATUS_WAITING)
            ->where('wake_at', '<=', now())
            ->pluck('id');

        foreach ($due as $runId) {
            $this->isolated(fn () => Bus::dispatch(new AdvanceAutomationRun($runId)));
        }

        $dueEventWaits = RunEventWait::query()
            ->where('status', RunEventWait::STATUS_WAITING)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        foreach ($dueEventWaits as $waitId) {
            $this->isolated(fn () => $eventWaits->resolveTimeout((int) $waitId));
        }

        $this->info("Woke {$due->count()} delayed run(s), resolved {$dueEventWaits->count()} event wait(s), re-dispatched {$redispatched} stalled run(s), gave up on {$abandoned}, recomputed {$recomputedSegments} rule segment(s).");

        return self::SUCCESS;
    }

    /**
     * A run is `running` only while a worker walks it, which takes seconds.
     * One still `running` long after that lost its AdvanceAutomationRun job:
     * the job's WithoutOverlapping lock was refused (a cache store that drops
     * writes refuses every lock, and the job does not release itself), the
     * queue lost it, or its worker died. Nothing else would ever touch it
     * again, because the due-run sweep only wakes `waiting` runs.
     *
     * Re-dispatching is safe: the job takes the same lock, so a run that is in
     * fact still moving keeps a single walker. Each attempt is claimed in
     * recovery_attempted_at and not repeated for the same idle period, so a
     * backed-up queue or a lock store still refusing locks does not pile up
     * duplicate jobs for the lowest ids while later runs wait their turn.
     * That column, not updated_at, records the attempts: updated_at must keep
     * saying when the run last moved. Runs with a send in flight are
     * left to recoverStaleSendReservations(), which checks the message ledger
     * before anything could send twice. A run stalled for longer than the
     * give-up window is failed with a reason instead: a "you didn't finish
     * booking" nudge three days late does more harm than none.
     *
     * @return array{0: int, 1: int} runs re-dispatched, runs given up on
     */
    protected function resumeStalledRuns(): array
    {
        $idleSince = now()->subMinutes(max(1, (int) config('trigger-engage-server.stalled_runs.after_minutes', 15)));
        $giveUpHours = max(1, (int) config('trigger-engage-server.stalled_runs.give_up_after_hours', 72));
        $giveUpBefore = now()->subHours($giveUpHours);
        $perTick = max(1, (int) config('trigger-engage-server.stalled_runs.per_tick', 500));

        $stalled = fn (): Builder => AutomationRun::query()
            ->where('status', AutomationRun::STATUS_RUNNING)
            ->where('updated_at', '<=', $idleSince)
            ->whereDoesntHave('steps', fn (Builder $steps) => $steps->where('status', 'processing'));

        $abandoned = 0;

        foreach ($stalled()->where('updated_at', '<', $giveUpBefore)->orderBy('id')->limit($perTick)->get(['id', 'context', 'updated_at']) as $run) {
            $abandoned += AutomationRun::query()
                ->whereKey($run->id)
                ->where('status', AutomationRun::STATUS_RUNNING)
                ->where('updated_at', $run->updated_at)
                ->update([
                    'status' => AutomationRun::STATUS_FAILED,
                    'context' => array_merge($run->context ?? [], [
                        'failure' => "Stalled for more than {$giveUpHours} hours after its advance job was lost, so it was stopped rather than sending late.",
                    ]),
                ]);
        }

        $notAttemptedSince = fn ($query) => $query
            ->whereNull('recovery_attempted_at')
            ->orWhere('recovery_attempted_at', '<=', $idleSince);
        $redispatched = 0;

        foreach ($stalled()->where('updated_at', '>=', $giveUpBefore)->where($notAttemptedSince)->orderBy('id')->limit($perTick)->pluck('id') as $runId) {
            // toBase(): an Eloquent update would also stamp updated_at.
            $claimed = AutomationRun::query()->toBase()
                ->where('id', $runId)
                ->where('status', AutomationRun::STATUS_RUNNING)
                ->where($notAttemptedSince)
                ->update(['recovery_attempted_at' => now()]);

            if ($claimed && $this->isolated(fn () => Bus::dispatch(new AdvanceAutomationRun($runId)))) {
                $redispatched++;
            }
        }

        return [$redispatched, $abandoned];
    }

    /**
     * On the sync queue a dispatched job runs inline and rethrows, so one run
     * with a broken graph would otherwise stop the tick for everyone after
     * it. The job's failed() hook has already failed that run.
     */
    protected function isolated(callable $work): bool
    {
        try {
            $work();

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /**
     * A worker can disappear after reserving a send. We only complete a stale
     * reservation when its message ledger says it was sent. Otherwise the run
     * fails for manual reconciliation; automatically retrying an ambiguous
     * SMTP handoff could deliver the same message twice.
     */
    protected function recoverStaleSendReservations(): void
    {
        RunStep::query()
            ->with('run')
            ->where('status', 'processing')
            ->where('updated_at', '<=', now()->subMinutes(15))
            ->each(function (RunStep $step): void {
                $message = Message::query()->where('run_step_id', $step->id)->first();

                if ($message?->status === 'sent') {
                    $step->update([
                        'status' => 'completed',
                        'output' => array_merge($step->output ?? [], ['message_id' => $message->id]),
                        'error' => null,
                    ]);

                    if ($step->run && in_array($step->run->status, AutomationRun::activeStatuses(), true)) {
                        $step->run->update([
                            'status' => AutomationRun::STATUS_RUNNING,
                            'current_node_id' => $step->node_id,
                            'wake_at' => null,
                        ]);
                        AdvanceAutomationRun::dispatch($step->run->id);
                    }

                    return;
                }

                $step->update([
                    'status' => 'failed',
                    'error' => 'Send worker stopped after provider dispatch began; not retried to prevent a duplicate.',
                ]);
                if ($step->run && in_array($step->run->status, AutomationRun::activeStatuses(), true)) {
                    $step->run->update([
                        'status' => AutomationRun::STATUS_FAILED,
                        'wake_at' => null,
                    ]);
                }
            });
    }

    /**
     * Expo publishes no delivery webhook, so the terminal state of a push has
     * to be pulled. Queue one poller per workspace that actually has an Expo
     * channel connected.
     */
    protected function collectExpoPushReceipts(): void
    {
        Channel::query()
            ->where('type', 'push')
            ->where('driver', 'expo')
            ->distinct()
            ->pluck('workspace_id')
            ->each(fn ($workspaceId) => PollExpoPushReceipts::dispatch((int) $workspaceId));
    }

    protected function cancelFinishedGoalSubscriptions(): void
    {
        RunGoalSubscription::query()
            ->where('status', RunGoalSubscription::STATUS_ACTIVE)
            ->whereHas('run', fn ($query) => $query->whereNotIn('status', AutomationRun::activeStatuses()))
            ->update([
                'status' => RunGoalSubscription::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ]);
    }
}
