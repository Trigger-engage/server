<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\RunGoalSubscription;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Template;

class AutomationController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');

        return Inertia::render('Automations/Index', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
            'automations' => $workspace->automations()
                ->with('triggerEvent:id,name')
                ->withCount('runs')
                ->latest()
                ->get(['id', 'name', 'status', 'trigger_event_id', 'reentry_policy', 'active_version_id', 'updated_at']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'trigger_event_id' => [
                'required',
                Rule::exists('events', 'id')->where('workspace_id', $workspace->id),
            ],
            'reentry_policy' => ['required', Rule::in([
                Automation::REENTRY_EVERY_TIME,
                Automation::REENTRY_ONE_ACTIVE,
                Automation::REENTRY_ONCE_EVER,
            ])],
        ]);

        $automation = $workspace->automations()->create($validated);

        return redirect()->route('engage.automations.edit', $automation)
            ->with('success', 'Automation draft created. Add steps and publish it.');
    }

    public function edit(Request $request, Automation $automation): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);
        $automation->load('triggerEvent:id,name', 'activeVersion:id,automation_id,graph,published_at');

        return Inertia::render('Automations/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'automation' => [
                ...$automation->only('id', 'name', 'status', 'reentry_policy'),
                'trigger_event' => $automation->triggerEvent?->only('id', 'name'),
                'steps' => $this->editableSteps($automation),
                'goal' => $this->editableGoal($automation),
                'published_at' => $automation->activeVersion?->published_at,
            ],
            'abTests' => $this->abTestResults($automation),
            'templates' => $workspace->templates()
                ->orderBy('name')
                ->get(['id', 'channel', 'name', 'subject']),
            'channels' => $workspace->channels()
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'type', 'name', 'driver', 'is_default']),
            'events' => $workspace->events()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function publish(Request $request, Automation $automation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);

        $validated = $request->validate([
            'steps' => ['present', 'array', 'max:100'],
            'steps.*.type' => ['required', Rule::in(['delay', 'wait_for_event', 'send_email', 'send_sms', 'send_push', 'split'])],
            'steps.*.variants' => ['required_if:steps.*.type,split', 'array', 'min:2', 'max:4'],
            'steps.*.variants.*.key' => ['required_with:steps.*.variants', 'string', 'max:20'],
            'steps.*.variants.*.weight' => ['nullable', 'integer', 'between:1,100'],
            'steps.*.variants.*.type' => ['required_with:steps.*.variants', Rule::in(['email', 'sms', 'push'])],
            'steps.*.variants.*.template_id' => ['nullable', 'integer'],
            'steps.*.variants.*.channel_id' => ['nullable', 'integer'],
            'steps.*.variants.*.retry_attempts' => ['nullable', 'integer', 'between:1,10'],
            'steps.*.variants.*.on_failure' => ['nullable', Rule::in(['continue', 'fail'])],
            'steps.*.days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'steps.*.hours' => ['nullable', 'integer', 'min:0', 'max:23'],
            'steps.*.minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'steps.*.until_time' => ['nullable', 'date_format:H:i'],
            'steps.*.template_id' => ['nullable', 'integer'],
            'steps.*.channel_id' => ['nullable', 'integer'],
            'steps.*.retry_attempts' => ['nullable', 'integer', 'between:1,10'],
            'steps.*.on_failure' => ['nullable', Rule::in(['continue', 'fail'])],
            'steps.*.event_id' => [
                'nullable',
                Rule::exists('events', 'id')->where('workspace_id', $workspace->id),
            ],
            'steps.*.timeout_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'steps.*.timeout_hours' => ['nullable', 'integer', 'min:0', 'max:23'],
            'steps.*.timeout_minutes' => ['nullable', 'integer', 'min:0', 'max:59'],
            'steps.*.incoming_field' => ['nullable', 'string', 'max:150'],
            'steps.*.trigger_field' => ['nullable', 'string', 'max:150'],
            'steps.*.match_operator' => ['nullable', Rule::in(['equals', 'not_equals'])],
            'steps.*.timeout_action' => ['nullable', Rule::in(['continue', 'exit', 'send_email', 'send_sms', 'send_push'])],
            'steps.*.timeout_template_id' => ['nullable', 'integer'],
            'steps.*.timeout_channel_id' => ['nullable', 'integer'],
            'steps.*.timeout_retry_attempts' => ['nullable', 'integer', 'between:1,10'],
            'steps.*.timeout_on_failure' => ['nullable', Rule::in(['continue', 'fail'])],
            'goal' => ['nullable', 'array'],
            'goal.enabled' => ['required_with:goal', 'boolean'],
            'goal.event_id' => [
                'exclude_unless:goal.enabled,true',
                'nullable',
                Rule::exists('events', 'id')->where('workspace_id', $workspace->id),
            ],
            'goal.incoming_field' => ['exclude_unless:goal.enabled,true', 'nullable', 'string', 'max:150'],
            'goal.trigger_field' => ['exclude_unless:goal.enabled,true', 'nullable', 'string', 'max:150'],
            'goal.match_operator' => ['exclude_unless:goal.enabled,true', 'nullable', Rule::in(['equals', 'not_equals'])],
        ]);

        $graph = $this->buildGraph($workspace->id, $validated['steps'], $validated['goal'] ?? null);
        $automation->publish($graph);

        return back()->with('success', 'Automation published as a new immutable version.');
    }

    public function pause(Request $request, Automation $automation): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $automation);
        $automation->update(['status' => 'paused']);

        return back()->with('success', 'Automation paused. Existing runs are unchanged.');
    }

    protected function ensureWorkspaceOwns(int $workspaceId, Automation $automation): void
    {
        abort_unless($automation->workspace_id === $workspaceId, 404);
    }

    /**
     * Per-variant results for every A/B split in the published graph. Conversion
     * counts goal completions when the automation has a goal, otherwise runs that
     * finished the journey.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function abTestResults(Automation $automation): array
    {
        $graph = $automation->activeVersion?->graph ?? [];
        $splits = collect($graph['nodes'] ?? [])->where('type', 'split');

        if ($splits->isEmpty()) {
            return [];
        }

        $goalBased = ! empty($graph['goals']);

        return $splits->map(function (array $node) use ($automation, $goalBased): array {
            $steps = RunStep::query()
                ->where('node_id', $node['id'])
                ->where('type', 'split')
                ->whereHas('run', fn ($q) => $q->where('automation_id', $automation->id))
                ->with('run:id,status')
                ->get(['id', 'automation_run_id', 'node_id', 'output']);

            $reached = $goalBased
                ? RunGoalSubscription::query()
                    ->where('status', RunGoalSubscription::STATUS_REACHED)
                    ->whereIn('automation_run_id', $steps->pluck('automation_run_id'))
                    ->pluck('automation_run_id')
                    ->flip()
                : collect();

            $isConverted = fn (RunStep $step): bool => $goalBased
                ? $reached->has($step->automation_run_id)
                : $step->run?->status === AutomationRun::STATUS_COMPLETED;

            $variants = collect($node['config']['variants'] ?? [])->map(function (array $variant) use ($steps, $isConverted): array {
                $assigned = $steps->where('output.variant', $variant['key']);
                $entered = $assigned->count();
                $converted = $assigned->filter($isConverted)->count();

                return [
                    'key' => $variant['key'],
                    'type' => $variant['type'] ?? 'email',
                    'weight' => $variant['weight'] ?? 50,
                    'entered' => $entered,
                    'converted' => $converted,
                    'rate' => $entered ? round($converted / $entered * 100, 1) : 0.0,
                ];
            })->values()->all();

            return ['node_id' => $node['id'], 'goal_based' => $goalBased, 'variants' => $variants];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    protected function editableSteps(Automation $automation): array
    {
        return collect($automation->activeVersion?->graph['nodes'] ?? [])
            ->reject(fn (array $node) => in_array($node['type'], ['trigger', 'exit'], true))
            ->reject(fn (array $node) => (bool) ($node['config']['generated_for_wait'] ?? false))
            ->reject(fn (array $node) => (bool) ($node['config']['generated_for_split'] ?? false))
            ->map(fn (array $node) => ['type' => $node['type'], ...($node['config'] ?? [])])
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function editableGoal(Automation $automation): array
    {
        $goal = collect($automation->activeVersion?->graph['goals'] ?? [])->first();

        return $goal
            ? [
                'enabled' => true,
                'event_id' => $goal['event_id'],
                'incoming_field' => $goal['incoming_field'] ?? '',
                'trigger_field' => $goal['trigger_field'] ?? '',
                'match_operator' => $goal['match_operator'] ?? 'equals',
            ]
            : [
                'enabled' => false,
                'event_id' => '',
                'incoming_field' => '',
                'trigger_field' => '',
                'match_operator' => 'equals',
            ];
    }

    /** @return array{nodes: array<int, array>, edges: array<int, array>, goals: array<int, array>} */
    protected function buildGraph(int $workspaceId, array $steps, ?array $goal = null): array
    {
        $nodes = [['id' => 'trigger', 'type' => 'trigger', 'config' => []]];
        $visibleNodes = [];
        // visibleId => array of nodes generated for it (event-wait timeout sends,
        // A/B variant send nodes). They execute but never appear as edit steps.
        $generatedNodes = [];

        foreach ($steps as $index => $step) {
            $id = 'step_'.($index + 1);

            if ($step['type'] === 'split') {
                [$config, $variantNodes] = $this->splitConfig($workspaceId, $id, $step);
                $generatedNodes[$id] = $variantNodes;
            } elseif ($step['type'] === 'delay') {
                $config = filled($step['until_time'] ?? null)
                    ? ['until_time' => $step['until_time']]
                    : [
                        'days' => (int) ($step['days'] ?? 0),
                        'hours' => (int) ($step['hours'] ?? 0),
                        'minutes' => (int) ($step['minutes'] ?? 0),
                    ];
            } elseif ($step['type'] === 'wait_for_event') {
                $event = Event::query()
                    ->where('workspace_id', $workspaceId)
                    ->find($step['event_id'] ?? null);

                if (! $event) {
                    throw ValidationException::withMessages([
                        'steps' => 'Every event wait must select an event from this workspace.',
                    ]);
                }

                $config = [
                    'event_id' => $event->id,
                    'event_name' => $event->name,
                    'timeout_days' => (int) ($step['timeout_days'] ?? 0),
                    'timeout_hours' => (int) ($step['timeout_hours'] ?? 0),
                    'timeout_minutes' => (int) ($step['timeout_minutes'] ?? 0),
                    'timeout_action' => $step['timeout_action'] ?? 'continue',
                    'incoming_field' => $step['incoming_field'] ?? '',
                    'trigger_field' => $step['trigger_field'] ?? '',
                    'match_operator' => $step['match_operator'] ?? 'equals',
                ];

                if ($config['timeout_days'] + $config['timeout_hours'] + $config['timeout_minutes'] < 1) {
                    throw ValidationException::withMessages([
                        'steps' => 'Every event wait needs a timeout of at least one minute.',
                    ]);
                }

                if (filled($config['incoming_field']) !== filled($config['trigger_field'])) {
                    throw ValidationException::withMessages([
                        'steps' => 'Event correlation needs both an incoming field and a trigger field, or neither.',
                    ]);
                }

                $config['match_rules'] = filled($config['incoming_field'])
                    ? [[
                        'incoming_field' => $config['incoming_field'],
                        'operator' => $config['match_operator'],
                        'source' => 'trigger_event',
                        'source_field' => $config['trigger_field'],
                    ]]
                    : [];

                if (str_starts_with($config['timeout_action'], 'send_')) {
                    $timeoutConfig = $this->sendConfig($workspaceId, $config['timeout_action'], [
                        'template_id' => $step['timeout_template_id'] ?? null,
                        'channel_id' => $step['timeout_channel_id'] ?? null,
                        'retry_attempts' => $step['timeout_retry_attempts'] ?? 3,
                        'on_failure' => $step['timeout_on_failure'] ?? 'continue',
                    ]);
                    $config += [
                        'timeout_template_id' => $timeoutConfig['template_id'],
                        'timeout_channel_id' => $timeoutConfig['channel_id'],
                        'timeout_retry_attempts' => $timeoutConfig['retry_attempts'],
                        'timeout_on_failure' => $timeoutConfig['on_failure'],
                    ];
                    $generatedNodes[$id][] = [
                        'id' => $id.'__timeout',
                        'type' => $config['timeout_action'],
                        'config' => [...$timeoutConfig, 'generated_for_wait' => $id],
                    ];
                }
            } else {
                $config = $this->sendConfig($workspaceId, $step['type'], $step);
            }

            $visibleNodes[] = ['id' => $id, 'type' => $step['type'], 'config' => $config];
        }

        foreach ($visibleNodes as $node) {
            $nodes[] = $node;

            foreach ($generatedNodes[$node['id']] ?? [] as $generated) {
                $nodes[] = $generated;
            }
        }

        $nodes[] = ['id' => 'exit', 'type' => 'exit', 'config' => []];
        $edges = [[
            'from' => 'trigger',
            'to' => $visibleNodes[0]['id'] ?? 'exit',
        ]];

        foreach ($visibleNodes as $index => $node) {
            $nextId = $visibleNodes[$index + 1]['id'] ?? 'exit';

            if ($node['type'] === 'split') {
                // One branch per variant → its generated send node → converge.
                foreach ($node['config']['variants'] as $variant) {
                    $variantNodeId = $node['id'].'__v_'.$variant['key'];
                    $edges[] = ['from' => $node['id'], 'to' => $variantNodeId, 'branch' => $variant['key']];
                    $edges[] = ['from' => $variantNodeId, 'to' => $nextId];
                }

                continue;
            }

            if ($node['type'] !== 'wait_for_event') {
                $edges[] = ['from' => $node['id'], 'to' => $nextId];

                continue;
            }

            $edges[] = ['from' => $node['id'], 'to' => $nextId, 'branch' => 'matched'];
            $timeoutAction = $node['config']['timeout_action'];

            if (str_starts_with($timeoutAction, 'send_')) {
                $timeoutNodeId = $node['id'].'__timeout';
                $edges[] = ['from' => $node['id'], 'to' => $timeoutNodeId, 'branch' => 'timed_out'];
                $edges[] = ['from' => $timeoutNodeId, 'to' => $nextId];
            } else {
                $edges[] = [
                    'from' => $node['id'],
                    'to' => $timeoutAction === 'exit' ? 'exit' : $nextId,
                    'branch' => 'timed_out',
                ];
            }
        }

        return ['nodes' => $nodes, 'edges' => $edges, 'goals' => $this->buildGoals($workspaceId, $goal)];
    }

    /** @return array<int, array<string, mixed>> */
    protected function buildGoals(int $workspaceId, ?array $goal): array
    {
        if (! ($goal['enabled'] ?? false)) {
            return [];
        }

        $event = Event::query()->where('workspace_id', $workspaceId)->find($goal['event_id'] ?? null);

        if (! $event) {
            throw ValidationException::withMessages([
                'goal.event_id' => 'Select a goal event from this workspace.',
            ]);
        }

        $incomingField = $goal['incoming_field'] ?? '';
        $triggerField = $goal['trigger_field'] ?? '';

        if (filled($incomingField) !== filled($triggerField)) {
            throw ValidationException::withMessages([
                'goal' => 'Goal correlation needs both an incoming field and a trigger field, or neither.',
            ]);
        }

        $operator = $goal['match_operator'] ?? 'equals';

        return [[
            'id' => 'goal_1',
            'event_id' => $event->id,
            'event_name' => $event->name,
            'incoming_field' => $incomingField,
            'trigger_field' => $triggerField,
            'match_operator' => $operator,
            'match_rules' => filled($incomingField)
                ? [[
                    'incoming_field' => $incomingField,
                    'operator' => $operator,
                    'source' => 'trigger_event',
                    'source_field' => $triggerField,
                ]]
                : [],
        ]];
    }

    /**
     * Build an A/B split node plus one generated send node per variant. The full
     * variant detail lives on the split node's config so the editor can round-trip
     * it; the generated send nodes are what the engine actually executes.
     *
     * @return array{0: array{variants: array<int, array<string, mixed>>}, 1: array<int, array>}
     */
    protected function splitConfig(int $workspaceId, string $stepId, array $step): array
    {
        $variants = array_values($step['variants'] ?? []);

        if (count($variants) < 2) {
            throw ValidationException::withMessages(['steps' => 'An A/B test needs at least two variants.']);
        }

        $seenKeys = [];
        $normalized = [];
        $nodes = [];

        foreach ($variants as $position => $variant) {
            $key = (string) ($variant['key'] ?? chr(65 + $position));

            if (in_array($key, $seenKeys, true)) {
                throw ValidationException::withMessages(['steps' => 'A/B test variant keys must be unique.']);
            }
            $seenKeys[] = $key;

            $channelType = $variant['type'] ?? 'email';
            $send = $this->sendConfig($workspaceId, 'send_'.$channelType, $variant);

            $normalized[] = [
                'key' => $key,
                'weight' => max(1, (int) ($variant['weight'] ?? 50)),
                'type' => $channelType,
                ...$send,
            ];
            $nodes[] = [
                'id' => $stepId.'__v_'.$key,
                'type' => 'send_'.$channelType,
                'config' => [...$send, 'generated_for_split' => $stepId, 'variant' => $key],
            ];
        }

        return [['variants' => $normalized], $nodes];
    }

    /** @return array{template_id: int, channel_id: int, retry_attempts: int, on_failure: string} */
    protected function sendConfig(int $workspaceId, string $type, array $step): array
    {
        $channelType = match ($type) {
            'send_sms' => 'sms',
            'send_push' => 'push',
            default => 'email',
        };
        $templateQuery = Template::query()
            ->where('workspace_id', $workspaceId)
            ->where('channel', $channelType);
        $template = filled($step['template_id'] ?? null)
            ? $templateQuery->find($step['template_id'])
            : $templateQuery->orderBy('name')->first();
        $channelQuery = Channel::query()
            ->where('workspace_id', $workspaceId)
            ->where('type', $channelType);
        $channel = filled($step['channel_id'] ?? null)
            ? $channelQuery->find($step['channel_id'])
            : $channelQuery->orderByDesc('is_default')->orderBy('name')->first();

        if (! $template || ! $channel) {
            throw ValidationException::withMessages([
                'steps' => 'Every send step must use a matching template and channel from this workspace.',
            ]);
        }

        return [
            'template_id' => $template->id,
            'channel_id' => $channel->id,
            'retry_attempts' => (int) ($step['retry_attempts'] ?? 3),
            'on_failure' => $step['on_failure'] ?? 'continue',
        ];
    }
}
