<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;

/**
 * The journey editor's step list, in both directions: read a graph as the
 * steps the editor shows, and build a graph from steps with every check the
 * editor's publish applies. The editor and the MCP drafting tools share it,
 * so a journey an agent drafts is one the editor can open, change and
 * publish without losing anything.
 */
class JourneySteps
{
    /**
     * Validation for a new automation: its name, trigger event and re-entry
     * policy, which live on the automation itself rather than in a version.
     *
     * @return array<string, mixed>
     */
    public function automationRules(int $workspaceId): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'trigger_event_id' => [
                'required',
                Rule::exists('events', 'id')->where('workspace_id', $workspaceId),
            ],
            'reentry_policy' => ['required', Rule::in([
                Automation::REENTRY_EVERY_TIME,
                Automation::REENTRY_ONE_ACTIVE,
                Automation::REENTRY_ONCE_EVER,
            ])],
        ];
    }

    /**
     * Validation for a step list, its goal and its trigger filters, as the
     * editor submits them.
     *
     * @return array<string, mixed>
     */
    public function rules(int $workspaceId): array
    {
        return [
            'steps' => ['present', 'array', 'max:100'],
            'steps.*.type' => ['required', Rule::in(['delay', 'wait_for_event', 'send_email', 'send_sms', 'send_push', 'split', 'segment'])],
            'steps.*.segment_id' => [
                'required_if:steps.*.type,segment',
                'nullable',
                Rule::exists('segments', 'id')->where('workspace_id', $workspaceId),
            ],
            'steps.*.in' => ['nullable', 'boolean'],
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
                Rule::exists('events', 'id')->where('workspace_id', $workspaceId),
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
                Rule::exists('events', 'id')->where('workspace_id', $workspaceId),
            ],
            'goal.incoming_field' => ['exclude_unless:goal.enabled,true', 'nullable', 'string', 'max:150'],
            'goal.trigger_field' => ['exclude_unless:goal.enabled,true', 'nullable', 'string', 'max:150'],
            'goal.match_operator' => ['exclude_unless:goal.enabled,true', 'nullable', Rule::in(['equals', 'not_equals'])],
            'trigger_filters' => ['nullable', 'array', 'max:5'],
            'trigger_filters.*.field' => ['required', 'string', 'max:150'],
            'trigger_filters.*.operator' => ['nullable', Rule::in(['equals', 'not_equals', 'contains', 'exists', 'not_exists', 'gt', 'lt'])],
            'trigger_filters.*.value' => ['nullable'],
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function steps(array $graph): array
    {

        return collect($graph['nodes'] ?? [])
            ->reject(fn (array $node) => in_array($node['type'], ['trigger', 'exit'], true))
            ->reject(fn (array $node) => (bool) ($node['config']['generated_for_wait'] ?? false))
            ->reject(fn (array $node) => (bool) ($node['config']['generated_for_split'] ?? false))
            ->map(function (array $node) use ($graph) {
                $step = ['type' => $node['type'], ...($node['config'] ?? [])];

                if ($node['type'] === 'wait_for_event' && ! isset($step['timeout_action'])) {
                    $step['timeout_action'] = $this->inferTimeoutAction($graph, $node['id']);
                }

                return $step;
            })
            ->values()
            ->all();
    }

    /**
     * Graphs authored outside this editor — the seeders, the API, an import — carry no
     * `timeout_action`, which is an editor-level concept that buildGraph() writes out as
     * edges. Recover it from those edges so such an automation opens instead of handing
     * the page an undefined value.
     *
     * @param  array<string, mixed>  $graph
     */
    protected function inferTimeoutAction(array $graph, string $nodeId): string
    {
        $timeoutEdge = collect($graph['edges'] ?? [])
            ->first(fn (array $edge) => ($edge['branch'] ?? null) === 'timed_out'
                && ($edge['from'] ?? null) === $nodeId);

        $target = $timeoutEdge['to'] ?? null;

        if ($target === null || $target === 'exit') {
            return 'exit';
        }

        $targetNode = collect($graph['nodes'] ?? [])->firstWhere('id', $target);

        // Seeded/API graphs often name their exit node something else ('done');
        // recognise it by TYPE, or a timeout that stops the run would round-trip
        // as 'continue' and quietly march people into the next step.
        if (($targetNode['type'] ?? null) === 'exit') {
            return 'exit';
        }

        // buildGraph() routes a send-on-timeout through a node it generated for this wait.
        return ($targetNode['config']['generated_for_wait'] ?? null) === $nodeId
            ? (string) $targetNode['type']
            : 'continue';
    }

    /** @return array<string, mixed> */
    public function goal(array $graph): array
    {
        $goal = collect($graph['goals'] ?? [])->first();

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

    /** Trigger-node payload filters as the edit form shows them. */
    public function triggerFilters(array $graph): array
    {
        $trigger = collect($graph['nodes'] ?? [])
            ->firstWhere('type', 'trigger');

        return collect($trigger['config']['filters'] ?? [])
            ->map(fn (array $filter) => [
                'field' => (string) ($filter['field'] ?? ''),
                'operator' => $filter['operator'] ?? 'equals',
                'value' => $filter['value'] ?? '',
            ])
            ->values()
            ->all();
    }

    /** @return array{nodes: array<int, array>, edges: array<int, array>, goals: array<int, array>} */
    public function build(int $workspaceId, array $steps, ?array $goal = null, array $triggerFilters = []): array
    {
        $filters = collect($triggerFilters)
            ->filter(fn ($filter) => is_array($filter) && filled($filter['field'] ?? null))
            ->map(fn (array $filter) => [
                'field' => trim((string) $filter['field']),
                'operator' => $filter['operator'] ?? 'equals',
                'value' => $filter['value'] ?? null,
            ])
            ->values()
            ->all();

        $nodes = [['id' => 'trigger', 'type' => 'trigger', 'config' => $filters === [] ? [] : ['filters' => $filters]]];
        $visibleNodes = [];
        // visibleId => array of nodes generated for it (event-wait timeout sends,
        // A/B variant send nodes). They execute but never appear as edit steps.
        $generatedNodes = [];

        foreach ($steps as $index => $step) {
            $id = 'step_'.($index + 1);

            try {
                $config = $this->stepConfig($workspaceId, $id, $index, $step, $generatedNodes);
            } catch (ValidationException $exception) {
                throw $this->stepError($index, $exception);
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

            if ($node['type'] === 'segment') {
                // A filter: matching people continue, everyone else exits.
                $edges[] = ['from' => $node['id'], 'to' => $nextId, 'branch' => 'true'];
                $edges[] = ['from' => $node['id'], 'to' => 'exit', 'branch' => 'false'];

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

    /**
     * Config for one editor step, exactly as the old inline loop built it.
     * Extracted so a ValidationException can be re-keyed to the step it names.
     *
     * @param  array<string, array<int, array>>  $generatedNodes
     * @return array<string, mixed>
     */
    protected function stepConfig(int $workspaceId, string $id, int $index, array $step, array &$generatedNodes): array
    {
        if ($step['type'] === 'split') {
            [$config, $variantNodes] = $this->splitConfig($workspaceId, $id, $step);
            $generatedNodes[$id] = $variantNodes;
        } elseif ($step['type'] === 'segment') {
            $segment = Segment::query()
                ->where('workspace_id', $workspaceId)
                ->find($step['segment_id'] ?? null);

            if (! $segment) {
                throw ValidationException::withMessages([
                    'steps' => 'Every segment filter must select a segment from this workspace.',
                ]);
            }

            $config = [
                'segment_id' => $segment->id,
                'segment_name' => $segment->name,
                'in' => ($step['in'] ?? true) !== false,
            ];
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

        return $config;
    }

    /** Re-key a step's validation messages to the step, naming it for the user. */
    protected function stepError(int $index, ValidationException $exception): ValidationException
    {
        return ValidationException::withMessages(
            collect($exception->errors())->mapWithKeys(fn (array $messages, string $key) => [
                $key === 'steps' ? 'steps.'.$index : $key => array_map(
                    fn (string $message) => str_starts_with($message, 'Step ') ? $message : 'Step '.($index + 1).': '.$message,
                    $messages
                ),
            ])->all()
        );
    }
}
