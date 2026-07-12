<?php

namespace TriggerEngage\Server\Console\Commands;

use Illuminate\Console\Command;
use TriggerEngage\Server\Engine\EventWaitManager;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Jobs\AdvanceAutomationRun;
use TriggerEngage\Server\Models\AutomationRun;
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
        $this->cancelFinishedGoalSubscriptions();

        // Behavioural audiences whose conditions are time-bound (e.g. "inactive
        // for 14 days") drift purely as time passes, so sweep stale ones here.
        $recomputedSegments = $segments->recomputeStale();

        $due = AutomationRun::query()
            ->where('status', AutomationRun::STATUS_WAITING)
            ->where('wake_at', '<=', now())
            ->pluck('id');

        foreach ($due as $runId) {
            AdvanceAutomationRun::dispatch($runId);
        }

        $dueEventWaits = RunEventWait::query()
            ->where('status', RunEventWait::STATUS_WAITING)
            ->where('expires_at', '<=', now())
            ->pluck('id');

        foreach ($dueEventWaits as $waitId) {
            $eventWaits->resolveTimeout((int) $waitId);
        }

        $this->info("Woke {$due->count()} delayed run(s), resolved {$dueEventWaits->count()} event wait(s), recomputed {$recomputedSegments} rule segment(s).");

        return self::SUCCESS;
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
