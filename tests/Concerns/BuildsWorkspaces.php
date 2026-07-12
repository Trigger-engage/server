<?php

namespace Tests\Concerns;

use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

trait BuildsWorkspaces
{
    /** @return array{0: Workspace, 1: string} workspace + plaintext api key */
    protected function makeWorkspace(string $timezone = 'UTC'): array
    {
        $workspace = Workspace::create(['name' => 'Test Workspace', 'timezone' => $timezone]);

        [, $plaintext] = ApiKey::issue($workspace);

        return [$workspace, $plaintext];
    }

    /** @return array<string, string> Basic auth header for the combined workspace_id + api_key pair */
    protected function authHeaders(Workspace $workspace, string $plaintextKey): array
    {
        return [
            'Authorization' => 'Basic '.base64_encode($workspace->public_id.':'.$plaintextKey),
        ];
    }

    protected function makeEmailTemplate(Workspace $workspace, ?string $subject = null, ?string $body = null): Template
    {
        return $workspace->templates()->create([
            'channel' => 'email',
            'name' => 'Welcome',
            'subject' => $subject ?? 'Welcome, {{ person.first_name }}!',
            'body' => $body ?? '<p>Hi {{ person.first_name }}, you are on {{ event.plan }}.</p>',
        ]);
    }

    protected function makeLogEmailChannel(Workspace $workspace): Channel
    {
        return $workspace->channels()->create([
            'type' => 'email',
            'driver' => 'array',
            'name' => 'Test channel',
            'is_default' => true,
        ]);
    }

    protected function makeAutomation(
        Workspace $workspace,
        string $eventName,
        array $graph,
        string $reentryPolicy = Automation::REENTRY_EVERY_TIME,
    ): Automation {
        $event = Event::firstOrCreate(
            ['workspace_id' => $workspace->id, 'name' => $eventName],
            ['first_seen_at' => now()]
        );

        $automation = $workspace->automations()->create([
            'name' => 'test_automation_'.$eventName,
            'trigger_event_id' => $event->id,
            'reentry_policy' => $reentryPolicy,
        ]);

        $automation->publish($graph);

        return $automation;
    }

    /** Trigger → send_email → exit, no delay. */
    protected function linearEmailGraph(Template $template, Channel $channel, array $extraNodes = []): array
    {
        return [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'send', 'type' => 'send_email', 'config' => [
                    'template_id' => $template->id,
                    'channel_id' => $channel->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'send'],
                ['from' => 'send', 'to' => 'done'],
            ],
        ];
    }
}
