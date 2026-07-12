<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Engine\Channels\EmailChannel;
use TriggerEngage\Server\Engine\Channels\PushChannel;
use TriggerEngage\Server\Engine\Channels\SmsChannel;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

class RunEngine
{
    protected const MAX_STEPS_PER_ADVANCE = 100;

    protected const SEND_TERMINAL = 'terminal';

    protected const SEND_WAITING = 'waiting';

    protected const SEND_BLOCKED = 'blocked';

    protected const SEND_FAIL_RUN = 'fail_run';

    public function __construct(
        protected ConditionEvaluator $conditions,
        protected EmailChannel $email,
        protected SmsChannel $sms,
        protected PushChannel $push,
        protected EventWaitManager $eventWaits,
    ) {}

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

        // A duplicate queue delivery must never bypass a durable delay or a
        // send retry backoff that has not elapsed yet.
        if ($run->status === AutomationRun::STATUS_WAITING && $run->wake_at?->isFuture()) {
            return;
        }

        $claimed = AutomationRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [AutomationRun::STATUS_RUNNING, AutomationRun::STATUS_WAITING])
            ->update(['status' => AutomationRun::STATUS_RUNNING, 'wake_at' => null]);

        if (! $claimed) {
            return;
        }

        $run->refresh();

        $graph = new Graph($run->version->graph);
        $context = $this->buildContext($run);
        $guard = 0;

        while (true) {
            if (++$guard > self::MAX_STEPS_PER_ADVANCE) {
                AutomationRun::query()
                    ->whereKey($run->id)
                    ->where('status', AutomationRun::STATUS_RUNNING)
                    ->update(['status' => AutomationRun::STATUS_FAILED]);

                return;
            }

            // Goal events update the run independently of this worker. Never
            // execute another node after a goal has completed the run.
            $run->refresh();

            if ($run->status !== AutomationRun::STATUS_RUNNING) {
                return;
            }

            $branch = ($run->context ?? [])['branch:'.$run->current_node_id] ?? null;
            $node = $graph->after($run->current_node_id, $branch);

            if (! $node || $node['type'] === 'exit') {
                if ($node) {
                    $this->recordStep($run, $node, 'completed');
                }

                $run->update(['status' => AutomationRun::STATUS_COMPLETED]);

                return;
            }

            if (in_array($node['type'], ['send_email', 'send_sms', 'send_push'], true)) {
                $outcome = $this->executeSendAction($run, $node, $context);

                if (in_array($outcome, [self::SEND_WAITING, self::SEND_BLOCKED], true)) {
                    return;
                }

                if ($outcome === self::SEND_FAIL_RUN) {
                    AutomationRun::query()
                        ->whereKey($run->id)
                        ->where('status', AutomationRun::STATUS_RUNNING)
                        ->update(['status' => AutomationRun::STATUS_FAILED]);

                    return;
                }

                $run->update(['current_node_id' => $node['id']]);

                continue;
            }

            if ($node['type'] === 'wait_for_event') {
                $this->eventWaits->register($run, $node);

                return;
            }

            // Idempotency: a non-send node that already has a step record was
            // executed on a previous advance and is safe to move past.
            if ($run->steps()->where('node_id', $node['id'])->exists()) {
                $run->update(['current_node_id' => $node['id']]);

                continue;
            }

            switch ($node['type']) {
                case 'delay':
                    $this->recordStep($run, $node, 'completed', [
                        'wake_at' => ($wakeAt = $this->wakeAt($node['config'], $run))->toIso8601String(),
                    ]);

                    $updated = AutomationRun::query()
                        ->whereKey($run->id)
                        ->where('status', AutomationRun::STATUS_RUNNING)
                        ->update([
                            'current_node_id' => $node['id'],
                            'status' => AutomationRun::STATUS_WAITING,
                            'wake_at' => $wakeAt,
                        ]);

                    if (! $updated) {
                        return;
                    }

                    return;

                case 'branch':
                    $result = $this->conditions->passes($node['config'], $context);

                    $this->recordStep($run, $node, 'completed', ['result' => $result]);

                    $updated = AutomationRun::query()
                        ->whereKey($run->id)
                        ->where('status', AutomationRun::STATUS_RUNNING)
                        ->update([
                            'current_node_id' => $node['id'],
                            'context' => array_merge($run->context ?? [], [
                                'branch:'.$node['id'] => $result ? 'true' : 'false',
                            ]),
                        ]);

                    if (! $updated) {
                        return;
                    }

                    break;

                case 'split':
                    // Deterministic weighted assignment: the same person always
                    // lands on the same variant of the same node, so re-runs of
                    // this advance never reshuffle the experiment.
                    $variant = $this->pickVariant($run, $node);

                    $this->recordStep($run, $node, 'completed', ['variant' => $variant]);

                    $updated = AutomationRun::query()
                        ->whereKey($run->id)
                        ->where('status', AutomationRun::STATUS_RUNNING)
                        ->update([
                            'current_node_id' => $node['id'],
                            'context' => array_merge($run->context ?? [], [
                                'branch:'.$node['id'] => $variant,
                            ]),
                        ]);

                    if (! $updated) {
                        return;
                    }

                    break;

                default:
                    $this->recordStep($run, $node, 'skipped', [
                        'reason' => "Unknown node type [{$node['type']}]",
                    ]);

                    $run->update(['current_node_id' => $node['id']]);
            }
        }
    }

    protected function executeSendAction(AutomationRun $run, array $node, array $context): string
    {
        $person = $run->person;
        $channelType = match ($node['type']) {
            'send_sms' => 'sms',
            'send_push' => 'push',
            default => 'email',
        };

        $step = DB::transaction(function () use ($run, $node): ?RunStep {
            $lockedRun = AutomationRun::query()->lockForUpdate()->find($run->id);

            if (! $lockedRun || $lockedRun->status !== AutomationRun::STATUS_RUNNING) {
                return null;
            }

            return RunStep::query()->firstOrCreate(
                ['automation_run_id' => $run->id, 'node_id' => $node['id']],
                [
                    'type' => $node['type'],
                    'status' => 'processing',
                    'attempts' => 1,
                    'executed_at' => now(),
                ]
            );
        });

        if (! $step) {
            return self::SEND_BLOCKED;
        }

        if (! $step->wasRecentlyCreated) {
            $outcome = $this->resumeSendStep($run, $step, $node);

            if ($outcome !== null) {
                return $outcome;
            }
        }

        if ($person->isSuppressed($channelType)) {
            $step->update([
                'status' => 'skipped',
                'output' => ['reason' => "person suppressed for {$channelType}"],
            ]);

            return self::SEND_TERMINAL;
        }

        $template = Template::query()
            ->where('workspace_id', $run->workspace_id)
            ->where('channel', $channelType)
            ->find($node['config']['template_id'] ?? null);

        $channel = $this->resolveChannel($run->workspace_id, $channelType, $node['config']['channel_id'] ?? null);

        if (! $template || ! $channel) {
            $step->update([
                'status' => 'failed',
                'error' => "Missing template or {$channelType} channel",
            ]);

            return $this->failureOutcome($node);
        }

        $result = match ($channelType) {
            'sms' => $this->sms->send($channel, $template, $person, $context, $step),
            'push' => $this->push->send($channel, $template, $person, $context, $step),
            default => $this->email->send($channel, $template, $person, $context, $step),
        };

        if (! $result) {
            $step->update([
                'status' => 'skipped',
                'output' => ['reason' => "person has no {$channelType} destination"],
            ]);

            return self::SEND_TERMINAL;
        }

        $message = $result['message'];
        $output = ['message_id' => $message->id];

        if ($result['warnings']) {
            $output['warnings'] = array_map(
                fn (string $variable) => "Missing template variable [{$variable}] rendered as empty.",
                $result['warnings']
            );
        }

        if ($message->status === 'failed') {
            return $this->scheduleSendRetry($run, $step, $node, $message, $output);
        }

        $step->update([
            'status' => 'completed',
            'output' => $output,
            'error' => null,
            'next_attempt_at' => null,
        ]);

        return self::SEND_TERMINAL;
    }

    /**
     * Returns null when a retry was claimed and should execute now.
     */
    protected function resumeSendStep(AutomationRun $run, RunStep $step, array $node): ?string
    {
        if (in_array($step->status, ['completed', 'skipped'], true)) {
            return self::SEND_TERMINAL;
        }

        if ($step->status === 'failed') {
            return $this->failureOutcome($node);
        }

        if ($step->status === 'processing') {
            // Another worker owns the reserved side effect. Never invoke the
            // provider concurrently for the same run/node.
            return self::SEND_BLOCKED;
        }

        if ($step->status !== 'retrying') {
            return self::SEND_BLOCKED;
        }

        if ($step->next_attempt_at?->isFuture()) {
            $run->update([
                'status' => AutomationRun::STATUS_WAITING,
                'wake_at' => $step->next_attempt_at,
            ]);

            return self::SEND_WAITING;
        }

        $claimed = RunStep::query()
            ->whereKey($step->id)
            ->where('status', 'retrying')
            ->update([
                'status' => 'processing',
                'attempts' => $step->attempts + 1,
                'next_attempt_at' => null,
                'updated_at' => now(),
            ]);

        if (! $claimed) {
            return self::SEND_BLOCKED;
        }

        $step->refresh();

        return null;
    }

    protected function scheduleSendRetry(
        AutomationRun $run,
        RunStep $step,
        array $node,
        Message $message,
        array $output,
    ): string {
        if (AutomationRun::query()->whereKey($run->id)->value('status') !== AutomationRun::STATUS_RUNNING) {
            $step->update([
                'status' => 'skipped',
                'output' => array_merge($output, ['reason' => 'automation goal reached']),
                'error' => null,
                'next_attempt_at' => null,
            ]);

            return self::SEND_BLOCKED;
        }

        $maxAttempts = max(1, min(10, (int) ($node['config']['retry_attempts'] ?? 3)));

        if ($step->attempts >= $maxAttempts) {
            $step->update([
                'status' => 'failed',
                'output' => $output,
                'error' => $message->error,
                'next_attempt_at' => null,
            ]);

            return $this->failureOutcome($node);
        }

        $wakeAt = now()->addSeconds($this->retryBackoffSeconds($node, $step->attempts));

        $step->update([
            'status' => 'retrying',
            'output' => $output,
            'error' => $message->error,
            'next_attempt_at' => $wakeAt,
        ]);

        $updated = AutomationRun::query()
            ->whereKey($run->id)
            ->where('status', AutomationRun::STATUS_RUNNING)
            ->update([
                'status' => AutomationRun::STATUS_WAITING,
                'wake_at' => $wakeAt,
            ]);

        if (! $updated) {
            $step->update([
                'status' => 'skipped',
                'output' => array_merge($output, ['reason' => 'automation goal reached']),
                'error' => null,
                'next_attempt_at' => null,
            ]);

            return self::SEND_BLOCKED;
        }

        return self::SEND_WAITING;
    }

    protected function retryBackoffSeconds(array $node, int $attempt): int
    {
        $backoff = $node['config']['retry_backoff_seconds'] ?? [10, 60, 300];
        $backoff = is_array($backoff) && $backoff ? array_values($backoff) : [10, 60, 300];

        return max(1, (int) ($backoff[min($attempt - 1, count($backoff) - 1)] ?? 10));
    }

    protected function failureOutcome(array $node): string
    {
        return ($node['config']['on_failure'] ?? 'continue') === 'fail'
            ? self::SEND_FAIL_RUN
            : self::SEND_TERMINAL;
    }

    protected function resolveChannel(int $workspaceId, string $type, ?int $channelId): ?Channel
    {
        $query = Channel::query()->where('workspace_id', $workspaceId)->where('type', $type);

        return $channelId
            ? $query->find($channelId)
            : $query->orderByDesc('is_default')->first();
    }

    /**
     * Weighted, deterministic variant assignment. Hashing the person + node id
     * keeps assignment stable across retries while distributing people across
     * variants in proportion to their weights.
     */
    protected function pickVariant(AutomationRun $run, array $node): ?string
    {
        $variants = array_values($node['config']['variants'] ?? []);

        if ($variants === []) {
            return null;
        }

        $weights = array_map(fn (array $variant) => max(1, (int) ($variant['weight'] ?? 1)), $variants);
        $total = array_sum($weights);

        $identity = $run->person->external_id ?? (string) $run->person->id;
        $bucket = crc32($identity.'|'.$node['id']) % $total;

        $cumulative = 0;

        foreach ($variants as $index => $variant) {
            $cumulative += $weights[$index];

            if ($bucket < $cumulative) {
                return (string) ($variant['key'] ?? $index);
            }
        }

        return (string) ($variants[array_key_last($variants)]['key'] ?? array_key_last($variants));
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
