<?php

namespace App\Console\Commands;

use App\Jobs\AdvanceAutomationRun;
use App\Models\AutomationRun;
use Illuminate\Console\Command;

class EngageTick extends Command
{
    protected $signature = 'engage:tick';

    protected $description = 'Wake automation runs whose delay has elapsed';

    public function handle(): int
    {
        $due = AutomationRun::query()
            ->where('status', AutomationRun::STATUS_WAITING)
            ->where('wake_at', '<=', now())
            ->pluck('id');

        foreach ($due as $runId) {
            AdvanceAutomationRun::dispatch($runId);
        }

        $this->info("Woke {$due->count()} run(s).");

        return self::SUCCESS;
    }
}
