<?php

namespace App\Jobs;

use App\Engine\RunEngine;
use App\Models\AutomationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class AdvanceAutomationRun implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $runId)
    {
    }

    public function handle(RunEngine $engine): void
    {
        $run = AutomationRun::query()->find($this->runId);

        if ($run) {
            $engine->advance($run);
        }
    }
}
