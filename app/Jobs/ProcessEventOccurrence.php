<?php

namespace App\Jobs;

use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\EventOccurrence;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Fan an event occurrence out to every active automation it triggers,
 * honouring each automation's re-entry policy.
 */
class ProcessEventOccurrence implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $occurrenceId)
    {
    }

    public function handle(): void
    {
        $occurrence = EventOccurrence::query()->with('person')->find($this->occurrenceId);

        // Automations message a person; an anonymous occurrence is data-only.
        if (! $occurrence || ! $occurrence->person) {
            return;
        }

        $automations = Automation::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('status', 'active')
            ->where('trigger_event_id', $occurrence->event_id)
            ->whereNotNull('active_version_id')
            ->get();

        foreach ($automations as $automation) {
            if (! $this->allowedByReentryPolicy($automation, $occurrence)) {
                continue;
            }

            $version = $automation->activeVersion;
            $graph = new \App\Engine\Graph($version->graph);
            $trigger = $graph->triggerNode();

            if (! $trigger) {
                continue;
            }

            $run = AutomationRun::create([
                'workspace_id' => $occurrence->workspace_id,
                'automation_id' => $automation->id,
                'automation_version_id' => $version->id,
                'person_id' => $occurrence->person_id,
                'event_occurrence_id' => $occurrence->id,
                'status' => AutomationRun::STATUS_RUNNING,
                'current_node_id' => $trigger['id'],
            ]);

            AdvanceAutomationRun::dispatch($run->id);
        }
    }

    protected function allowedByReentryPolicy(Automation $automation, EventOccurrence $occurrence): bool
    {
        $runs = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('person_id', $occurrence->person_id);

        return match ($automation->reentry_policy) {
            Automation::REENTRY_ONCE_EVER => ! $runs->exists(),
            Automation::REENTRY_ONE_ACTIVE => ! $runs
                ->whereIn('status', [AutomationRun::STATUS_RUNNING, AutomationRun::STATUS_WAITING])
                ->exists(),
            default => true,
        };
    }
}
