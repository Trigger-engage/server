<?php

namespace TriggerEngage\Server\Mcp\Tools\Runs;

use TriggerEngage\Server\Engine\Graph;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\RunEventWait;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\RunStep;

class GetRun extends Tool
{
    public function name(): string
    {
        return 'get_run';
    }

    public function title(): string
    {
        return 'Get an automation run';
    }

    public function description(): string
    {
        return 'One run\'s timeline: the event that started it (with its payload), every step it reached in order with '
            .'its outcome, skip reason or error, retries and the message it produced, the event waits and goals it '
            .'registered, and what happens next (the step it is on, when it wakes, or why it is stuck).';
    }

    public function properties(): array
    {
        return ['run_id' => ['type' => 'integer']];
    }

    public function required(): array
    {
        return ['run_id'];
    }

    public function rules(): array
    {
        return ['run_id' => ['required', 'integer']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var AutomationRun $run */
        $run = $this->findOwned(AutomationRun::class, $arguments['run_id'], $context, 'run');
        $run->load(
            'automation:id,name,status',
            'version:id,automation_id,graph,published_at',
            'person',
            'occurrence.event:id,name',
            'steps.message',
            'eventWaits.event:id,name',
            'goalSubscriptions.event:id,name',
        );

        $graph = $run->version?->graph ?? [];
        $nodes = collect($graph['nodes'] ?? [])->filter(fn ($node) => is_array($node) && isset($node['id']))->keyBy('id');
        $describer = new GraphDescriber($context->workspace);
        $summary = fn (?string $nodeId) => $nodeId && $nodes->has($nodeId) ? $context->redactor->text($describer->summary($nodes[$nodeId])) : null;

        return [
            'run' => [
                'id' => $run->id,
                'status' => $run->status,
                'automation' => $run->automation?->only('id', 'name', 'status'),
                'version_id' => $run->automation_version_id,
                'person' => $context->person($run->person),
                'started_at' => $context->time($run->created_at),
                'updated_at' => $context->time($run->updated_at),
                'url' => $context->url('runs.show', $run),
            ],
            'trigger' => $run->occurrence ? [
                'event' => $run->occurrence->event?->name,
                'occurred_at' => $context->time($run->occurrence->occurred_at),
                'payload' => $context->redactor->data($run->occurrence->payload ?? []),
            ] : null,
            'now' => $this->now($run, $graph, $summary, $context),
            'steps' => $run->steps->sortBy('id')->map(fn (RunStep $step) => array_filter([
                'node_id' => $step->node_id,
                'type' => $step->type,
                'summary' => $summary($step->node_id),
                'status' => $step->status,
                'attempts' => $step->attempts,
                'output' => $step->output ? $context->redactor->data($step->output) : null,
                'error' => $context->redactor->text($step->error),
                'executed_at' => $context->time($step->executed_at),
                'next_attempt_at' => $context->time($step->next_attempt_at),
                'message' => $step->message ? [
                    'id' => $step->message->id,
                    'channel' => $step->message->channel,
                    'to' => $context->redactor->address($step->message->channel, $step->message->to_address),
                    'status' => $step->message->status,
                    'error' => $context->redactor->text($step->message->error),
                ] : null,
            ], fn ($value) => $value !== null))->values()->all(),
            'event_waits' => $run->eventWaits->map(fn (RunEventWait $wait) => [
                'node_id' => $wait->node_id,
                'event' => $wait->event?->name,
                'status' => $wait->status,
                'expires_at' => $context->time($wait->expires_at),
                'matched_at' => $context->time($wait->matched_at),
                'timed_out_at' => $context->time($wait->timed_out_at),
            ])->values()->all(),
            'goals' => $run->goalSubscriptions->map(fn (RunGoalSubscription $goal) => [
                'goal_id' => $goal->goal_id,
                'event' => $goal->event?->name,
                'status' => $goal->status,
                'reached_at' => $context->time($goal->reached_at),
            ])->values()->all(),
            'context' => $context->redactor->data(collect($run->context ?? [])->reject(fn ($value, $key) => str_starts_with((string) $key, 'branch:'))->all()),
            'branch_choices' => collect($run->context ?? [])->filter(fn ($value, $key) => str_starts_with((string) $key, 'branch:'))
                ->mapWithKeys(fn ($value, $key) => [substr((string) $key, 7) => $value])->all(),
        ];
    }

    /** Where the run stands and what it is waiting for, in words. */
    protected function now(AutomationRun $run, array $graph, callable $summary, Context $context): array
    {
        $stalledAfter = (int) config('trigger-engage-server.stalled_runs.after_minutes', 15);
        $current = ['node_id' => $run->current_node_id, 'summary' => $summary($run->current_node_id)];

        $state = match ($run->status) {
            AutomationRun::STATUS_WAITING => $run->wake_at?->isPast() && $run->wake_at->lt(now()->subMinutes(5))
                ? 'Overdue: this delay should have woken at '.$context->time($run->wake_at).'. Check that the scheduler runs engage:tick every minute and a queue worker is up.'
                : 'Waiting on a delay; wakes at '.$context->time($run->wake_at).'.',
            AutomationRun::STATUS_WAITING_EVENT => 'Waiting for an event (see event_waits) or for the wait to time out.',
            AutomationRun::STATUS_RUNNING => $run->updated_at?->lt(now()->subMinutes($stalledAfter))
                ? 'Stalled: marked running but untouched since '.$context->time($run->updated_at).'. engage:tick re-dispatches stalled runs; if this persists, check the queue worker.'
                : 'Running now.',
            AutomationRun::STATUS_COMPLETED => 'Completed.',
            AutomationRun::STATUS_CANCELLED => 'Cancelled before the end, usually because a goal was reached (see goals).',
            AutomationRun::STATUS_FAILED => 'Failed: see the last step\'s error.',
            default => $run->status,
        };

        $next = null;
        if (in_array($run->status, AutomationRun::activeStatuses(), true) && $run->current_node_id) {
            $branch = ($run->context ?? [])['branch:'.$run->current_node_id] ?? null;
            $node = (new Graph($graph))->after($run->current_node_id, $branch);
            $next = $node ? ['node_id' => $node['id'], 'summary' => $summary($node['id'])] : null;
        }

        return ['state' => $state, 'at_step' => $current, 'next_step' => $next];
    }
}
