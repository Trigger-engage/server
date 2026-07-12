<?php

namespace TriggerEngage\Server\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Engine\EventWaitManager;
use TriggerEngage\Server\Engine\GoalManager;
use TriggerEngage\Server\Engine\Graph;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;

/**
 * Fan an event occurrence out to every active automation it triggers,
 * honouring each automation's re-entry policy.
 */
class ProcessEventOccurrence implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 5;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $occurrenceId) {}

    public function handle(): void
    {
        $eventWaits = app(EventWaitManager::class);
        $goals = app(GoalManager::class);
        $segments = app(SegmentManager::class);
        $occurrence = EventOccurrence::query()->with('person')->find($this->occurrenceId);

        // Automations message a person; an anonymous occurrence is data-only.
        if (! $occurrence || ! $occurrence->person) {
            return;
        }

        $segments->matchOccurrence($occurrence);
        // The new event may also change this person's eligibility for rule-based
        // (behavioural) audiences — re-evaluate them for that person only.
        $segments->syncPersonRuleSegments($occurrence->person);

        // A goal stops an existing run before the same occurrence is offered
        // to node-level waits or used to start a new automation run.
        $goals->matchOccurrence($occurrence);
        $eventWaits->matchOccurrence($occurrence);

        $automationIds = Automation::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('status', 'active')
            ->where('trigger_event_id', $occurrence->event_id)
            ->whereNotNull('active_version_id')
            ->pluck('id');

        foreach ($automationIds as $automationId) {
            $goalSubscriptionIds = DB::transaction(function () use ($automationId, $occurrence, $goals): array {
                // Serialize matching per automation so simultaneous events
                // cannot race a one-active/once-ever re-entry check.
                $automation = Automation::query()->lockForUpdate()->find($automationId);

                if (! $automation
                    || $automation->status !== 'active'
                    || ! $automation->active_version_id
                    || ! $this->allowedByReentryPolicy($automation, $occurrence)) {
                    return [];
                }

                $version = $automation->activeVersion;
                $graph = new Graph($version->graph);
                $trigger = $graph->triggerNode();

                if (! $trigger) {
                    return [];
                }

                $run = AutomationRun::query()->firstOrCreate(
                    [
                        'automation_id' => $automation->id,
                        'event_occurrence_id' => $occurrence->id,
                    ],
                    [
                        'workspace_id' => $occurrence->workspace_id,
                        'automation_version_id' => $version->id,
                        'person_id' => $occurrence->person_id,
                        'status' => AutomationRun::STATUS_RUNNING,
                        'current_node_id' => $trigger['id'],
                    ]
                );

                if ($run->wasRecentlyCreated) {
                    $goalSubscriptionIds = $goals->register($run, $graph->goals());
                    AdvanceAutomationRun::dispatch($run->id)->afterCommit();

                    return $goalSubscriptionIds;
                }

                return [];
            });

            foreach ($goalSubscriptionIds as $subscriptionId) {
                $goals->catchUp($subscriptionId);
            }
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
                ->whereIn('status', AutomationRun::activeStatuses())
                ->exists(),
            default => true,
        };
    }
}
