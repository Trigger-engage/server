<?php

namespace TriggerEngage\Server\Mcp\Tools\People;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Suppression;
use TriggerEngage\Server\Services\Subscriptions;

class GetPerson extends Tool
{
    public function __construct(protected Subscriptions $subscriptions) {}

    public function name(): string
    {
        return 'get_person';
    }

    public function title(): string
    {
        return 'Get a person';
    }

    public function description(): string
    {
        return 'One person\'s full picture: profile attributes, the segments they are in, whether each channel can reach '
            .'them (has an address, not suppressed or unsubscribed, and why), their topic subscriptions, and their most '
            .'recent events, automation runs and messages. Contact details are masked unless the operator turned that off.';
    }

    public function properties(): array
    {
        return [
            ...$this->personProperties(),
            'recent' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'How many recent events, runs and messages to include (default 10).'],
        ];
    }

    public function rules(): array
    {
        return [...$this->personRules(), 'recent' => ['nullable', 'integer', 'min:1', 'max:50']];
    }

    public function handle(array $arguments, Context $context): array
    {
        $person = $this->findPerson($arguments, $context);
        $recent = (int) ($arguments['recent'] ?? 10);
        $person->loadCount(['segments', 'occurrences', 'automationRuns', 'messages']);
        $state = $this->subscriptions->state($person);

        return [
            'person' => [
                ...$context->person($person),
                'created_at' => $context->time($person->created_at),
                'updated_at' => $context->time($person->updated_at),
                'unsubscribed_from_everything_at' => $context->time($person->unsubscribed_at),
                'attributes' => $context->redactor->data($person->properties()),
                'url' => $context->url('people.show', $person),
            ],
            'counts' => [
                'segments' => $person->segments_count,
                'events' => $person->occurrences_count,
                'runs' => $person->automation_runs_count,
                'messages' => $person->messages_count,
            ],
            'channels' => collect($state['channels'])->map(fn (array $channel) => [
                ...$channel,
                'since' => $context->time($channel['since']),
            ])->all(),
            'topics' => collect($state['topics'])->map(fn (array $topic) => [
                ...collect($topic)->only('key', 'name', 'subscribed', 'decided_by', 'source', 'property')->all(),
                'since' => $context->time($topic['since'] ?? null),
            ])->values()->all(),
            'suppressions' => $person->suppressions()->orderBy('channel')->get()->map(fn (Suppression $suppression) => [
                'channel' => $suppression->channel,
                'reason' => $suppression->reason,
                'since' => $context->time($suppression->created_at),
            ])->values()->all(),
            'segments' => $person->segments()->orderBy('name')->get(['segments.id', 'segments.name', 'segments.type'])
                ->map(fn (Segment $segment) => [
                    'id' => $segment->id,
                    'name' => $segment->name,
                    'type' => $segment->type,
                    'added_at' => $context->time($segment->pivot->added_at),
                    'source' => $segment->pivot->source,
                ])->values()->all(),
            'recent_events' => $person->occurrences()->with('event:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->limit($recent)->get()
                ->map(fn (EventOccurrence $occurrence) => [
                    'id' => $occurrence->id,
                    'event' => $occurrence->event?->name,
                    'occurred_at' => $context->time($occurrence->occurred_at),
                    'payload' => $context->redactor->data($occurrence->payload ?? []),
                ])->values()->all(),
            'recent_runs' => $person->automationRuns()->with('automation:id,name')->orderByDesc('id')->limit($recent)->get()
                ->map(fn (AutomationRun $run) => [
                    'id' => $run->id,
                    'automation' => $run->automation?->only('id', 'name'),
                    'status' => $run->status,
                    'current_node_id' => $run->current_node_id,
                    'wake_at' => $context->time($run->wake_at),
                    'started_at' => $context->time($run->created_at),
                    'updated_at' => $context->time($run->updated_at),
                ])->values()->all(),
            'recent_messages' => $person->messages()->with('template:id,name')->orderByDesc('id')->limit($recent)
                ->get(['id', 'person_id', 'template_id', 'channel', 'to_address', 'subject', 'status', 'error', 'sent_at', 'created_at'])
                ->map(fn (Message $message) => [
                    'id' => $message->id,
                    'channel' => $message->channel,
                    'to' => $context->redactor->address($message->channel, $message->to_address),
                    'template' => $message->template?->only('id', 'name'),
                    'subject' => $context->redactor->text($message->subject),
                    'status' => $message->status,
                    'error' => $context->redactor->text($message->error),
                    'sent_at' => $context->time($message->sent_at),
                    'created_at' => $context->time($message->created_at),
                ])->values()->all(),
        ];
    }
}
