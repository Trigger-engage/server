<?php

namespace Database\Seeders;

use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Workspace;
use Illuminate\Database\Seeder;

class DemoSeeder extends Seeder
{
    /**
     * Seeds a demo workspace with a working onboarding automation:
     * customer_sign_up → wait 1 minute → welcome email (log driver).
     */
    public function run(): void
    {
        $workspace = Workspace::create([
            'name' => 'Demo Workspace',
            'timezone' => 'Africa/Lagos',
        ]);

        [, $plaintext] = ApiKey::issue($workspace, 'demo');

        $event = Event::create([
            'workspace_id' => $workspace->id,
            'name' => 'customer_sign_up',
            'first_seen_at' => now(),
        ]);

        $template = $workspace->templates()->create([
            'channel' => 'email',
            'name' => 'Welcome email',
            'subject' => 'Welcome, {{ person.first_name }}!',
            'body' => '<p>Hi {{ person.first_name }},</p><p>Thanks for signing up on the {{ event.plan }} plan. We are glad you are here.</p>',
            'from_name' => 'Demo Workspace',
            'from_address' => 'hello@example.com',
        ]);

        $channel = $workspace->channels()->create([
            'type' => 'email',
            'driver' => 'log',
            'name' => 'Log (dev)',
            'is_default' => true,
        ]);

        $automation = $workspace->automations()->create([
            'name' => 'onboarding_automation',
            'trigger_event_id' => $event->id,
            'reentry_policy' => 'once_ever_per_person',
        ]);

        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 1]],
                ['id' => 'welcome', 'type' => 'send_email', 'config' => [
                    'template_id' => $template->id,
                    'channel_id' => $channel->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'welcome'],
                ['from' => 'welcome', 'to' => 'done'],
            ],
        ]);

        $this->command?->info('Demo workspace seeded. SDK credentials:');
        $this->command?->table(['Setting', 'Value'], [
            ['TRIGGER_ENGAGE_WORKSPACE_ID', $workspace->public_id],
            ['TRIGGER_ENGAGE_API_KEY', $plaintext],
        ]);
    }
}
