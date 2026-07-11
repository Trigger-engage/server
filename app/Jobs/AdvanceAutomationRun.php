<?php

namespace App\Jobs;

use App\Engine\RunEngine;
use App\Models\AutomationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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
