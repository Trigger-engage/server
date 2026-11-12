<?php

namespace TriggerEngage\Server\Mcp\Support;

use Illuminate\Support\Collection;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

/**
 * Turns an automation graph into steps an agent can read: each node with a
 * one-line summary in plain words, the ids it points at, and the template,
 * channel, event and segment names its config refers to by id.
 *
 * It walks the graph from the trigger the way the engine does, so the order
 * is the order a person moves through the journey. Nodes no edge reaches are
 * listed last and marked, because the engine will never run them.
 */
class GraphDescriber
{
    /** @var Collection<int, Template>|null */
    protected ?Collection $templates = null;

    /** @var Collection<int, Channel>|null */
    protected ?Collection $channels = null;

    /** @var Collection<int, Event>|null */
    protected ?Collection $events = null;

    /** @var Collection<int, Segment>|null */
    protected ?Collection $segments = null;

    public function __construct(protected Workspace $workspace) {}

    /**
     * @return array{steps: array<int, array<string, mixed>>, goals: array<int, array<string, mixed>>}
     */
    public function describe(array $graph): array
    {
        $nodes = collect($graph['nodes'] ?? [])->filter(fn ($node) => is_array($node) && isset($node['id']))->keyBy('id');
        $edges = collect($graph['edges'] ?? [])->filter(fn ($edge) => is_array($edge) && isset($edge['from'], $edge['to']));

        $order = [];
        $queue = $nodes->filter(fn ($node) => ($node['type'] ?? null) === 'trigger')->keys()->all();
        while ($queue !== []) {
            $id = array_shift($queue);
            if (isset($order[$id]) || ! $nodes->has($id)) {
                continue;
            }
            $order[$id] = true;
            foreach ($edges->where('from', $id) as $edge) {
                $queue[] = $edge['to'];
            }
        }

        $steps = [];
        foreach (array_keys($order) as $id) {
            $steps[] = $this->step($nodes[$id], $edges);
        }
        foreach ($nodes->keys()->diff(array_keys($order)) as $id) {
            $steps[] = [...$this->step($nodes[$id], $edges), 'unreachable' => true];
        }

        return [
            'steps' => $steps,
            'goals' => collect($graph['goals'] ?? [])->filter(fn ($goal) => is_array($goal))->map(fn (array $goal) => [
                'id' => $goal['id'] ?? null,
                'summary' => 'Ends the run early when '.$this->eventName($goal['event_id'] ?? null).' happens'.$this->matchRules($goal['match_rules'] ?? []),
                'event_id' => $goal['event_id'] ?? null,
            ])->values()->all(),
        ];
    }

    /** One node in plain words. */
    public function summary(array $node): string
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];

        return match ($node['type'] ?? null) {
            'trigger' => $this->triggerSummary($config),
            'delay' => $this->delaySummary($config),
            'branch' => 'If '.$this->condition($config).' (true / false paths)',
            'segment' => 'If the person is '.(($config['in'] ?? true) === false ? 'not ' : '').'in segment '.$this->segmentName($config['segment_id'] ?? null).' (true / false paths)',
            'split' => 'A/B split: '.collect($config['variants'] ?? [])->map(fn ($variant, $index) => ($variant['label'] ?? $variant['name'] ?? $variant['key'] ?? $index).' (weight '.max(1, (int) ($variant['weight'] ?? 1)).')')->implode(', '),
            'wait_for_event' => 'Wait up to '.$this->duration($config, 'timeout_').' for '.$this->eventName($config['event_id'] ?? null).$this->matchRules($config['match_rules'] ?? []).' (matched / timed-out paths)',
            'send_email', 'send_sms', 'send_push' => $this->sendSummary($node['type'], $config),
            'exit' => 'End of journey',
            default => 'Unknown step type "'.($node['type'] ?? '?').'": the engine skips it',
        };
    }

    /** @return array<string, mixed> */
    protected function step(array $node, Collection $edges): array
    {
        $config = is_array($node['config'] ?? null) ? $node['config'] : [];
        $step = [
            'id' => $node['id'],
            'type' => $node['type'] ?? null,
            'summary' => $this->summary($node),
            'next' => $edges->where('from', $node['id'])->map(fn (array $edge) => array_filter([
                'branch' => $edge['branch'] ?? null,
                'to' => $edge['to'],
            ], fn ($value) => $value !== null))->values()->all(),
        ];

        if (in_array($step['type'], ['send_email', 'send_sms', 'send_push'], true)) {
            $step['template_id'] = $config['template_id'] ?? null;
            $step['channel_id'] = $config['channel_id'] ?? null;
            $step['on_failure'] = $config['on_failure'] ?? 'continue';
        }

        return $step;
    }

    protected function triggerSummary(array $config): string
    {
        $filters = collect($config['filters'] ?? [])->filter(fn ($filter) => is_array($filter) && filled($filter['field'] ?? null))
            ->map(fn (array $filter) => $this->condition([
                'field' => str_starts_with($filter['field'], 'event.') ? $filter['field'] : 'event.'.$filter['field'],
                'operator' => $filter['operator'] ?? 'equals',
                'value' => $filter['value'] ?? null,
            ]));

        return 'Starts on the trigger event'.($filters->isEmpty() ? '' : ' when '.$filters->implode(' and '));
    }

    protected function delaySummary(array $config): string
    {
        if (isset($config['until_time'])) {
            return 'Wait until '.$config['until_time'].' (workspace time), the next day if that time has passed';
        }

        return 'Wait '.$this->duration($config);
    }

    protected function duration(array $config, string $prefix = ''): string
    {
        $parts = [];
        foreach (['days' => 'day', 'hours' => 'hour', 'minutes' => 'minute'] as $key => $unit) {
            $value = (int) ($config[$prefix.$key] ?? 0);
            if ($value > 0) {
                $parts[] = $value.' '.$unit.($value === 1 ? '' : 's');
            }
        }

        return $parts === [] ? 'no time' : implode(' ', $parts);
    }

    protected function condition(array $condition): string
    {
        $operator = str_replace('_', ' ', (string) ($condition['operator'] ?? 'equals'));
        $field = (string) ($condition['field'] ?? '?');

        if (in_array($condition['operator'] ?? null, ['exists', 'not_exists'], true)) {
            return "{$field} {$operator}";
        }

        return "{$field} {$operator} ".json_encode($condition['value'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    protected function matchRules(mixed $rules): string
    {
        if (! is_array($rules) || $rules === []) {
            return '';
        }

        return ' where '.collect($rules)->filter(fn ($rule) => is_array($rule))->map(function (array $rule): string {
            $source = $rule['source'] ?? 'trigger_event';
            $expected = match ($source) {
                'literal' => json_encode($rule['value'] ?? null, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'person' => 'person.'.($rule['source_field'] ?? ''),
                default => 'trigger event.'.($rule['source_field'] ?? ''),
            };

            return 'its '.($rule['incoming_field'] ?? '?').' '.str_replace('_', ' ', (string) ($rule['operator'] ?? 'equals')).' '.$expected;
        })->implode(' and ');
    }

    protected function sendSummary(string $type, array $config): string
    {
        $channelType = substr($type, 5);
        $template = $this->templates()->get((int) ($config['template_id'] ?? 0));
        $channel = filled($config['channel_id'] ?? null)
            ? $this->channels()->get((int) $config['channel_id'])
            : $this->channels()->where('type', $channelType)->sortByDesc('is_default')->first();

        $label = match ($channelType) {
            'sms' => 'SMS',
            'push' => 'push',
            default => 'email',
        };

        return 'Send '.$label.' '.($template ? '"'.$template->name.'"' : '(template '.($config['template_id'] ?? 'missing').' not found)')
            .' via '.($channel ? $channel->name.' ('.$channel->driver.')' : 'no '.$label.' channel')
            .(filled($config['channel_id'] ?? null) ? '' : ', the default channel');
    }

    /** A segment rule group in plain words, nested groups in brackets. */
    public function rules(?array $group): string
    {
        $conditions = collect($group['conditions'] ?? [])->filter(fn ($condition) => is_array($condition));

        if ($conditions->isEmpty()) {
            return 'no conditions (matches nobody)';
        }

        $joiner = ($group['match'] ?? 'all') === 'any' ? ' OR ' : ' AND ';

        return $conditions->map(function (array $condition): string {
            return match ($condition['kind'] ?? 'attribute') {
                'group' => '('.$this->rules($condition).')',
                'segment' => (($condition['in'] ?? true) === false ? 'not in' : 'in').' segment '.$this->segmentName($condition['segment_id'] ?? null),
                'event' => $this->eventRule($condition),
                default => $this->condition([...$condition, 'field' => 'person.'.($condition['field'] ?? '?')]),
            };
        })->implode($joiner);
    }

    protected function eventRule(array $condition): string
    {
        $event = $this->eventName($condition['event_id'] ?? null);
        $within = (int) ($condition['within_days'] ?? 0) > 0 ? ' in the last '.(int) $condition['within_days'].' days' : '';

        if (($condition['performed'] ?? true) === false) {
            return "never did {$event}{$within}";
        }

        $count = max(1, (int) ($condition['count'] ?? 1));
        $times = match ($condition['count_operator'] ?? 'gte') {
            'lte' => "at most {$count}",
            'eq' => "exactly {$count}",
            default => "at least {$count}",
        };

        return "did {$event} {$times} time".($count === 1 ? '' : 's').$within;
    }

    public function eventName(mixed $id): string
    {
        $event = $this->events()->get((int) $id);

        return $event ? '"'.$event->name.'"' : 'event '.($id ?? '?').' (not found)';
    }

    public function segmentName(mixed $id): string
    {
        $segment = $this->segments()->get((int) $id);

        return $segment ? '"'.$segment->name.'"' : 'segment '.($id ?? '?').' (not found)';
    }

    /** @return Collection<int, Template> */
    protected function templates(): Collection
    {
        return $this->templates ??= Template::query()->where('workspace_id', $this->workspace->id)->get(['id', 'name', 'channel'])->keyBy('id');
    }

    /** @return Collection<int, Channel> */
    protected function channels(): Collection
    {
        return $this->channels ??= Channel::query()->where('workspace_id', $this->workspace->id)->get(['id', 'name', 'type', 'driver', 'is_default'])->keyBy('id');
    }

    /** @return Collection<int, Event> */
    protected function events(): Collection
    {
        return $this->events ??= Event::query()->where('workspace_id', $this->workspace->id)->get(['id', 'name'])->keyBy('id');
    }

    /** @return Collection<int, Segment> */
    protected function segments(): Collection
    {
        return $this->segments ??= Segment::query()->where('workspace_id', $this->workspace->id)->get(['id', 'name'])->keyBy('id');
    }
}
