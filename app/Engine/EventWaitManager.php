<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Jobs\AdvanceAutomationRun;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\RunEventWait;
use TriggerEngage\Server\Models\RunStep;

class EventWaitManager
{
    public function __construct(protected EventMatchEvaluator $matches) {}

    /**
     * Park a run on an event wait, then scan past the registration cursor.
     * The scan closes the small race where an occurrence commits while the
     * wait registration transaction is still in flight.
     */
    public function register(AutomationRun $run, array $node): void
    {
        $waitId = DB::transaction(function () use ($run, $node): ?int {
            $lockedRun = AutomationRun::query()->lockForUpdate()->findOrFail($run->id);

            if ($lockedRun->status !== AutomationRun::STATUS_RUNNING) {
                return null;
            }
            $existing = RunEventWait::query()
                ->where('automation_run_id', $lockedRun->id)
                ->where('node_id', $node['id'])
                ->first();

            if ($existing) {
                return $existing->id;
            }

            $eventId = (int) ($node['config']['event_id'] ?? 0);
            $expiresAt = now()
                ->addDays((int) ($node['config']['timeout_days'] ?? 0))
                ->addHours((int) ($node['config']['timeout_hours'] ?? 0))
                ->addMinutes((int) ($node['config']['timeout_minutes'] ?? 0));
            $cursor = (int) EventOccurrence::query()
                ->where('workspace_id', $lockedRun->workspace_id)
                ->max('id');

            $wait = RunEventWait::query()->create([
                'workspace_id' => $lockedRun->workspace_id,
                'automation_run_id' => $lockedRun->id,
                'person_id' => $lockedRun->person_id,
                'event_id' => $eventId,
                'node_id' => $node['id'],
                'status' => RunEventWait::STATUS_WAITING,
                'match_rules' => $node['config']['match_rules'] ?? [],
                'occurrence_cursor' => $cursor,
                'expires_at' => $expiresAt,
            ]);

            RunStep::query()->firstOrCreate(
                ['automation_run_id' => $lockedRun->id, 'node_id' => $node['id']],
                [
                    'type' => 'wait_for_event',
                    'status' => 'waiting',
                    'output' => [
                        'event_id' => $eventId,
                        'expires_at' => $expiresAt->toIso8601String(),
                    ],
                    'executed_at' => now(),
                ]
            );

            $lockedRun->update([
                'current_node_id' => $node['id'],
                'status' => AutomationRun::STATUS_WAITING_EVENT,
                'wake_at' => $expiresAt,
            ]);

            return $wait->id;
        });

        if ($waitId) {
            $this->matchExistingCandidates($waitId);
        }
    }

    public function matchOccurrence(EventOccurrence $occurrence): int
    {
        if (! $occurrence->person_id) {
            return 0;
        }

        $matched = 0;
        $waitIds = RunEventWait::query()
            ->where('workspace_id', $occurrence->workspace_id)
            ->where('person_id', $occurrence->person_id)
            ->where('event_id', $occurrence->event_id)
            ->where('status', RunEventWait::STATUS_WAITING)
            ->where('occurrence_cursor', '<', $occurrence->id)
            ->pluck('id');

        foreach ($waitIds as $waitId) {
            $matched += $this->claimMatch((int) $waitId, $occurrence->id) ? 1 : 0;
        }

        return $matched;
    }

    /**
     * Resolve one due wait. A qualifying occurrence recorded before the
     * deadline wins even when its queue job was delayed until after expiry.
     */
    public function resolveTimeout(int $waitId): ?string
    {
        return DB::transaction(function () use ($waitId): ?string {
            $waitPointer = RunEventWait::query()->find($waitId);

            if (! $waitPointer) {
                return null;
            }

            $run = AutomationRun::query()
                ->with('occurrence', 'person')
                ->lockForUpdate()
                ->find($waitPointer->automation_run_id);
            $wait = RunEventWait::query()->lockForUpdate()->find($waitId);

            if ($wait && $run) {
                $wait->setRelation('run', $run);
            }

            if (! $wait || ! $run || $wait->status !== RunEventWait::STATUS_WAITING || $wait->expires_at->isFuture()) {
                return null;
            }

            if ($wait->run->status !== AutomationRun::STATUS_WAITING_EVENT) {
                $wait->update(['status' => RunEventWait::STATUS_CANCELLED]);

                return RunEventWait::STATUS_CANCELLED;
            }

            $candidate = $this->candidateQuery($wait)
                ->where('occurred_at', '<=', $wait->expires_at)
                ->get()
                ->first(fn (EventOccurrence $occurrence) => $this->matches->passes(
                    $wait->run,
                    $occurrence,
                    $wait->match_rules ?? [],
                ));

            if ($candidate) {
                $this->completeMatch($wait, $candidate);

                return RunEventWait::STATUS_MATCHED;
            }

            $wait->update([
                'status' => RunEventWait::STATUS_TIMED_OUT,
                'timed_out_at' => now(),
            ]);
            $this->completeStep($run->id, $wait->node_id, [
                'result' => RunEventWait::STATUS_TIMED_OUT,
                'timed_out_at' => now()->toIso8601String(),
            ]);
            $this->resumeRun($run, $wait->node_id, RunEventWait::STATUS_TIMED_OUT);

            return RunEventWait::STATUS_TIMED_OUT;
        });
    }

    protected function matchExistingCandidates(int $waitId): void
    {
        $wait = RunEventWait::query()->find($waitId);

        if (! $wait || $wait->status !== RunEventWait::STATUS_WAITING) {
            return;
        }

        foreach ($this->candidateQuery($wait)->where('occurred_at', '<=', $wait->expires_at)->pluck('id') as $occurrenceId) {
            if ($this->claimMatch($wait->id, (int) $occurrenceId)) {
                return;
            }
        }
    }

    protected function claimMatch(int $waitId, int $occurrenceId): bool
    {
        return DB::transaction(function () use ($waitId, $occurrenceId): bool {
            $waitPointer = RunEventWait::query()->find($waitId);

            if (! $waitPointer) {
                return false;
            }

            $run = AutomationRun::query()
                ->with('occurrence', 'person')
                ->lockForUpdate()
                ->find($waitPointer->automation_run_id);
            $wait = RunEventWait::query()->lockForUpdate()->find($waitId);
            $occurrence = EventOccurrence::query()->find($occurrenceId);

            if ($wait && $run) {
                $wait->setRelation('run', $run);
            }

            if (! $wait
                || ! $run
                || ! $occurrence
                || $wait->status !== RunEventWait::STATUS_WAITING
                || $wait->run->status !== AutomationRun::STATUS_WAITING_EVENT
                || $occurrence->id <= $wait->occurrence_cursor
                || $occurrence->workspace_id !== $wait->workspace_id
                || $occurrence->person_id !== $wait->person_id
                || $occurrence->event_id !== $wait->event_id
                || $occurrence->occurred_at->isBefore($wait->created_at)
                || $occurrence->occurred_at->isAfter($wait->expires_at)
                || ! $this->matches->passes($wait->run, $occurrence, $wait->match_rules ?? [])) {
                return false;
            }

            $this->completeMatch($wait, $occurrence);

            return true;
        });
    }

    protected function completeMatch(RunEventWait $wait, EventOccurrence $occurrence): void
    {
        $run = $wait->run;
        $wait->update([
            'status' => RunEventWait::STATUS_MATCHED,
            'matched_occurrence_id' => $occurrence->id,
            'matched_at' => now(),
        ]);
        $this->completeStep($run->id, $wait->node_id, [
            'result' => RunEventWait::STATUS_MATCHED,
            'matched_occurrence_id' => $occurrence->id,
            'matched_at' => now()->toIso8601String(),
        ]);
        $this->resumeRun($run, $wait->node_id, RunEventWait::STATUS_MATCHED, $occurrence);
    }

    protected function completeStep(int $runId, string $nodeId, array $output): void
    {
        $step = RunStep::query()
            ->where('automation_run_id', $runId)
            ->where('node_id', $nodeId)
            ->firstOrFail();

        $step->update([
            'status' => 'completed',
            'output' => array_merge($step->output ?? [], $output),
        ]);
    }

    protected function resumeRun(
        AutomationRun $run,
        string $nodeId,
        string $branch,
        ?EventOccurrence $occurrence = null,
    ): void {
        $context = array_merge($run->context ?? [], ['branch:'.$nodeId => $branch]);

        if ($occurrence) {
            $context['wait_event:'.$nodeId] = $occurrence->payload ?? [];
        }

        $run->update([
            'status' => AutomationRun::STATUS_RUNNING,
            'wake_at' => null,
            'context' => $context,
        ]);

        AdvanceAutomationRun::dispatch($run->id)->afterCommit();
    }

    protected function candidateQuery(RunEventWait $wait)
    {
        return EventOccurrence::query()
            ->where('workspace_id', $wait->workspace_id)
            ->where('person_id', $wait->person_id)
            ->where('event_id', $wait->event_id)
            ->where('id', '>', $wait->occurrence_cursor)
            ->where('occurred_at', '>=', $wait->created_at)
            ->orderBy('id');
    }
}
