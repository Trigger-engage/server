<?php

namespace App\Console\Commands;

use App\Engine\EventWaitManager;
use App\Jobs\AdvanceAutomationRun;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\RunEventWait;
use App\Models\RunGoalSubscription;
use App\Models\RunStep;
use Illuminate\Console\Command;

class EngageTick extends Command
{
    protected $signature = 'engage:tick';

    protected $description = 'Wake automation runs whose delay has elapsed';

    public function handle(EventWaitManager $eventWaits): int
    {
        $this->recoverStaleSendReservations();
        $this->cancelFinishedGoalSubscriptions();

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

        $this->info("Woke {$due->count()} delayed run(s) and resolved {$dueEventWaits->count()} event wait(s).");

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
