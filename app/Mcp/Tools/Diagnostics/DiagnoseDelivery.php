<?php

namespace TriggerEngage\Server\Mcp\Tools\Diagnostics;

use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use TriggerEngage\Server\Engine\ConditionEvaluator;
use TriggerEngage\Server\Engine\Graph;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\BroadcastRecipient;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Services\Subscriptions;

/**
 * "Why did (or didn't) this person get that message?" Walks every place a
 * message can stop, in the order the engine meets them, and says which one
 * did. Each check is pass, fail, warn or info; the verdict is the first
 * failure, or what was delivered.
 */
class DiagnoseDelivery extends Tool
{
    protected const SEND_TYPES = ['send_email', 'send_sms', 'send_push'];

    protected Context $context;

    protected GraphDescriber $describer;

    /** @var array<int, array{check: string, result: string, detail: string}> */
    protected array $checks = [];

    public function __construct(
        protected Subscriptions $subscriptions,
        protected ConditionEvaluator $conditions,
    ) {}

    public function name(): string
    {
        return 'diagnose_delivery';
    }

    public function title(): string
    {
        return 'Diagnose a delivery';
    }

    public function description(): string
    {
        return 'Answers "why did (or didn\'t) this person get this message?" for an automation or a broadcast. For an '
            .'automation it checks, in order: is it published and active; did the person send the trigger event in the '
            .'window; did each event start a run, and if not which trigger filter or re-entry rule stopped it; what the '
            .'latest run did at each send step (sent, skipped and why, failed and why) and what the provider reported; '
            .'and whether each channel can reach the person today (address, suppression, topic opt-out). For a broadcast '
            .'it checks the person\'s recipient row, segment membership at send time and reachability. Returns a '
            .'one-line verdict and every check with pass, fail, warn or info.';
    }

    public function properties(): array
    {
        return [
            ...$this->personProperties(),
            'automation_id' => ['type' => 'integer', 'description' => 'The automation to check. Give this or broadcast_id.'],
            'broadcast_id' => ['type' => 'integer', 'description' => 'The broadcast to check. Give this or automation_id.'],
            'days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'description' => 'How far back to look for trigger events and runs (default 30).'],
        ];
    }

    public function rules(): array
    {
        return [
            ...$this->personRules(),
            'automation_id' => ['nullable', 'integer', 'required_without:broadcast_id', 'prohibits:broadcast_id'],
            'broadcast_id' => ['nullable', 'integer', 'required_without:automation_id'],
            'days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $this->context = $context;
        $this->describer = new GraphDescriber($context->workspace);
        $this->checks = [];
        $person = $this->findPerson($arguments, $context);

        $result = filled($arguments['automation_id'] ?? null)
            ? $this->automation($person, $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation'), (int) ($arguments['days'] ?? 30))
            : $this->broadcast($person, $this->findOwned(Broadcast::class, $arguments['broadcast_id'], $context, 'broadcast'));

        return [
            'person' => $context->person($person),
            'verdict' => $result['verdict'] ?? $this->verdict(),
            ...Arr::except($result, 'verdict'),
            'checks' => $this->checks,
        ];
    }

    /** @return array<string, mixed> */
    protected function automation(Person $person, Automation $automation, int $days): array
    {
        $automation->load('triggerEvent:id,name', 'activeVersion');
        $graph = $automation->activeVersion?->graph ?? [];
        $since = now()->subDays($days);

        // 1. Is it live?
        if (! $automation->active_version_id) {
            $this->check('Automation is published', 'fail', 'It has never been published, so it starts no runs.');
        } elseif ($automation->status !== 'active') {
            $this->check('Automation is active', 'fail', "It is {$automation->status}, so new trigger events start no runs (runs already under way carry on).");
        } else {
            $this->check('Automation is active', 'pass', 'Live version '.$automation->active_version_id.', published '.$this->context->time($automation->activeVersion->published_at).'.');
        }

        // 2. Did the trigger event arrive for this person?
        $event = $automation->triggerEvent;
        $occurrences = collect();
        if (! $event) {
            $this->check('Trigger event', 'fail', 'The automation has no trigger event.');
        } else {
            $occurrences = EventOccurrence::query()
                ->where('workspace_id', $person->workspace_id)
                ->where('event_id', $event->id)
                ->where('person_id', $person->id)
                ->where('occurred_at', '>=', $since)
                ->orderByDesc('occurred_at')->orderByDesc('id')
                ->limit(10)->get();

            if ($occurrences->isEmpty()) {
                $theirLast = EventOccurrence::query()->where('event_id', $event->id)->where('person_id', $person->id)->max('occurred_at');
                $anyoneLast = EventOccurrence::query()->where('event_id', $event->id)->max('occurred_at');
                $this->check('Trigger event received', 'fail', "No \"{$event->name}\" event for this person in the last {$days} days."
                    .($theirLast ? ' Their last one was at '.$this->context->time($theirLast).'; pass a larger days to look further back.' : '')
                    .($anyoneLast
                        ? ' The workspace last received "'.$event->name.'" at '.$this->context->time($anyoneLast).', so the host app does send it, just not for this person (or not under this identity).'
                        : ' Nobody has sent "'.$event->name.'" yet: check that the host app tracks it.'));
            } else {
                $this->check('Trigger event received', 'pass', $occurrences->count().' "'.$event->name.'" event'.($occurrences->count() === 1 ? '' : 's').' in the last '.$days.' days, the latest at '.$this->context->time($occurrences->first()->occurred_at).'.');
            }
        }

        // 3. Did each event start a run?
        $runs = AutomationRun::query()
            ->where('automation_id', $automation->id)
            ->where('person_id', $person->id)
            ->with('steps.message', 'version:id,graph', 'goalSubscriptions.event:id,name')
            ->orderByDesc('id')->limit(20)->get();

        $entries = $occurrences->map(function (EventOccurrence $occurrence) use ($automation, $runs) {
            $run = $runs->firstWhere('event_occurrence_id', $occurrence->id)
                ?? AutomationRun::query()->where('automation_id', $automation->id)->where('event_occurrence_id', $occurrence->id)->first();

            return [
                'occurrence_id' => $occurrence->id,
                'occurred_at' => $this->context->time($occurrence->occurred_at),
                'run_id' => $run?->id,
                'run_status' => $run?->status,
                'why_no_run' => $run ? null : $this->whyNoRun($automation, $occurrence, $runs),
            ];
        })->values();

        if ($entries->isNotEmpty()) {
            $entered = $entries->whereNotNull('run_id')->count();
            $missed = $entries->whereNull('run_id');

            if ($entered === 0) {
                $this->check('Event started a run', 'fail', 'None of the '.$entries->count().' events started a run. Latest: '.$missed->first()['why_no_run']);
            } elseif ($missed->isNotEmpty()) {
                $this->check('Event started a run', 'pass', "{$entered} of {$entries->count()} events started a run. The others did not: ".$missed->pluck('why_no_run')->unique()->implode(' '));
            } else {
                $this->check('Event started a run', 'pass', "Every one of the {$entered} events started a run.");
            }
        }

        // 4. What did the latest run do?
        $run = $runs->first(fn (AutomationRun $run) => $run->created_at->gte($since));
        $sent = $run ? $this->runOutcome($run) : [];

        // 5. Can the channels reach them today?
        $sendNodes = collect($graph['nodes'] ?? [])->filter(fn ($node) => is_array($node) && in_array($node['type'] ?? null, self::SEND_TYPES, true));
        foreach ($sendNodes->groupBy(fn (array $node) => substr($node['type'], 5)) as $channelType => $nodes) {
            $channelId = $nodes->pluck('config.channel_id')->filter()->first();
            $topicIds = Template::query()->whereIn('id', $nodes->pluck('config.template_id')->filter())->whereNotNull('subscription_topic_id')->pluck('subscription_topic_id', 'name');
            $this->reachability($person, $channelType, $channelId ? (int) $channelId : null, $topicIds);
        }

        $messages = Message::query()
            ->where('person_id', $person->id)
            ->whereHas('runStep.run', fn ($query) => $query->where('automation_id', $automation->id))
            ->where('created_at', '>=', $since)
            ->with('template:id,name')
            ->orderByDesc('id')->limit(10)
            ->get(['id', 'template_id', 'channel', 'status', 'error', 'sent_at', 'created_at']);

        return [
            'verdict' => $sent !== [] ? $this->sentVerdict($sent) : $this->verdict(),
            'automation' => [
                ...$automation->only('id', 'name', 'status', 'reentry_policy'),
                'trigger_event' => $event?->name,
                'url' => $this->context->url('automations.edit', $automation),
            ],
            'window_days' => $days,
            'trigger_events' => $entries->all(),
            'latest_run' => $run ? [
                'id' => $run->id,
                'status' => $run->status,
                'started_at' => $this->context->time($run->created_at),
                'url' => $this->context->url('runs.show', $run),
            ] : null,
            'messages' => $messages->map(fn (Message $message) => [
                'id' => $message->id,
                'channel' => $message->channel,
                'template' => $message->template?->name,
                'status' => $message->status,
                'error' => $this->context->redactor->text($message->error),
                'sent_at' => $this->context->time($message->sent_at),
            ])->values()->all(),
        ];
    }

    /** Why an occurrence of the trigger event started no run, mirroring ProcessEventOccurrence. */
    protected function whyNoRun(Automation $automation, EventOccurrence $occurrence, Collection $runs): string
    {
        $versions = $automation->versions()->whereNotNull('published_at')->orderBy('published_at')->get(['id', 'graph', 'published_at']);
        $first = $versions->first();

        if (! $first || $occurrence->occurred_at->lt($first->published_at)) {
            return 'It happened at '.$this->context->time($occurrence->occurred_at).', before the automation was first published'
                .($first ? ' ('.$this->context->time($first->published_at).')' : '').'.';
        }

        $version = $versions->filter(fn ($version) => $version->published_at->lte($occurrence->occurred_at))->last() ?? $first;
        $trigger = (new Graph($version->graph ?? []))->triggerNode();

        foreach ($trigger['config']['filters'] ?? [] as $filter) {
            if (! is_array($filter) || blank($filter['field'] ?? null)) {
                continue;
            }

            $field = str_starts_with($filter['field'], 'event.') ? $filter['field'] : 'event.'.$filter['field'];
            $condition = ['field' => $field, 'operator' => $filter['operator'] ?? 'equals', 'value' => $filter['value'] ?? null];
            $payload = ['event' => $occurrence->payload ?? []];

            if (! $this->conditions->passes($condition, $payload)) {
                $actual = Arr::get($payload, $field);

                return 'The trigger filter "'.$field.' '.str_replace('_', ' ', $condition['operator']).' '.$this->context->redactor->text(json_encode($condition['value'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'" did not match: the event had '
                    .$field.' = '.$this->context->redactor->text(json_encode($actual, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'.';
            }
        }

        // Earlier = started by an earlier occurrence. Ids, not timestamps:
        // two events in the same second must still be told apart.
        $earlier = $runs->filter(fn (AutomationRun $run) => $run->event_occurrence_id !== null
            ? $run->event_occurrence_id < $occurrence->id
            : $run->created_at->lt($occurrence->created_at ?? $occurrence->occurred_at))->sortBy('id');

        if ($automation->reentry_policy === Automation::REENTRY_ONCE_EVER && $earlier->isNotEmpty()) {
            return 'Re-entry is once_ever_per_person and they already went through it (run '.$earlier->last()->id.', started '.$this->context->time($earlier->last()->created_at).').';
        }

        if ($automation->reentry_policy === Automation::REENTRY_ONE_ACTIVE) {
            $busy = $earlier->first(fn (AutomationRun $run) => in_array($run->status, AutomationRun::activeStatuses(), true)
                || $run->updated_at->gte($occurrence->created_at ?? $occurrence->occurred_at));

            if ($busy) {
                return 'Re-entry is one_active_run_per_person and run '.$busy->id.' was still in progress when this event arrived.';
            }
        }

        if ($automation->status !== 'active') {
            return 'The automation is '.$automation->status.' now and may have been when this event arrived.';
        }

        if ($occurrence->created_at?->gt(now()->subMinutes(2))) {
            return 'It arrived under two minutes ago; the queue may not have processed it yet.';
        }

        return 'No rule explains it. The event may still be waiting in the queue (is a worker running?), or the automation was paused when it arrived.';
    }

    /**
     * Record the latest run's send steps and state as checks.
     *
     * @return array<int, array{channel: string, template: ?string, status: string, at: ?string}>
     */
    protected function runOutcome(AutomationRun $run): array
    {
        $nodes = collect($run->version?->graph['nodes'] ?? [])->filter(fn ($node) => is_array($node) && isset($node['id']))->keyBy('id');
        $label = fn (string $nodeId) => $nodes->has($nodeId) ? $this->describer->summary($nodes[$nodeId]) : 'step '.$nodeId;
        $sent = [];

        foreach ($run->steps->sortBy('id') as $step) {
            /** @var RunStep $step */
            if (! in_array($step->type, self::SEND_TYPES, true)) {
                continue;
            }

            $what = $label($step->node_id);
            $message = $step->message;

            match (true) {
                $step->status === 'skipped' => $this->check('Send step', 'fail', 'Skipped "'.$what.'": '.$this->context->redactor->text($step->output['reason'] ?? 'no reason recorded').'.'),
                $step->status === 'failed' => $this->check('Send step', 'fail', 'Failed "'.$what.'": '.$this->context->redactor->text($step->error ?? 'no error recorded').'.'),
                in_array($step->status, ['processing', 'retrying'], true) => $this->check('Send step', 'info', ucfirst($step->status).' "'.$what.'"'.($step->next_attempt_at ? ', next attempt at '.$this->context->time($step->next_attempt_at) : '').' (attempt '.$step->attempts.').'),
                $message === null => $this->check('Send step', 'warn', '"'.$what.'" completed but recorded no message.'),
                in_array($message->status, ['failed', 'bounced'], true) => $this->check('Provider result', 'fail', '"'.$what.'" was handed to the provider and '.$message->status.': '.$this->context->redactor->text($message->error ?? 'no error recorded').'.'),
                in_array($message->status, ['queued', 'sending'], true) => $this->check('Provider result', 'info', '"'.$what.'" is still '.$message->status.'.'),
                default => $this->check('Provider result', 'pass', '"'.$what.'": '.$message->status.($message->delivered_at ? ', delivered '.$this->context->time($message->delivered_at) : ($message->sent_at ? ', sent '.$this->context->time($message->sent_at) : '')).($message->opened_at ? ', opened' : '').'.'),
            };

            if ($message && ! in_array($message->status, ['failed', 'bounced', 'queued', 'sending'], true) && $step->status === 'completed') {
                $sent[] = ['channel' => $message->channel, 'what' => $what, 'status' => $message->status, 'at' => $this->context->time($message->delivered_at ?? $message->sent_at ?? $message->created_at)];
            }
        }

        $stalledAfter = (int) config('trigger-engage-server.stalled_runs.after_minutes', 15);
        $at = $run->current_node_id ? $label($run->current_node_id) : 'the start';

        match ($run->status) {
            AutomationRun::STATUS_WAITING => $run->wake_at?->lt(now()->subMinutes(5))
                ? $this->check('Run progress', 'warn', 'Run '.$run->id.' is overdue: its delay should have woken at '.$this->context->time($run->wake_at).'. Check that engage:tick runs every minute and a queue worker is up.')
                : $this->check('Run progress', 'info', 'Run '.$run->id.' is waiting at "'.$at.'" until '.$this->context->time($run->wake_at).'.'),
            AutomationRun::STATUS_WAITING_EVENT => $this->check('Run progress', 'info', 'Run '.$run->id.' is waiting for an event before it continues.'),
            AutomationRun::STATUS_RUNNING => $run->updated_at->lt(now()->subMinutes($stalledAfter))
                ? $this->check('Run progress', 'warn', 'Run '.$run->id.' is stalled: marked running but untouched since '.$this->context->time($run->updated_at).'.')
                : $this->check('Run progress', 'info', 'Run '.$run->id.' is running now.'),
            AutomationRun::STATUS_FAILED => $this->check('Run progress', 'fail', 'Run '.$run->id.' failed at "'.$at.'".'),
            AutomationRun::STATUS_CANCELLED => $this->check('Run progress', 'info', 'Run '.$run->id.' was cancelled'.(($goal = $run->goalSubscriptions->firstWhere('status', 'reached')) ? ' because they reached the goal "'.$goal->event?->name.'"' : '').', so later steps did not run.'),
            default => $sent === [] && ! $run->steps->contains(fn (RunStep $step) => in_array($step->type, self::SEND_TYPES, true))
                ? $this->check('Run progress', 'info', 'Run '.$run->id.' completed without reaching a send step: a branch, segment check or split took them another way (see get_run).')
                : $this->check('Run progress', 'pass', 'Run '.$run->id.' completed.'),
        };

        return $sent;
    }

    /** @return array<string, mixed> */
    protected function broadcast(Person $person, Broadcast $broadcast): array
    {
        $broadcast->load('segment:id,name', 'channelConfiguration:id,name,driver,type', 'subscriptionTopic:id,name');
        $recipient = BroadcastRecipient::query()->where('broadcast_id', $broadcast->id)->where('person_id', $person->id)->with('message')->first();
        $membership = $broadcast->segment_id
            ? DB::table('segment_person')->where('segment_id', $broadcast->segment_id)->where('person_id', $person->id)->first()
            : null;
        $segment = $broadcast->segment?->name ?? 'its segment';
        $sent = [];

        if ($broadcast->isEditable()) {
            $this->check('Broadcast sent', 'info', 'It is still a draft, so nobody has received it. The checks below say whether this person would.');
            $this->check('In the audience', $membership ? 'pass' : 'fail', $membership ? "They are in segment \"{$segment}\"." : "They are not in segment \"{$segment}\", so they would not receive it.");
        } elseif ($recipient) {
            $message = $recipient->message;
            match (true) {
                in_array($recipient->status, ['skipped', 'failed'], true) => $this->check('Recipient', 'fail', ucfirst($recipient->status).': '.$this->context->redactor->text($recipient->error ?? 'no reason recorded').'.'),
                in_array($recipient->status, ['queued', 'sending'], true) => $this->check('Recipient', 'info', 'Still '.$recipient->status.'.'),
                $message && in_array($message->status, ['failed', 'bounced'], true) => $this->check('Provider result', 'fail', 'Handed to the provider and '.$message->status.': '.$this->context->redactor->text($message->error ?? 'no error recorded').'.'),
                default => $this->check('Provider result', 'pass', ($message?->status ?? $recipient->status).($message?->delivered_at ? ', delivered '.$this->context->time($message->delivered_at) : ($recipient->sent_at ? ', sent '.$this->context->time($recipient->sent_at) : '')).'.'),
            };

            if ($recipient->status === 'sent' && (! $message || ! in_array($message->status, ['failed', 'bounced'], true))) {
                $sent[] = ['channel' => $broadcast->channel, 'what' => 'Broadcast "'.$broadcast->name.'"', 'status' => $message?->status ?? 'sent', 'at' => $this->context->time($message?->delivered_at ?? $recipient->sent_at)];
            }
        } elseif ($membership && $broadcast->started_at && Carbon::parse($membership->added_at)->gt($broadcast->started_at)) {
            $this->check('In the audience', 'fail', "They joined segment \"{$segment}\" at ".$this->context->time($membership->added_at).', after the broadcast started at '.$this->context->time($broadcast->started_at).'. A broadcast goes to the members at send time.');
        } elseif ($membership) {
            $this->check('In the audience', 'warn', "They are in segment \"{$segment}\" now but were not a recipient: they were not a member when it sent, or it is still being sent.");
        } else {
            $this->check('In the audience', 'fail', "They are not in segment \"{$segment}\", so the broadcast did not go to them.");
        }

        $this->reachability(
            $person,
            $broadcast->channel,
            $broadcast->channel_id,
            $broadcast->subscription_topic_id ? collect([$broadcast->name => $broadcast->subscription_topic_id]) : collect(),
        );

        return [
            'verdict' => $sent !== [] ? $this->sentVerdict($sent) : $this->verdict(),
            'broadcast' => [
                ...$broadcast->only('id', 'name', 'channel', 'status'),
                'segment' => $broadcast->segment?->only('id', 'name'),
                'started_at' => $this->context->time($broadcast->started_at),
                'url' => $this->context->url($broadcast->isEditable() ? 'broadcasts.edit' : 'broadcasts.show', $broadcast),
            ],
            'recipient' => $recipient ? [
                'status' => $recipient->status,
                'reason' => $this->context->redactor->text($recipient->error),
                'message_id' => $recipient->message_id,
                'sent_at' => $this->context->time($recipient->sent_at),
            ] : null,
        ];
    }

    /**
     * Whether a channel can reach the person today: an address the driver
     * can use, no suppression, and no opt-out from the topics in play.
     *
     * @param  Collection<string, int>  $topicIds  template or broadcast name => topic id
     */
    protected function reachability(Person $person, string $channelType, ?int $channelId, Collection $topicIds): void
    {
        $channel = Channel::query()->where('workspace_id', $person->workspace_id)->where('type', $channelType)
            ->when($channelId, fn ($query) => $query->whereKey($channelId), fn ($query) => $query->orderByDesc('is_default'))
            ->first();
        $label = match ($channelType) {
            'sms' => 'SMS',
            'push' => 'Push',
            default => 'Email',
        };

        if (! $channel) {
            $this->check("{$label} channel", 'fail', "There is no {$channelType} channel set up, so {$channelType} steps fail.");

            return;
        }

        $attributes = $person->properties();
        [$hasAddress, $missing] = match ($channelType) {
            'email' => [filled($person->email), 'no email address on the profile'],
            'sms' => [filled($person->phone), 'no phone number on the profile'],
            default => $channel->driver === 'expo'
                ? [collect(Arr::wrap($attributes['expo_push_tokens'] ?? $attributes['expo_push_token'] ?? []))->contains(fn ($token) => is_string($token) && preg_match('/^Expo(nent)?PushToken\[[^\]]+\]$/', trim($token)) === 1), 'no Expo push token on the profile (the app has not registered this device)']
                : [filled($attributes['onesignal_external_id'] ?? $person->external_id), 'no external id or OneSignal id to address the push to'],
        };

        $suppression = $person->suppressions()->whereIn('channel', [$channelType, 'all'])->first();
        $optedOut = $topicIds->filter(fn ($topicId) => ! $this->subscriptions->allowsTopic($person, (int) $topicId));

        $problems = array_filter([
            $hasAddress ? null : $missing,
            $person->unsubscribed_at ? 'they unsubscribed from everything on '.$this->context->time($person->unsubscribed_at) : null,
            $suppression ? "suppressed for {$suppression->channel} ({$suppression->reason}) since ".$this->context->time($suppression->created_at) : null,
            $optedOut->isNotEmpty() ? 'opted out of the subscription topic used by '.$optedOut->keys()->map(fn ($name) => '"'.$name.'"')->implode(', ') : null,
        ]);

        $this->check(
            "{$label} reachable today",
            $problems === [] ? 'pass' : 'fail',
            $problems === []
                ? "Yes, through {$channel->name} ({$channel->driver})."
                : 'No: '.implode('; ', $problems).". {$label} sends to them are skipped.",
        );
    }

    protected function check(string $check, string $result, string $detail): void
    {
        $this->checks[] = ['check' => $check, 'result' => $result, 'detail' => $detail];
    }

    protected function verdict(): string
    {
        $checks = collect($this->checks);
        $first = $checks->firstWhere('result', 'fail') ?? $checks->firstWhere('result', 'warn');

        if ($first) {
            return $first['detail'];
        }

        // Nothing is wrong, but nothing has gone out yet either: say where it is.
        $progress = $checks->first(fn (array $check) => $check['check'] === 'Run progress' && $check['result'] === 'info');

        return $progress
            ? 'Not sent yet, and nothing is blocking it: '.lcfirst($progress['detail'])
            : 'No problem found: nothing stopped a message, and every channel can reach them.';
    }

    /** @param  array<int, array{channel: string, what: string, status: string, at: ?string}>  $sent */
    protected function sentVerdict(array $sent): string
    {
        $latest = end($sent);
        $problem = collect($this->checks)->firstWhere('result', 'fail');

        return 'Sent: '.$latest['what'].' is '.$latest['status'].($latest['at'] ? ' ('.$latest['at'].')' : '').'.'
            .(count($sent) > 1 ? ' '.count($sent).' messages in all from the latest run.' : '')
            .($problem ? ' But note: '.$problem['detail'] : '');
    }
}
