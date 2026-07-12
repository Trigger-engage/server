<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automation-run:'.$this->runId))
                ->expireAfter(600)
                ->dontRelease(),
        ];
    }
}
