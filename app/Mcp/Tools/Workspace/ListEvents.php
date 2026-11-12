<?php

namespace TriggerEngage\Server\Mcp\Tools\Workspace;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Support\KeySampler;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;

class ListEvents extends Tool
{
    protected const PAYLOAD_SAMPLE = 20;

    public function name(): string
    {
        return 'list_events';
    }

    public function title(): string
    {
        return 'List events';
    }

    public function description(): string
    {
        return 'The events the host app sends (e.g. "first_session_booked"), with how many times each has occurred, when '
            .'it was last seen, the payload keys it carries (readable in templates as {{ event.<key> }} and in trigger '
            .'filters), and which automations use it as a trigger, a wait or a goal. Payload keys come from the declared '
            .'schema or, without one, from the most recent occurrences.';
    }

    public function properties(): array
    {
        return [
            'search' => ['type' => 'string', 'description' => 'Only events whose name contains this text.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:150'], ...$this->paginationRules()];
    }

    public function handle(array $arguments, Context $context): array
    {
        $usage = new AutomationIndex($context->workspace);
        $query = Event::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['search'] ?? null, fn ($query, $search) => $query->where('name', 'like', '%'.$search.'%'))
            ->withCount('occurrences')
            ->withMax('occurrences', 'occurred_at')
            ->orderBy('name');

        return $this->page($query, $arguments, fn (Event $event) => [
            'id' => $event->id,
            'name' => $event->name,
            'occurrences' => (int) $event->occurrences_count,
            'first_seen_at' => $context->time($event->first_seen_at),
            'last_seen_at' => $context->time($event->occurrences_max_occurred_at),
            'payload_keys' => $this->payloadKeys($event, $context),
            'used_by_automations' => $usage->usingEvent($event->id),
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function payloadKeys(Event $event, Context $context): array
    {
        $sampler = new KeySampler($context->redactor);

        if (is_array($event->payload_schema) && $event->payload_schema !== []) {
            $sampler->add($event->payload_schema);

            return collect($sampler->keys())->map(fn (array $key) => ['key' => $key['key'], 'source' => 'schema'])->all();
        }

        EventOccurrence::query()
            ->where('event_id', $event->id)
            ->orderByDesc('id')
            ->limit(self::PAYLOAD_SAMPLE)
            ->pluck('payload')
            ->each(fn ($payload) => $sampler->add(is_array($payload) ? $payload : []));

        return collect($sampler->keys())->map(fn (array $key) => [
            'key' => $key['key'],
            'types' => $key['types'],
            'seen_in' => $key['seen_in'].' of last '.$sampler->count(),
            'example' => $key['example'],
        ])->all();
    }
}
