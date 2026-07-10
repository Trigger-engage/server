<?php

namespace App\Engine;

use App\Engine\Channels\EmailChannel;
use App\Models\AutomationRun;
use App\Models\Channel;
use App\Models\RunStep;
use App\Models\Template;
use Illuminate\Support\Carbon;

class RunEngine
{
    protected const MAX_STEPS_PER_ADVANCE = 100;

    public function __construct(
        protected ConditionEvaluator $conditions,
        protected EmailChannel $email,
    ) {
    }

    /**
     * Walk the run forward from its current node until it waits (delay),
     * completes, or fails. current_node_id always points at the last node
     * that finished executing.
     */
    public function advance(AutomationRun $run): void
    {
        if (! in_array($run->status, [AutomationRun::STATUS_RUNNING, AutomationRun::STATUS_WAITING])) {
            return;
        }

        $run->forceFill(['status' => AutomationRun::STATUS_RUNNING, 'wake_at' => null])->save();

        $graph = new Graph($run->version->graph);
        $context = $this->buildContext($run);
        $guard = 0;

        while (true) {
            if (++$guard > self::MAX_STEPS_PER_ADVANCE) {
                $run->update(['status' => AutomationRun::STATUS_FAILED]);

                return;
            }

            $branch = $run->context['branch:'.$run->current_node_id] ?? null;
            $node = $graph->after($run->current_node_id, $branch);

            if (! $node || $node['type'] === 'exit') {
                if ($node) {
                    $this->recordStep($run, $node, 'completed');
                }

                $run->update(['status' => AutomationRun::STATUS_COMPLETED]);

                return;
            }

            // Idempotency: a node that already has a step record was executed
            // on a previous (possibly crashed/retried) advance — never redo it.
            if ($run->steps()->where('node_id', $node['id'])->exists()) {
                $run->update(['current_node_id' => $node['id']]);

                continue;
            }

            switch ($node['type']) {
                case 'delay':
                    $this->recordStep($run, $node, 'completed', [
                        'wake_at' => ($wakeAt = $this->wakeAt($node['config'], $run))->toIso8601String(),
                    ]);

                    $run->update([
                        'current_node_id' => $node['id'],
                        'status' => AutomationRun::STATUS_WAITING,
                        'wake_at' => $wakeAt,
                    ]);

                    return;

                case 'branch':
                    $result = $this->conditions->passes($node['config'], $context);

                    $this->recordStep($run, $node, 'completed', ['result' => $result]);

                    $run->update([
                        'current_node_id' => $node['id'],
                        'context' => array_merge($run->context ?? [], [
                            'branch:'.$node['id'] => $result ? 'true' : 'false',
                        ]),
                    ]);

                    break;

                case 'send_email':
                    $this->executeSendEmail($run, $node, $context);

                    $run->update(['current_node_id' => $node['id']]);

                    break;

                default:
                    $this->recordStep($run, $node, 'skipped', [
                        'reason' => "Unknown node type [{$node['type']}]",
                    ]);

                    $run->update(['current_node_id' => $node['id']]);
            }
        }
    }

    protected function executeSendEmail(AutomationRun $run, array $node, array $context): void
    {
        $person = $run->person;

        if ($person->isSuppressed('email')) {
            $this->recordStep($run, $node, 'skipped', ['reason' => 'person suppressed for email']);

            return;
        }

        $template = Template::query()
            ->where('workspace_id', $run->workspace_id)
            ->find($node['config']['template_id'] ?? null);

        $channel = $this->resolveChannel($run->workspace_id, 'email', $node['config']['channel_id'] ?? null);

        if (! $template || ! $channel) {
            $this->recordStep($run, $node, 'failed', null, 'Missing template or email channel');

            return;
        }

        $message = $this->email->send($channel, $template, $person, $context);

        if (! $message) {
            $this->recordStep($run, $node, 'skipped', ['reason' => 'person has no email address']);

            return;
        }

        $step = $this->recordStep(
            $run,
            $node,
            $message->status === 'failed' ? 'failed' : 'completed',
            ['message_id' => $message->id],
            $message->error
        );

        $message->update(['run_step_id' => $step->id]);
    }

    protected function resolveChannel(int $workspaceId, string $type, ?int $channelId): ?Channel
    {
        $query = Channel::query()->where('workspace_id', $workspaceId)->where('type', $type);

        return $channelId
            ? $query->find($channelId)
            : $query->orderByDesc('is_default')->first();
    }

    protected function wakeAt(array $config, AutomationRun $run): Carbon
    {
        if (isset($config['until_time'])) {
            $timezone = $run->workspace->timezone ?? 'UTC';
            $target = Carbon::now($timezone)->setTimeFromTimeString($config['until_time']);

            if ($target->isPast()) {
                $target->addDay();
            }

            return $target->utc();
        }

        return now()
            ->addDays((int) ($config['days'] ?? 0))
            ->addHours((int) ($config['hours'] ?? 0))
            ->addMinutes((int) ($config['minutes'] ?? 0));
    }

    protected function recordStep(
        AutomationRun $run,
        array $node,
        string $status,
        ?array $output = null,
        ?string $error = null
    ): RunStep {
        return $run->steps()->create([
            'node_id' => $node['id'],
            'type' => $node['type'],
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'executed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function buildContext(AutomationRun $run): array
    {
        return [
            'person' => $run->person->toContext(),
            'event' => $run->occurrence?->payload ?? [],
        ];
    }
}
