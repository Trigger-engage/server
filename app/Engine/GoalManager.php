<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\RunEventWait;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\RunStep;

class GoalManager
{
    public function __construct(protected EventMatchEvaluator $matches) {}

    /** @return array<int, int> */
    public function register(AutomationRun $run, array $goals): array
    {
        $cursor = (int) EventOccurrence::query()
            ->where('workspace_id', $run->workspace_id)
            ->max('id');

        return collect($goals)
            ->map(function (array $goal) use ($run, $cursor): int {
                return RunGoalSubscription::query()->firstOrCreate(
                    [
                        'automation_run_id' => $run->id,
                        'goal_id' => $goal['id'],
                    ],
                    [
                        'workspace_id' => $run->workspace_id,
                        'person_id' => $run->person_id,
                        'event_id' => (int) $goal['event_id'],
                        'status' => RunGoalSubscription::STATUS_ACTIVE,
                        'match_rules' => $goal['match_rules'] ?? [],
                        'occurrence_cursor' => $cursor,
                    ]
                )->id;
            })
            ->all();
    }

    public function matchOccurrence(EventOccurrence $occurrence): int
    {
        if (! $occurrence->person_id) {
            return 0;
        }

        $reached = 0;
        $subscriptionIds = RunGoalSubscription::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('person_id', $occurrence->person_id)
            ->where('event_id', $occurrence->event_id)
            ->where('status', RunGoalSubscription::STATUS_ACTIVE)
            ->where('occurrence_cursor', '<', $occurrence->id)
            ->pluck('id');

        foreach ($subscriptionIds as $subscriptionId) {
            $reached += $this->claim((int) $subscriptionId, $occurrence->id) ? 1 : 0;
        }

        return $reached;
    }

    public function catchUp(int $subscriptionId): void
    {
        $subscription = RunGoalSubscription::query()->find($subscriptionId);

        if (! $subscription || $subscription->status !== RunGoalSubscription::STATUS_ACTIVE) {
            return;
        }

        $occurrenceIds = EventOccurrence::query()
            ->where('workspace_id', $subscription->workspace_id)
            ->where('person_id', $subscription->person_id)
            ->where('event_id', $subscription->event_id)
            ->where('id', '>', $subscription->occurrence_cursor)
            ->where('occurred_at', '>=', $subscription->created_at)
            ->orderBy('id')
            ->pluck('id');

        foreach ($occurrenceIds as $occurrenceId) {
            if ($this->claim($subscription->id, (int) $occurrenceId)) {
                return;
            }
        }
    }

    protected function claim(int $subscriptionId, int $occurrenceId): bool
    {
        return DB::transaction(function () use ($subscriptionId, $occurrenceId): bool {
            $subscriptionPointer = RunGoalSubscription::query()->find($subscriptionId);

            if (! $subscriptionPointer) {
                return false;
            }

            // All run state transitions lock the run before their child
            // ledger row. This keeps goal, wait, and send races deadlock-safe.
            $run = AutomationRun::query()
                ->with('occurrence', 'person')
                ->lockForUpdate()
                ->find($subscriptionPointer->automation_run_id);
            $subscription = RunGoalSubscription::query()->lockForUpdate()->find($subscriptionId);
            $occurrence = EventOccurrence::query()->find($occurrenceId);

            if ($subscription && $run) {
                $subscription->setRelation('run', $run);
            }

            if (! $subscription
                || ! $occurrence
                || ! $run
                || $subscription->status !== RunGoalSubscription::STATUS_ACTIVE
                || ! in_array($subscription->run->status, AutomationRun::activeStatuses(), true)
                || $occurrence->id <= $subscription->occurrence_cursor
                || $occurrence->workspace_id !== $subscription->workspace_id
                || $occurrence->person_id !== $subscription->person_id
                || $occurrence->event_id !== $subscription->event_id
                || $occurrence->occurred_at->isBefore($subscription->created_at)
                || ! $this->matches->passes($subscription->run, $occurrence, $subscription->match_rules ?? [])) {
                return false;
            }

            $this->complete($subscription, $occurrence);

            return true;
        });
    }

    protected function complete(RunGoalSubscription $subscription, EventOccurrence $occurrence): void
    {
        $run = $subscription->run;
        $subscription->update([
            'status' => RunGoalSubscription::STATUS_REACHED,
            'reached_occurrence_id' => $occurrence->id,
            'reached_at' => now(),
        ]);

        RunGoalSubscription::query()
            ->where('automation_run_id', $run->id)
            ->where('id', '!=', $subscription->id)
            ->where('status', RunGoalSubscription::STATUS_ACTIVE)
            ->update(['status' => RunGoalSubscription::STATUS_CANCELLED, 'cancelled_at' => now()]);

        RunEventWait::query()
            ->where('automation_run_id', $run->id)
            ->where('status', RunEventWait::STATUS_WAITING)
            ->update(['status' => RunEventWait::STATUS_CANCELLED]);

        RunStep::query()
            ->where('automation_run_id', $run->id)
            ->whereIn('status', ['waiting', 'retrying'])
            ->update([
                'status' => 'skipped',
                'error' => 'Cancelled because the automation goal was reached.',
                'next_attempt_at' => null,
            ]);

        RunStep::query()->firstOrCreate(
            ['automation_run_id' => $run->id, 'node_id' => 'goal:'.$subscription->goal_id],
            [
                'type' => 'goal',
                'status' => 'completed',
                'output' => [
                    'event_id' => $occurrence->event_id,
                    'occurrence_id' => $occurrence->id,
                    'payload' => $occurrence->payload ?? [],
                    'reached_at' => now()->toIso8601String(),
                ],
                'executed_at' => now(),
            ]
        );

        $run->update([
            'status' => AutomationRun::STATUS_COMPLETED,
            'wake_at' => null,
            'context' => array_merge($run->context ?? [], [
                'goal_reached' => true,
                'goal' => [
                    'id' => $subscription->goal_id,
                    'event_id' => $occurrence->event_id,
                    'occurrence_id' => $occurrence->id,
                    'payload' => $occurrence->payload ?? [],
                ],
            ]),
        ]);
    }
}
