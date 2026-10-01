<?php

namespace TriggerEngage\Server\Engine;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
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

        // MySQL's rowCount() reports rows CHANGED, not rows matched — so claiming a
        // run that is already {running, wake_at: null}, in the same second it was
        // created, updates nothing and returns 0. (SQLite counts matched rows, which
        // is why this only bites on MySQL.) A zero here is therefore ambiguous: it
        // means either "another worker finished this run" — the case this guard is
        // for — or "the claim was already ours". Re-read to tell them apart.
        if (! $claimed) {
            $claimed = AutomationRun::query()
                ->whereKey($run->id)
                ->where('status', AutomationRun::STATUS_RUNNING)
                ->whereNull('wake_at')
                ->exists();
        }

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

            // Idempotency: a non-send node that already has a step record ran
            // on a previous advance. Pick its outcome up from the step rather
            // than walking straight past it: a run can be behind its own steps
            // (see resumeFromStep), and a delay walked past is a delay skipped.
            if ($step = $run->steps()->where('node_id', $node['id'])->first()) {
                if (! $this->resumeFromStep($run, $node, $step)) {
                    return;
                }

                continue;
            }

            switch ($node['type']) {
                case 'delay':
                    $wakeAt = $this->wakeAt($node['config'], $run);

                    $this->checkpoint($run, $node, ['wake_at' => $wakeAt->toIso8601String()], [
                        'status' => AutomationRun::STATUS_WAITING,
                        'wake_at' => $wakeAt,
                    ]);

                    return;

                case 'branch':
                    $result = $this->conditions->passes($node['config'], $context);

                    $updated = $this->checkpoint($run, $node, ['result' => $result], [
                        'context' => array_merge($run->context ?? [], [
                            'branch:'.$node['id'] => $result ? 'true' : 'false',
                        ]),
                    ]);

                    if (! $updated) {
                        return;
                    }

                    break;

                case 'segment':
                    // Membership check against the materialized segment_person
                    // rows — true when the person's membership matches the
                    // node's expectation ("in" or "not in").
                    $wantIn = ($node['config']['in'] ?? true) !== false;
                    $isMember = DB::table('segment_person')
                        ->where('segment_id', (int) ($node['config']['segment_id'] ?? 0))
                        ->where('person_id', $run->person_id)
                        ->exists();
                    $result = $isMember === $wantIn;

                    $updated = $this->checkpoint($run, $node, ['member' => $isMember, 'result' => $result], [
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

                    $updated = $this->checkpoint($run, $node, ['variant' => $variant], [
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

    /**
     * Returns CarbonInterface, not Carbon: an embedding host may have called
     * Date::use(CarbonImmutable::class), which makes now() immutable — and the
     * addDay() below a no-op unless its result is reassigned.
     *
     * An until-time is a wall-clock target in the WORKSPACE timezone, but it is
     * stored and compared (engage:tick's `wake_at <= now()`) in the HOST app's
     * timezone, which is whatever config('app.timezone') says — not necessarily
     * UTC. Converting to UTC here made every until-time fire early by the
     * host's offset when the embedding app ran on, say, Africa/Lagos.
     */
    protected function wakeAt(array $config, AutomationRun $run): CarbonInterface
    {
        if (isset($config['until_time'])) {
            $timezone = $run->workspace->timezone ?? 'UTC';
            $target = Carbon::now($timezone)->setTimeFromTimeString($config['until_time']);

            if ($target->isPast()) {
                $target = $target->addDay();
            }

            return $target->setTimezone(config('app.timezone', 'UTC'));
        }

        return now()
            ->addDays((int) ($config['days'] ?? 0))
            ->addHours((int) ($config['hours'] ?? 0))
            ->addMinutes((int) ($config['minutes'] ?? 0));
    }

    /**
     * Record a node's step and move the run onto that node in one
     * transaction. Written apart, a worker that died between the two left a
     * step the run never acted on: a delay recorded but never waited, a branch
     * answered but its answer lost.
     *
     * @param  array<string, mixed>  $output
     * @param  array<string, mixed>  $changes  run columns to set alongside current_node_id
     */
    protected function checkpoint(AutomationRun $run, array $node, array $output, array $changes): int
    {
        return DB::transaction(function () use ($run, $node, $output, $changes): int {
            $this->recordStep($run, $node, 'completed', $output);

            return AutomationRun::query()
                ->whereKey($run->id)
                ->where('status', AutomationRun::STATUS_RUNNING)
                ->update(['current_node_id' => $node['id']] + $changes);
        });
    }

    /**
     * A run that reaches a node already holding a step record is behind its
     * own steps: a duplicate delivery, or a run left mid-checkpoint by an
     * engine that wrote the step and the run separately. Restore what the
     * step decided instead of skipping the node: a delay whose wake time is
     * still ahead parks the run again, and a branch, segment or split whose
     * answer never reached the run's context gets it back.
     *
     * @return bool true to keep walking, false when the run is parked again
     */
    protected function resumeFromStep(AutomationRun $run, array $node, RunStep $step): bool
    {
        $output = $step->output ?? [];

        if ($node['type'] === 'delay' && filled($output['wake_at'] ?? null)) {
            $wakeAt = Date::parse($output['wake_at'])->setTimezone(config('app.timezone', 'UTC'));

            if ($wakeAt->isFuture()) {
                AutomationRun::query()
                    ->whereKey($run->id)
                    ->where('status', AutomationRun::STATUS_RUNNING)
                    ->update([
                        'current_node_id' => $node['id'],
                        'status' => AutomationRun::STATUS_WAITING,
                        'wake_at' => $wakeAt,
                    ]);

                return false;
            }
        }

        $changes = ['current_node_id' => $node['id']];
        $key = 'branch:'.$node['id'];
        $answer = match ($node['type']) {
            'branch', 'segment' => array_key_exists('result', $output) ? ($output['result'] ? 'true' : 'false') : null,
            'split' => $output['variant'] ?? null,
            default => null,
        };

        if ($answer !== null && ! array_key_exists($key, $run->context ?? [])) {
            $changes['context'] = array_merge($run->context ?? [], [$key => $answer]);
        }

        // Not gated on the row count: on MySQL a no-op update reports zero.
        // The walk re-reads the run before every node and stops if it moved.
        AutomationRun::query()
            ->whereKey($run->id)
            ->where('status', AutomationRun::STATUS_RUNNING)
            ->update($changes);

        return true;
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
