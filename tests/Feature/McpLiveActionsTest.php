<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkspaces;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Redactor;
use TriggerEngage\Server\Mcp\Tools\Live\SendBroadcast;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\AuditEvent;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

/**
 * Live tools reach real people, so each takes a preview and a second call
 * with the confirmation that preview returned, and the confirmation dies
 * the moment anything it described changes.
 */
class McpLiveActionsTest extends TestCase
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
        config(['trigger-engage-server.mcp.writes' => true, 'trigger-engage-server.mcp.live' => true]);
    }

    protected function identify(string $id, array $profile): Person
    {
        $this->putJson('/api/v1/people/'.$id, $profile, $this->authHeaders($this->workspace, $this->key))->assertOk();

        return Person::query()->where('external_id', $id)->sole();
    }

    protected function track(string $person, string $event): void
    {
        $this->postJson('/api/v1/events', ['name' => $event, 'person_id' => $person], $this->authHeaders($this->workspace, $this->key))->assertStatus(202);
    }

    protected function draftBroadcast(): int
    {
        return $this->tool($this->key, 'create_broadcast_draft', [
            'name' => 'November note',
            'segment_id' => $this->workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $this->template->id,
        ])['broadcast']['id'];
    }

    public function test_live_tools_need_their_own_switch(): void
    {
        config(['trigger-engage-server.mcp.live' => false]);
        $names = array_column($this->mcp($this->key, 'tools/list')->json('result.tools'), 'name');
        $this->assertContains('create_broadcast_draft', $names, 'Drafting stays on.');
        $this->assertNotContains('send_broadcast', $names);
        $this->assertFalse($this->tool($this->key, 'get_workspace')['mcp']['can_act']);

        config(['trigger-engage-server.mcp.live' => true]);
        $tools = collect($this->mcp($this->key, 'tools/list')->json('result.tools'))->keyBy('name');
        $this->assertCount(38, $tools);
        $this->assertSame(['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => false, 'openWorldHint' => false],
            collect($tools['send_broadcast']['annotations'])->except('title')->all());
        $this->assertTrue($this->tool($this->key, 'get_workspace')['mcp']['can_act']);
    }

    public function test_a_broadcast_sends_only_on_the_second_call(): void
    {
        $this->identify('user-1', ['email' => 'ada@example.com', 'attributes' => ['first_name' => 'Ada']]);
        $this->identify('user-2', ['phone' => '+2348000000002']);
        $id = $this->draftBroadcast();

        $preview = $this->tool($this->key, 'send_broadcast', ['broadcast_id' => $id]);
        $this->assertFalse($preview['done']);
        $this->assertSame(1, $preview['preview']['audience']['sendable']);
        $this->assertSame(1, $preview['preview']['audience']['no_destination']);
        $this->assertSame('Welcome, {{ person.first_name }}!', $preview['preview']['subject']);
        $this->assertTrue($preview['preview']['cannot_be_undone']);
        $this->assertStringContainsString('Show this preview to the person', $preview['next']);
        $this->assertSame('draft', Broadcast::find($id)->status, 'A preview sends nothing.');
        Mail::assertNothingSent();

        $sent = $this->tool($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $preview['confirmation']]);
        $this->assertTrue($sent['done']);
        $this->assertSame(2, $sent['recipients_queued']);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.com'));
        Mail::assertSent(TemplatedMail::class, 1);
        $this->assertDatabaseHas('engage_audit_events', ['action' => 'send_broadcast', 'subject_id' => $id, 'actor' => 'api-key:default']);

        // The same confirmation can't send it twice.
        $this->assertStringContainsString('only a draft can be sent', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $preview['confirmation']]));
        Mail::assertSent(TemplatedMail::class, 1);
    }

    public function test_sends_wait_until_the_response_has_gone_out(): void
    {
        $this->identify('user-1', ['email' => 'ada@example.com']);
        $id = $this->draftBroadcast();
        $context = new Context($this->workspace, new Redactor, 'api-key:default', canWrite: true, canAct: true);
        $tool = app(SendBroadcast::class);

        $confirmation = $tool->handle(['broadcast_id' => $id], $context)['confirmation'];
        $tool->handle(['broadcast_id' => $id, 'confirmation' => $confirmation], $context);

        // The answer is ready, recipients are queued, and nothing has been sent yet.
        $this->assertSame(['queued'], Broadcast::find($id)->recipients()->pluck('status')->unique()->values()->all());
        Mail::assertNothingSent();

        $this->app->terminate();
        Mail::assertSent(TemplatedMail::class, 1);
        $this->assertSame('sent', Broadcast::find($id)->recipients()->value('status'));
    }

    public function test_a_confirmation_dies_when_anything_it_described_changes(): void
    {
        $this->identify('user-1', ['email' => 'ada@example.com']);
        $id = $this->draftBroadcast();
        $other = $this->draftBroadcast();
        $confirmation = $this->tool($this->key, 'send_broadcast', ['broadcast_id' => $id])['confirmation'];

        // Used for a different broadcast.
        $this->assertStringContainsString('does not match', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $other, 'confirmation' => $confirmation]));

        // Used by a different caller.
        [, $otherKey] = ApiKey::issue($this->workspace, 'another-agent');
        $this->assertStringContainsString('does not match', $this->toolError($otherKey, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $confirmation]));

        // Made up.
        $this->assertStringContainsString('not one this server issued', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => 'yes']));

        // The audience grew after the preview.
        $this->identify('user-2', ['email' => 'tobi@example.com']);
        $this->assertStringContainsString('does not match', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $confirmation]));

        // The copy changed after the preview.
        $fresh = $this->tool($this->key, 'send_broadcast', ['broadcast_id' => $id])['confirmation'];
        $this->tool($this->key, 'update_broadcast_draft', ['broadcast_id' => $id, 'subject' => 'A different subject']);
        $this->assertStringContainsString('does not match', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $fresh]));

        // Too late.
        $late = $this->tool($this->key, 'send_broadcast', ['broadcast_id' => $id])['confirmation'];
        $this->travel(11)->minutes();
        $this->assertStringContainsString('expired', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $id, 'confirmation' => $late]));

        $this->assertSame('draft', Broadcast::find($id)->status);
        Mail::assertNothingSent();
        $this->assertSame(0, AuditEvent::query()->where('action', 'send_broadcast')->count());
    }

    public function test_a_broadcast_nobody_can_receive_is_refused(): void
    {
        $this->identify('user-1', ['phone' => '+2348000000001']);

        $this->assertStringContainsString('Nobody in segment "All people" can receive this email', $this->toolError($this->key, 'send_broadcast', ['broadcast_id' => $this->draftBroadcast()]));
    }

    public function test_publishing_a_draft_changes_the_journey_for_new_runs_only(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['days' => 1]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => $this->template->id, 'channel_id' => $this->channel->id]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'wait'], ['from' => 'wait', 'to' => 'send']],
        ]);
        $liveVersion = $automation->active_version_id;
        $this->identify('user-1', ['email' => 'ada@example.com']);
        $this->track('user-1', 'signed_up');

        $this->tool($this->key, 'update_automation_draft', ['automation_id' => $automation->id, 'steps' => [
            ['type' => 'send_email', 'template_id' => $this->template->id, 'channel_id' => $this->channel->id],
        ]]);

        $preview = $this->tool($this->key, 'publish_automation', ['automation_id' => $automation->id]);
        $this->assertSame('Replaces the live steps with the draft for everyone who triggers it from now on.', $preview['preview']['what_happens']);
        $this->assertContains('Wait 1 day', $preview['preview']['replaces']);
        $this->assertNotContains('Wait 1 day', $preview['preview']['goes_live']);
        $this->assertSame(1, $preview['preview']['people_mid_journey_staying_on_their_version']);
        $this->assertSame(1, $preview['preview']['trigger_events_last_7_days']);
        $this->assertSame($liveVersion, $automation->fresh()->active_version_id, 'A preview publishes nothing.');

        $published = $this->tool($this->key, 'publish_automation', ['automation_id' => $automation->id, 'confirmation' => $preview['confirmation']]);
        $automation->refresh();
        $this->assertSame($published['version_id'], $automation->active_version_id);
        $this->assertNull($automation->draft_graph);

        // The person already waiting stays on the old version; a new trigger gets the new one.
        $this->assertSame($liveVersion, AutomationRun::query()->oldest('id')->first()->automation_version_id);
        $this->identify('user-2', ['email' => 'tobi@example.com']);
        $this->track('user-2', 'signed_up');
        $newRun = AutomationRun::query()->latest('id')->first();
        $this->assertSame($automation->active_version_id, $newRun->automation_version_id);
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $newRun->status);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('tobi@example.com'));
    }

    public function test_a_draft_that_lost_its_template_is_not_published(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $doomed = $this->workspace->templates()->create(['channel' => 'email', 'name' => 'Temporary', 'subject' => 'Hi', 'body' => '<p>Hi</p>']);
        $this->tool($this->key, 'update_automation_draft', ['automation_id' => $automation->id, 'steps' => [['type' => 'send_email', 'template_id' => $doomed->id]]]);
        $doomed->delete();

        $this->assertStringContainsString("can't be published as it stands", $this->toolError($this->key, 'publish_automation', ['automation_id' => $automation->id]));
        $this->assertStringContainsString('no draft to publish and is already live', $this->toolError($this->key, 'publish_automation', [
            'automation_id' => $this->makeAutomation($this->workspace, 'booked', $this->linearEmailGraph($this->template, $this->channel))->id,
        ]));
    }

    public function test_pausing_stops_new_runs_and_publishing_resumes(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $this->identify('user-1', ['email' => 'ada@example.com']);

        $pause = $this->tool($this->key, 'pause_automation', ['automation_id' => $automation->id]);
        $this->assertSame('active', $automation->fresh()->status);
        $this->tool($this->key, 'pause_automation', ['automation_id' => $automation->id, 'confirmation' => $pause['confirmation']]);
        $this->assertSame('paused', $automation->fresh()->status);
        $this->assertStringContainsString('nothing to pause', $this->toolError($this->key, 'pause_automation', ['automation_id' => $automation->id]));

        $this->track('user-1', 'signed_up');
        $this->assertSame(0, AutomationRun::query()->count(), 'A paused journey starts no runs.');

        $resume = $this->tool($this->key, 'publish_automation', ['automation_id' => $automation->id]);
        $this->assertSame('Resumes the paused journey with its current live steps.', $resume['preview']['what_happens']);
        $this->tool($this->key, 'publish_automation', ['automation_id' => $automation->id, 'confirmation' => $resume['confirmation']]);
        $this->assertSame('active', $automation->fresh()->status);

        $this->track('user-1', 'signed_up');
        $this->assertSame(1, AutomationRun::query()->count());
    }

    public function test_retrying_failures_resends_only_to_the_failed(): void
    {
        $ada = $this->identify('user-1', ['email' => 'ada@example.com']);
        $tobi = $this->identify('user-2', ['email' => 'tobi@example.com']);
        $broadcast = $this->workspace->broadcasts()->create([
            'name' => 'October note', 'channel' => 'email', 'status' => 'completed', 'completed_at' => now(),
            'segment_id' => $this->workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $this->template->id, 'channel_id' => $this->channel->id,
            'subject' => 'Hi', 'body' => '<p>Hi</p>',
        ]);
        $broadcast->recipients()->create(['person_id' => $ada->id, 'status' => 'failed', 'error' => 'SMTP timeout']);
        $broadcast->recipients()->create(['person_id' => $tobi->id, 'status' => 'sent', 'sent_at' => now()]);

        $preview = $this->tool($this->key, 'retry_broadcast_failures', ['broadcast_id' => $broadcast->id]);
        $this->assertSame(1, $preview['preview']['people_to_retry']);
        $this->assertSame([['reason' => 'SMTP timeout', 'people' => 1]], $preview['preview']['why_they_failed']);
        Mail::assertNothingSent();

        $retried = $this->tool($this->key, 'retry_broadcast_failures', ['broadcast_id' => $broadcast->id, 'confirmation' => $preview['confirmation']]);
        $this->assertSame(1, $retried['recipients_queued']);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.com'));
        Mail::assertNotSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('tobi@example.com'));
    }
}
