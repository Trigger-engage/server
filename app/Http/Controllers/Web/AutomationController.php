<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Automation;
use App\Models\Channel;
use App\Models\Event;
use App\Models\Template;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AutomationController extends Controller
{
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
            'steps.*.type' => ['required', Rule::in(['delay', 'wait_for_event', 'send_email', 'send_sms', 'send_push'])],
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

    /** @return array<int, array<string, mixed>> */
    protected function editableSteps(Automation $automation): array
    {
        return collect($automation->activeVersion?->graph['nodes'] ?? [])
            ->reject(fn (array $node) => in_array($node['type'], ['trigger', 'exit'], true))
            ->reject(fn (array $node) => (bool) ($node['config']['generated_for_wait'] ?? false))
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
        $generatedTimeoutNodes = [];

        foreach ($steps as $index => $step) {
            $id = 'step_'.($index + 1);

            if ($step['type'] === 'delay') {
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
                    $generatedTimeoutNodes[$id] = [
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

            if (isset($generatedTimeoutNodes[$node['id']])) {
                $nodes[] = $generatedTimeoutNodes[$node['id']];
            }
        }

        $nodes[] = ['id' => 'exit', 'type' => 'exit', 'config' => []];
        $edges = [[
            'from' => 'trigger',
            'to' => $visibleNodes[0]['id'] ?? 'exit',
        ]];

        foreach ($visibleNodes as $index => $node) {
            $nextId = $visibleNodes[$index + 1]['id'] ?? 'exit';

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
