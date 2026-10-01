<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;
use TriggerEngage\Server\Engine\RunEngine;
use TriggerEngage\Server\Models\AutomationRun;

class AdvanceAutomationRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $runId) {}

    public function handle(RunEngine $engine): void
    {
        $run = AutomationRun::query()->find($this->runId);

        if ($run) {
            $engine->advance($run);
        }
    }

    /**
     * The queue has given up: every try is used, or, on the sync queue, the
     * first exception. Fail the run with the reason; left `running`, it would
     * look busy forever and engage:tick would keep re-dispatching it.
     */
    public function failed(?Throwable $exception = null): void
    {
        $run = AutomationRun::query()->find($this->runId);

        if (! $run || $run->status !== AutomationRun::STATUS_RUNNING) {
            return;
        }

        AutomationRun::query()
            ->whereKey($run->id)
            ->where('status', AutomationRun::STATUS_RUNNING)
            ->update([
                'status' => AutomationRun::STATUS_FAILED,
                'wake_at' => null,
                'context' => array_merge($run->context ?? [], [
                    'failure' => 'Advancing the run failed: '.Str::limit($exception?->getMessage() ?: 'no reason given', 500),
                ]),
            ]);
    }

    /**
     * One walker per run. A refused lock drops this job (dontRelease), so
     * engage:tick re-dispatches a run left `running` once it has sat idle
     * past the lock's expiry: a dead lock store delays runs instead of
     * stranding them.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-run:'.$this->runId))
                ->expireAfter(600)
                ->dontRelease(),
        ];
    }
}
