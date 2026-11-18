<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkspaces;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

class McpDiagnoseDeliveryTest extends TestCase
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
    }

    protected function identify(string $id, array $profile = ['email' => 'ada@example.com']): Person
    {
        $this->putJson('/api/v1/people/'.$id, $profile, $this->authHeaders($this->workspace, $this->key))->assertOk();

        return Person::query()->where('external_id', $id)->sole();
    }

    protected function track(string $person, string $event, array $data = []): void
    {
        $this->postJson('/api/v1/events', ['name' => $event, 'person_id' => $person, 'data' => $data], $this->authHeaders($this->workspace, $this->key))->assertStatus(202);
    }

    /** @return array<string, array{result: string, detail: string}> */
    protected function checks(array $diagnosis): array
    {
        return collect($diagnosis['checks'])->mapWithKeys(fn (array $check) => [$check['check'] => $check])->all();
    }

    public function test_a_delivered_message_says_so(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $this->identify('user-1');
        $this->track('user-1', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);
        $checks = $this->checks($diagnosis);

        $this->assertMatchesRegularExpression('/^Sent \\(sent, [0-9T:+\\-]+\\): Send email "Welcome" via Test channel \\(array\\)\\.$/', $diagnosis['verdict']);
        $this->assertSame('pass', $checks['Automation is active']['result']);
        $this->assertSame('pass', $checks['Trigger event received']['result']);
        $this->assertSame('pass', $checks['Event started a run']['result']);
        $this->assertSame('pass', $checks['Provider result']['result']);
        $this->assertSame('pass', $checks['Email reachable today']['result']);
        $this->assertSame('a***@e***.com', $diagnosis['person']['email']);
        $this->assertCount(1, $diagnosis['messages']);
        $this->assertNotNull($diagnosis['trigger_events'][0]['run_id']);
    }

    public function test_no_trigger_event_for_the_person(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $this->identify('user-1');
        $this->identify('user-2', ['email' => 'tobi@example.com']);
        $this->track('user-2', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertStringStartsWith('No "signed_up" event for this person in the last 30 days.', $diagnosis['verdict']);
        $this->assertStringContainsString('so the host app does send it', $diagnosis['verdict']);
        $this->assertNull($diagnosis['latest_run']);
    }

    public function test_a_trigger_filter_that_did_not_match(): void
    {
        $graph = $this->linearEmailGraph($this->template, $this->channel);
        $graph['nodes'][0]['config']['filters'] = [['field' => 'plan', 'operator' => 'equals', 'value' => 'premium']];
        $automation = $this->makeAutomation($this->workspace, 'upgraded', $graph);
        $this->identify('user-1');
        $this->track('user-1', 'upgraded', ['plan' => 'basic']);

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertSame('fail', $this->checks($diagnosis)['Event started a run']['result']);
        $this->assertStringContainsString('did not match: the event had event.plan = "basic"', $diagnosis['verdict']);
        $this->assertNull($diagnosis['trigger_events'][0]['run_id']);
    }

    public function test_a_re_entry_policy_that_kept_them_out(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel), Automation::REENTRY_ONCE_EVER);
        $this->identify('user-1');
        $this->track('user-1', 'signed_up');
        $this->track('user-1', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertStringContainsString('1 of 2 events started a run', $this->checks($diagnosis)['Event started a run']['detail']);
        $this->assertStringStartsWith('Re-entry is once_ever_per_person and they already went through it', $diagnosis['trigger_events'][0]['why_no_run']);
        $this->assertStringStartsWith('Sent (', $diagnosis['verdict']);
    }

    public function test_a_suppressed_person_is_skipped_and_told_why(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $person = $this->identify('user-1');
        $person->suppressions()->create(['workspace_id' => $this->workspace->id, 'channel' => 'email', 'reason' => 'bounce']);
        $this->track('user-1', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);
        $checks = $this->checks($diagnosis);

        $this->assertSame('Skipped "Send email "Welcome" via Test channel (array)": person suppressed for email.', $diagnosis['verdict']);
        $this->assertSame('fail', $checks['Email reachable today']['result']);
        $this->assertStringContainsString('suppressed for email (bounce)', $checks['Email reachable today']['detail']);
    }

    public function test_a_paused_automation(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', $this->linearEmailGraph($this->template, $this->channel));
        $automation->update(['status' => 'paused']);
        $this->identify('user-1');
        $this->track('user-1', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertStringStartsWith('It is paused, so new trigger events start no runs', $diagnosis['verdict']);
        $this->assertStringStartsWith('The automation is paused now', $diagnosis['trigger_events'][0]['why_no_run']);
    }

    public function test_an_overdue_delay_points_at_the_scheduler(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['hours' => 1]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => $this->template->id, 'channel_id' => $this->channel->id]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'wait'], ['from' => 'wait', 'to' => 'send']],
        ]);
        $this->identify('user-1');
        $this->track('user-1', 'signed_up');
        $this->travel(2)->hours();

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertSame('warn', $this->checks($diagnosis)['Run progress']['result']);
        $this->assertStringContainsString('is overdue', $diagnosis['verdict']);
        $this->assertStringContainsString('engage:tick', $diagnosis['verdict']);

        $run = $this->tool($this->key, 'get_run', ['run_id' => $diagnosis['latest_run']['id']]);
        $this->assertStringStartsWith('Overdue', $run['now']['state']);
        $this->assertSame('send', $run['now']['next_step']['node_id']);
        $this->assertSame(1, $this->tool($this->key, 'get_workspace')['engine']['overdue_delays']);
        $this->assertCount(1, $this->tool($this->key, 'list_runs', ['status' => 'overdue'])['items']);
    }

    public function test_a_run_waiting_on_a_delay_says_nothing_has_gone_out_yet(): void
    {
        $automation = $this->makeAutomation($this->workspace, 'signed_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 30]],
                ['id' => 'send', 'type' => 'send_email', 'config' => ['template_id' => $this->template->id]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'wait'], ['from' => 'wait', 'to' => 'send']],
        ]);
        $this->identify('user-1');
        $this->track('user-1', 'signed_up');

        $diagnosis = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => $automation->id]);

        $this->assertStringStartsWith('Not sent yet, and nothing is blocking it: run ', $diagnosis['verdict']);
        $this->assertStringContainsString('is waiting at "Wait 30 minutes" until', $diagnosis['verdict']);
    }

    public function test_broadcasts_explain_recipients_late_joiners_and_drafts(): void
    {
        $everyone = $this->workspace->segments()->where('type', Segment::TYPE_ALL)->value('id');
        $tobi = $this->identify('user-7', ['email' => 'tobi@example.com']);
        $sent = $this->workspace->broadcasts()->create([
            'name' => 'November news', 'channel' => 'email', 'status' => 'completed', 'started_at' => now()->subDay(),
            'segment_id' => $everyone, 'template_id' => $this->template->id, 'channel_id' => $this->channel->id,
        ]);
        $sent->recipients()->create(['person_id' => $tobi->id, 'status' => 'skipped', 'error' => 'person suppressed for email']);
        $this->identify('user-1');

        $skipped = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-7', 'broadcast_id' => $sent->id]);
        $this->assertSame('Skipped: person suppressed for email.', $skipped['verdict']);
        $this->assertSame('skipped', $skipped['recipient']['status']);

        $late = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'broadcast_id' => $sent->id]);
        $this->assertStringStartsWith('They joined segment "All people" at', $late['verdict']);
        $this->assertStringContainsString('after the broadcast started', $late['verdict']);

        $vips = Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'VIPs', 'type' => Segment::TYPE_MANUAL]);
        $draft = $this->workspace->broadcasts()->create([
            'name' => 'VIP invite', 'channel' => 'email', 'status' => 'draft',
            'segment_id' => $vips->id, 'template_id' => $this->template->id, 'channel_id' => $this->channel->id,
        ]);

        $notIn = $this->tool($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'broadcast_id' => $draft->id]);
        $this->assertSame('They are not in segment "VIPs", so they would not receive it.', $notIn['verdict']);
        $this->assertSame('info', $this->checks($notIn)['Broadcast sent']['result']);
    }

    public function test_it_needs_exactly_one_thing_to_diagnose(): void
    {
        $this->identify('user-1');

        $this->assertStringContainsString('automation id', strtolower($this->toolError($this->key, 'diagnose_delivery', ['external_id' => 'user-1'])));
        $this->assertStringContainsString('prohibits', strtolower($this->toolError($this->key, 'diagnose_delivery', ['external_id' => 'user-1', 'automation_id' => 1, 'broadcast_id' => 1])));
    }
}
