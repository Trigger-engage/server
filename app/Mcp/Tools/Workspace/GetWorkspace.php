<?php

namespace TriggerEngage\Server\Mcp\Tools\Workspace;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Server;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;

class GetWorkspace extends Tool
{
    public function name(): string
    {
        return 'get_workspace';
    }

    public function title(): string
    {
        return 'Workspace overview';
    }

    public function description(): string
    {
        return 'Start here. The workspace this key belongs to: its timezone, how many people, events, segments, templates, '
            .'automations and broadcasts it has (by status), which delivery channels are set up and which is the default per '
            .'type, and engine health (active runs, delays that should have woken already, stalled runs, when the last event '
            .'and message happened). Overdue delays or stalled runs usually mean the scheduler (engage:tick) or the queue '
            .'worker is not running.';
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspace = $context->workspace;
        $stalledAfter = (int) config('trigger-engage-server.stalled_runs.after_minutes', 15);
        $runs = fn () => AutomationRun::query()->where('workspace_id', $workspace->id);

        return [
            'workspace' => [
                'name' => $workspace->name,
                'public_id' => $workspace->public_id,
                'timezone' => $workspace->timezone ?: 'UTC',
                'url' => $context->url('dashboard'),
            ],
            'counts' => [
                'people' => $workspace->people()->count(),
                'events' => $workspace->events()->count(),
                'segments' => $workspace->segments()->count(),
                'templates' => $workspace->templates()->count(),
                'subscription_topics' => $workspace->subscriptionTopics()->count(),
                'automations' => $workspace->automations()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total),
                'broadcasts' => $workspace->broadcasts()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($total) => (int) $total),
            ],
            'channels' => $workspace->channels()->orderBy('type')->orderByDesc('is_default')->orderBy('name')->get()
                ->map(fn (Channel $channel) => $channel->only('id', 'type', 'name', 'driver', 'is_default'))->values()->all(),
            'engine' => [
                'active_runs' => $runs()->whereIn('status', AutomationRun::activeStatuses())->count(),
                'overdue_delays' => $runs()->where('status', AutomationRun::STATUS_WAITING)->where('wake_at', '<', now()->subMinutes(5))->count(),
                'stalled_runs' => $runs()->where('status', AutomationRun::STATUS_RUNNING)->where('updated_at', '<', now()->subMinutes($stalledAfter))->count(),
                'failed_runs_last_7_days' => $runs()->where('status', AutomationRun::STATUS_FAILED)->where('updated_at', '>=', now()->subDays(7))->count(),
                'last_event_at' => $context->time(EventOccurrence::query()->where('workspace_id', $workspace->id)->max('occurred_at')),
                'last_message_at' => $context->time(Message::query()->where('workspace_id', $workspace->id)->max('created_at')),
            ],
            'mcp' => [
                'server_version' => Server::version(),
                'read_only' => true,
                'contact_details' => $context->redactor->revealsContacts() ? 'shown' : 'masked',
            ],
        ];
    }
}
