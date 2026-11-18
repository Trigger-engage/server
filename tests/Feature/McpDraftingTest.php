<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\AuditEvent;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

/**
 * The drafting tools: who gets them, that what they make reaches nobody, and
 * that they refuse changes a live journey would feel.
 */
class McpDraftingTest extends TestCase
{
    use BuildsWorkspaces, CallsMcp, RefreshDatabase;

    protected Workspace $workspace;

    protected string $key;

    protected Template $template;

    protected Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        [$this->workspace, $this->key] = $this->makeWorkspace();
        $this->template = $this->makeEmailTemplate($this->workspace);
        $this->channel = $this->makeLogEmailChannel($this->workspace);
        config(['trigger-engage-server.mcp.writes' => true, 'trigger-engage-server.mcp.test_recipient' => 'me@team.test']);
    }

    protected function headers(): array
    {
        return $this->authHeaders($this->workspace, $this->key);
    }

    protected function identify(string $id, array $profile): Person
    {
        $this->putJson('/api/v1/people/'.$id, $profile, $this->headers())->assertOk();

        return Person::query()->where('external_id', $id)->sole();
    }

    public function test_drafting_tools_are_offered_only_when_writes_are_switched_on(): void
    {
        config(['trigger-engage-server.mcp.writes' => false]);
        $names = array_column($this->mcp($this->key, 'tools/list')->json('result.tools'), 'name');
        $this->assertNotContains('create_template', $names);
        $this->mcp($this->key, 'tools/call', ['name' => 'create_template', 'arguments' => ['channel' => 'email', 'name' => 'x', 'body' => 'x']])
            ->assertJsonPath('error.code', -32602);
        $this->assertFalse($this->tool($this->key, 'get_workspace')['mcp']['can_write']);

        config(['trigger-engage-server.mcp.writes' => true]);
        $tools = collect($this->mcp($this->key, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertCount(34, $tools);
        $this->assertFalse($tools['create_template']['annotations']['readOnlyHint']);
        $this->assertFalse($tools['create_template']['annotations']['destructiveHint']);
        $this->assertTrue($tools['update_template']['annotations']['destructiveHint']);
        $this->assertTrue($tools['get_person']['annotations']['readOnlyHint']);
        $this->assertTrue($this->tool($this->key, 'get_workspace')['mcp']['can_write']);
    }

    public function test_templates_are_checked_like_the_editor_and_recorded(): void
    {
        $error = $this->toolError($this->key, 'create_template', ['channel' => 'email', 'name' => 'Broken', 'body' => '{% if person.first_name %}Hi']);
        $this->assertStringContainsString('could not be rendered', $error);

        $created = $this->tool($this->key, 'create_template', [
            'channel' => 'email', 'name' => 'Check-in', 'subject' => 'How are you, {{ person.first_name }}?',
            'body' => '<p>Hi {{ person.first_name }}, your plan is {{ person.plan_name }}.</p>', 'subscription_topic' => '',
        ]);

        $this->assertSame('Check-in', $created['template']['name']);
        $this->assertSame(['person.plan_name'], $created['empty_in_preview']);
        $this->assertDatabaseHas('engage_audit_events', [
            'workspace_id' => $this->workspace->id, 'actor' => 'api-key:default', 'action' => 'template.created', 'subject_id' => $created['template']['id'],
        ]);

        $this->assertStringContainsString('No subscription topic with key "nope"', $this->toolError($this->key, 'create_template', [
            'channel' => 'sms', 'name' => 'Reminder', 'body' => 'See you soon', 'subscription_topic' => 'nope',
        ]));
    }

    public function test_a_template_a_live_journey_sends_is_refused_and_can_be_copied(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));

        $error = $this->toolError($this->key, 'update_template', ['template_id' => $this->template->id, 'subject' => 'New subject']);
        $this->assertStringContainsString('is sent by live journeys: "'.$automation->name.'"', $error);
        $this->assertSame('Welcome, {{ person.first_name }}!', $this->template->fresh()->subject);

        $copy = $this->tool($this->key, 'duplicate_template', ['template_id' => $this->template->id])['template'];
        $this->assertSame('Welcome (copy)', $copy['name']);

        $updated = $this->tool($this->key, 'update_template', ['template_id' => $copy['id'], 'subject' => 'Welcome aboard, {{ person.first_name }}']);
        $this->assertSame(['subject'], $updated['changed']);
        $this->assertSame('Welcome aboard, {{ person.first_name }}', Template::find($copy['id'])->subject);
        $this->assertSame('<p>Hi {{ person.first_name }}, you are on {{ event.plan }}.</p>', Template::find($copy['id'])->body, 'Fields not named keep their value.');
    }

    public function test_a_paused_journeys_in_flight_runs_still_count_as_live(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['days' => 1]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => $this->template->id]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'wait'], ['from' => 'wait', 'to' => 'send']],
        ]);
        $this->identify('user-1', ['email' => 'ada@example.com']);
        $this->postJson('/api/v1/events', ['name' => 'signed_up', 'person_id' => 'user-1'], $this->headers())->assertStatus(202);
        $automation->update(['status' => 'paused']);

        $this->assertSame(AutomationRun::STATUS_WAITING, AutomationRun::query()->sole()->status);
        $this->assertStringContainsString('live journeys', $this->toolError($this->key, 'update_template', ['template_id' => $this->template->id, 'body' => 'Changed']));
    }

    public function test_a_new_segment_fills_without_starting_journeys(): void
    {
        // A journey that starts on anyone entering any segment.
        $this->makeAutomation($this->workspace, SegmentManager::EVENT_ENTERED, $this->linearEmailGraph($this->template, $this->channel));
        $this->identify('user-1', ['email' => 'ada@example.com', 'attributes' => ['plan' => 'premium']]);
        $this->identify('user-2', ['email' => 'tobi@example.com', 'attributes' => ['plan' => 'free']]);
        $occurrencesBefore = EventOccurrence::query()->count();

        $created = $this->tool($this->key, 'create_segment', [
            'name' => 'Premium', 'rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium']]],
        ]);

        $this->assertSame(1, $created['people']);
        $this->assertSame('person.plan equals "premium"', $created['segment']['rules_in_words']);
        $this->assertSame($occurrencesBefore, EventOccurrence::query()->count(), 'No segment_entered was recorded.');
        $this->assertSame(0, AutomationRun::query()->count(), 'No journey started.');

        // The engine's next recompute finds membership already right.
        app(SegmentManager::class)->recompute(Segment::find($created['segment']['id']));
        $this->assertSame(0, AutomationRun::query()->count());

        $this->assertStringContainsString('already exists', $this->toolError($this->key, 'create_segment', ['name' => 'Premium', 'type' => 'manual']));
        $this->assertStringContainsString('needs rules', $this->toolError($this->key, 'create_segment', ['name' => 'Empty']));
    }

    public function test_segment_rules_are_refused_where_journeys_or_other_segments_depend_on_them(): void
    {
        $rules = fn (string $plan) => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => $plan]]];
        $used = Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'Used', 'type' => Segment::TYPE_RULE, 'rules' => $rules('premium')]);
        $nested = Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'Nested', 'type' => Segment::TYPE_RULE, 'rules' => $rules('basic')]);
        $free = Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'Free', 'type' => Segment::TYPE_RULE, 'rules' => $rules('free')]);
        Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'Parent', 'type' => Segment::TYPE_RULE, 'rules' => [
            'match' => 'any', 'conditions' => [['kind' => 'group', 'match' => 'all', 'conditions' => [['kind' => 'segment', 'segment_id' => $nested->id, 'in' => true]]]],
        ]]);
        $this->makeAutomation($this->workspace, 'signed_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'only', 'type' => 'segment', 'config' => ['segment_id' => $used->id, 'in' => true]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => $this->template->id]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'only'], ['from' => 'only', 'to' => 'send', 'branch' => 'true']],
        ]);

        $this->assertStringContainsString('Live journeys use segment "Used"', $this->toolError($this->key, 'update_segment', ['segment_id' => $used->id, 'rules' => $rules('vip')]));
        $this->assertStringContainsString('part of the rules of "Parent"', $this->toolError($this->key, 'update_segment', ['segment_id' => $nested->public_id, 'rules' => $rules('vip')]));
        $this->assertStringContainsString("can't be changed", $this->toolError($this->key, 'update_segment', ['segment_id' => Segment::query()->where('type', Segment::TYPE_ALL)->value('id'), 'name' => 'Everyone']));

        // A rename is harmless, even for a segment a journey uses.
        $this->assertSame(['name'], $this->tool($this->key, 'update_segment', ['segment_id' => $used->id, 'name' => 'Premium users'])['changed']);

        $this->identify('user-1', ['email' => 'ada@example.com', 'attributes' => ['plan' => 'vip']]);
        $updated = $this->tool($this->key, 'update_segment', ['segment_id' => $free->id, 'rules' => $rules('vip')]);
        $this->assertSame(1, $updated['people']);
        $this->assertSame(0, EventOccurrence::query()->whereHas('event', fn ($query) => $query->where('name', SegmentManager::EVENT_ENTERED))->count());
    }

    public function test_broadcast_drafts_copy_the_template_and_send_nothing(): void
    {
        $this->identify('user-1', ['email' => 'ada@example.com']);
        $this->identify('user-2', ['phone' => '+2348000000002']);
        $everyone = $this->workspace->segments()->where('type', Segment::TYPE_ALL)->value('public_id');

        $draft = $this->tool($this->key, 'create_broadcast_draft', ['name' => 'December news', 'segment_id' => $everyone, 'template_id' => $this->template->id]);
        $this->assertSame('draft', $draft['broadcast']['status']);
        $this->assertSame($this->channel->id, $draft['broadcast']['delivery_channel']['id'], 'The default email channel.');
        $this->assertSame(1, $draft['audience_estimate']['sendable']);
        $this->assertSame(1, $draft['audience_estimate']['no_destination']);

        $edited = $this->tool($this->key, 'update_broadcast_draft', ['broadcast_id' => $draft['broadcast']['id'], 'subject' => 'Our December note']);
        $this->assertSame('Our December note', $edited['broadcast']['subject']);
        $this->assertSame('Welcome, {{ person.first_name }}!', $this->template->fresh()->subject, 'The template is left alone.');

        Mail::assertNothingSent();
        $this->assertSame(0, Message::query()->count());

        $sent = $this->workspace->broadcasts()->create([
            'name' => 'Old', 'channel' => 'email', 'status' => 'completed', 'segment_id' => Segment::query()->where('public_id', $everyone)->value('id'),
            'template_id' => $this->template->id, 'channel_id' => $this->channel->id,
        ]);
        $this->assertStringContainsString('not a draft', $this->toolError($this->key, 'update_broadcast_draft', ['broadcast_id' => $sent->id, 'subject' => 'x']));
    }

    public function test_journey_drafts_start_nothing_until_a_person_publishes_them(): void
    {
        $this->workspace->events()->create(['name' => 'booked', 'first_seen_at' => now()]);
        $steps = [
            ['type' => 'delay', 'days' => 1],
            ['type' => 'send_email', 'template_id' => $this->template->id],
        ];

        $draft = $this->tool($this->key, 'create_automation_draft', ['name' => 'After booking', 'trigger_event' => 'booked', 'steps' => $steps]);
        $this->assertSame('draft', $draft['automation']['status']);
        // The editor's builder pins the default channel when a step names none.
        $this->assertSame(['Wait 1 day', 'Send email "Welcome" via Test channel (array)', 'End of journey'], array_column(array_slice($draft['draft_steps'], 1), 'summary'));

        $automation = Automation::find($draft['automation']['id']);
        $this->assertNull($automation->active_version_id);
        $this->assertSame('once_ever_per_person', $automation->reentry_policy);

        $this->identify('user-1', ['email' => 'ada@example.com']);
        $this->postJson('/api/v1/events', ['name' => 'booked', 'person_id' => 'user-1'], $this->headers())->assertStatus(202);
        $this->assertSame(0, AutomationRun::query()->count(), 'A draft starts no runs.');

        // The editor opens the draft, says so, and publishing it makes it live.
        $this->get('/app/automations/'.$automation->id, $this->headers())->assertInertia(fn (Assert $page) => $page
            ->component('Automations/Edit')
            ->where('automation.draft.saved_by', 'api-key:default')
            ->where('automation.steps.0.type', 'delay')
            ->where('automation.steps.1.template_id', $this->template->id));

        $this->put('/app/automations/'.$automation->id.'/publish', ['steps' => $steps, 'goal' => ['enabled' => false]], $this->headers())->assertRedirect();
        $automation->refresh();
        $this->assertSame('active', $automation->status);
        $this->assertNull($automation->draft_graph, 'Publishing clears the draft.');

        $this->assertStringContainsString('No event named "nope"', $this->toolError($this->key, 'create_automation_draft', ['name' => 'x', 'trigger_event' => 'nope', 'steps' => $steps]));
        $sms = $this->workspace->templates()->create(['channel' => 'sms', 'name' => 'Text', 'body' => 'Hi']);
        $this->assertStringContainsString('Invalid arguments', $this->toolError($this->key, 'create_automation_draft', [
            'name' => 'Wrong channel', 'trigger_event' => 'booked', 'steps' => [['type' => 'send_email', 'template_id' => $sms->id]],
        ]));
    }

    public function test_a_draft_beside_a_live_journey_leaves_the_live_version_running(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $liveVersion = $automation->active_version_id;

        $draft = $this->tool($this->key, 'update_automation_draft', [
            'automation_id' => $automation->id,
            'steps' => [['type' => 'delay', 'hours' => 2], ['type' => 'send_email', 'template_id' => $this->template->id, 'channel_id' => $this->channel->id]],
        ]);
        $this->assertTrue($draft['automation']['live_version_untouched']);
        $this->assertStringContainsString('rename it in the dashboard', $this->toolError($this->key, 'update_automation_draft', [
            'automation_id' => $automation->id, 'name' => 'Renamed', 'steps' => [],
        ]));

        $this->identify('user-1', ['email' => 'ada@example.com']);
        $this->postJson('/api/v1/events', ['name' => 'signed_up', 'person_id' => 'user-1'], $this->headers())->assertStatus(202);
        $run = AutomationRun::query()->sole();
        $this->assertSame($liveVersion, $run->automation_version_id);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status, 'The live steps (send straight away) ran, not the draft\'s delay.');

        $this->assertNull($this->tool($this->key, 'discard_automation_draft', ['automation_id' => $automation->id])['draft']);
        $this->assertNull($automation->fresh()->draft_graph);
        $this->assertSame($liveVersion, $automation->fresh()->active_version_id);
        $this->assertStringContainsString('no draft to discard', $this->toolError($this->key, 'discard_automation_draft', ['automation_id' => $automation->id]));
    }

    public function test_the_editor_can_discard_a_draft(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $this->tool($this->key, 'update_automation_draft', ['automation_id' => $automation->id, 'steps' => [['type' => 'delay', 'hours' => 1]]]);

        $this->get('/app/automations/'.$automation->id, $this->headers())
            ->assertInertia(fn (Assert $page) => $page->where('automation.steps.0.type', 'delay')->whereNot('automation.draft', null));

        $this->delete('/app/automations/'.$automation->id.'/draft', [], $this->headers())->assertRedirect();

        $this->get('/app/automations/'.$automation->id, $this->headers())
            ->assertInertia(fn (Assert $page) => $page->where('automation.draft', null)->where('automation.steps.0.type', 'send_email'));
    }

    public function test_test_sends_go_only_to_the_callers_own_address(): void
    {
        $this->identify('user-1', ['email' => 'ada@example.com', 'attributes' => ['first_name' => 'Ada']]);

        $sent = $this->tool($this->key, 'send_test', ['template_id' => $this->template->id, 'external_id' => 'user-1', 'event' => ['plan' => 'wellness']]);
        $this->assertSame('me@team.test', $sent['sent_to']);
        $this->assertSame('[Test] Welcome, Ada!', $sent['subject']);
        $this->assertSame([], $sent['missing_variables']);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('me@team.test') && $mail->renderedSubject === '[Test] Welcome, Ada!');
        Mail::assertSent(TemplatedMail::class, 1);
        $this->assertSame(0, Message::query()->count(), 'A test leaves no message in the log.');

        $sms = $this->workspace->templates()->create(['channel' => 'sms', 'name' => 'Text', 'body' => 'Hi']);
        $this->assertStringContainsString('email only', $this->toolError($this->key, 'send_test', ['template_id' => $sms->id]));

        config(['trigger-engage-server.mcp.test_recipient' => null]);
        $this->assertStringContainsString('No test address', $this->toolError($this->key, 'send_test', ['template_id' => $this->template->id]));
    }

    public function test_changes_show_up_in_the_workspace_overview(): void
    {
        $this->tool($this->key, 'create_template', ['channel' => 'sms', 'name' => 'Reminder', 'body' => 'See you soon']);

        $changes = $this->tool($this->key, 'get_workspace')['recent_agent_changes'];
        $this->assertSame('api-key:default', $changes[0]['by']);
        $this->assertSame('Created sms template "Reminder".', $changes[0]['what']);
        $this->assertSame(1, AuditEvent::query()->count());
    }
}
