<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Services\Ingest;

class SegmentAutomationTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_manual_membership_changes_record_entered_and_left_events(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        $segment = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);

        $manager = app(SegmentManager::class);
        $manager->addMember($segment, $person);
        // Re-adding an existing member records nothing (loop guard).
        $manager->addMember($segment, $person);
        $manager->removeMember($segment, $person);

        $entered = Event::where('workspace_id', $workspace->id)->where('name', 'segment_entered')->first();
        $left = Event::where('workspace_id', $workspace->id)->where('name', 'segment_left')->first();

        $this->assertSame(1, EventOccurrence::where('event_id', $entered->id)->count());
        $this->assertSame(1, EventOccurrence::where('event_id', $left->id)->count());
        $this->assertSame(
            $segment->public_id,
            EventOccurrence::where('event_id', $entered->id)->first()->payload['segment_public_id']
        );
    }

    public function test_rule_recompute_records_entering_people(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'prime', 'attributes' => ['plan' => 'premium']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'free', 'attributes' => ['plan' => 'free']]);

        $segment = $workspace->segments()->create([
            'name' => 'Premium', 'type' => Segment::TYPE_RULE,
            'rules' => ['match' => 'all', 'conditions' => [
                ['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ]],
        ]);

        app(SegmentManager::class)->recompute($segment);

        $entered = Event::where('workspace_id', $workspace->id)->where('name', 'segment_entered')->first();
        $occurrences = EventOccurrence::where('event_id', $entered->id)->get();
        $this->assertCount(1, $occurrences);

        // A second recompute with no membership change records nothing new.
        app(SegmentManager::class)->recompute($segment);
        $this->assertSame(1, EventOccurrence::where('event_id', $entered->id)->count());
    }

    public function test_segment_entered_can_trigger_an_automation_filtered_to_one_segment(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace, 'Welcome to the audience', '<p>Hi</p>');
        $channel = $this->makeLogEmailChannel($workspace);

        $vip = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);
        $other = $workspace->segments()->create(['name' => 'Other', 'type' => Segment::TYPE_MANUAL]);

        $this->makeAutomation($workspace, SegmentManager::EVENT_ENTERED, [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => ['filters' => [
                    ['field' => 'segment_public_id', 'operator' => 'equals', 'value' => $vip->public_id],
                ]]],
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
        ]);

        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1', 'email' => 'one@example.com']);

        $manager = app(SegmentManager::class);
        // Entering the non-matching segment must not start the journey.
        $manager->addMember($other, $person);
        $this->assertSame(0, Message::where('workspace_id', $workspace->id)->count());

        $manager->addMember($vip, $person);
        $this->assertSame(1, Message::where('workspace_id', $workspace->id)->count());
    }

    public function test_segment_node_branches_on_materialized_membership(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace, 'Members only', '<p>Hi</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $vip = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);

        $this->makeAutomation($workspace, 'app_open', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'gate', 'type' => 'segment', 'config' => ['segment_id' => $vip->id, 'in' => true]],
                ['id' => 'send', 'type' => 'send_email', 'config' => [
                    'template_id' => $template->id,
                    'channel_id' => $channel->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'gate'],
                ['from' => 'gate', 'to' => 'send', 'branch' => 'true'],
                ['from' => 'gate', 'to' => 'done', 'branch' => 'false'],
                ['from' => 'send', 'to' => 'done'],
            ],
        ]);

        $member = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'member', 'email' => 'm@example.com']);
        $outsider = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'outsider', 'email' => 'o@example.com']);
        $vip->people()->attach($member->id, ['source' => 'api', 'added_at' => now()]);

        $ingest = app(Ingest::class);
        $ingest->track($workspace, ['name' => 'app_open', 'person_id' => 'member']);
        $ingest->track($workspace, ['name' => 'app_open', 'person_id' => 'outsider']);

        $messages = Message::where('workspace_id', $workspace->id)->get();
        $this->assertCount(1, $messages);
        $this->assertSame($member->id, $messages->first()->person_id);
    }

    public function test_builder_publishes_segment_filter_and_trigger_filters(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $vip = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'signed_up', 'first_seen_at' => now()]);

        $automation = $workspace->automations()->create([
            'name' => 'Filtered welcome',
            'trigger_event_id' => $event->id,
            'reentry_policy' => 'every_time',
        ]);

        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [
                ['type' => 'segment', 'segment_id' => $vip->id, 'in' => true],
                ['type' => 'send_email', 'template_id' => $template->id, 'channel_id' => $channel->id],
            ],
            'trigger_filters' => [
                ['field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ],
        ], $headers)->assertRedirect()->assertSessionHasNoErrors();

        $graph = $automation->fresh()->activeVersion->graph;
        $trigger = collect($graph['nodes'])->firstWhere('type', 'trigger');
        $segmentNode = collect($graph['nodes'])->firstWhere('type', 'segment');

        $this->assertSame('plan', $trigger['config']['filters'][0]['field']);
        $this->assertSame($vip->id, $segmentNode['config']['segment_id']);
        $this->assertTrue($segmentNode['config']['in']);

        $edges = collect($graph['edges']);
        $this->assertNotNull($edges->first(fn ($e) => $e['from'] === $segmentNode['id'] && ($e['branch'] ?? null) === 'true'));
        $this->assertSame('exit', $edges->first(fn ($e) => $e['from'] === $segmentNode['id'] && ($e['branch'] ?? null) === 'false')['to']);
    }

    public function test_trigger_filters_are_editable_after_publish(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'signed_up', 'first_seen_at' => now()]);

        $automation = $workspace->automations()->create([
            'name' => 'Filtered welcome',
            'trigger_event_id' => $event->id,
            'reentry_policy' => 'every_time',
        ]);

        $this->put("/app/automations/{$automation->id}/publish", [
            'steps' => [
                ['type' => 'send_email', 'template_id' => $template->id, 'channel_id' => $channel->id],
            ],
            'trigger_filters' => [
                ['field' => 'plan', 'operator' => 'equals', 'value' => 'premium'],
            ],
        ], $headers)->assertRedirect();

        $this->get("/app/automations/{$automation->id}", $headers)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('triggerFilters.0.field', 'plan')
                ->where('triggerFilters.0.value', 'premium'));
    }
}
