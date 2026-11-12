<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkspaces;
use Tests\Concerns\CallsMcp;
use Tests\TestCase;
use TriggerEngage\Server\Engine\SegmentManager;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

class McpToolsTest extends TestCase
{
    use BuildsWorkspaces, CallsMcp, RefreshDatabase;

    protected Workspace $workspace;

    protected string $key;

    protected Template $template;

    protected Channel $channel;

    protected Automation $automation;

    protected Person $ada;

    protected Person $tobi;

    protected Broadcast $broadcast;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        [$this->workspace, $this->key] = $this->makeWorkspace('Africa/Lagos');
        $this->template = $this->makeEmailTemplate($this->workspace);
        $this->channel = $this->makeLogEmailChannel($this->workspace);
        $this->automation = $this->makeAutomation($this->workspace, 'customer_sign_up', $this->linearEmailGraph($this->template, $this->channel));

        $headers = $this->authHeaders($this->workspace, $this->key);
        $this->putJson('/api/v1/people/user-42', [
            'email' => 'ada.lovelace@example.com',
            'phone' => '+2348012345678',
            'attributes' => [
                'first_name' => 'Ada',
                'plan' => 'premium',
                'mobile_app_seen_at' => '2026-11-01T09:00:00Z',
                'expo_push_tokens' => ['ExponentPushToken[abc123]'],
                'whatsapp_number' => '+2348099999999',
            ],
        ], $headers)->assertOk();
        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'user-42', 'data' => ['plan' => 'premium', 'referrer' => 'grace@example.com']], $headers)->assertStatus(202);
        $this->ada = Person::query()->where('external_id', 'user-42')->sole();

        $this->tobi = Person::create(['workspace_id' => $this->workspace->id, 'external_id' => 'user-7', 'email' => 'tobi@example.com', 'phone' => '+2348000000007', 'attributes' => ['plan' => 'free']]);

        $this->broadcast = $this->workspace->broadcasts()->create([
            'name' => 'November news',
            'channel' => 'email',
            'segment_id' => $this->workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $this->template->id,
            'channel_id' => $this->channel->id,
            'status' => 'completed',
            'started_at' => now()->subHour(),
            'body' => '<p>Hello</p>',
        ]);
        $this->broadcast->recipients()->create(['person_id' => $this->tobi->id, 'status' => 'failed', 'error' => 'Mailbox tobi@example.com is full']);

        Message::create([
            'workspace_id' => $this->workspace->id, 'person_id' => $this->tobi->id, 'channel' => 'sms',
            'to_address' => '+2348000000007', 'body' => 'Your session starts in 15 minutes.',
            'status' => 'failed', 'error' => 'Termii rejected the sender id.',
        ]);
    }

    public function test_get_workspace_summarises_setup_and_engine_health(): void
    {
        $data = $this->tool($this->key, 'get_workspace');

        $this->assertSame('Africa/Lagos', $data['workspace']['timezone']);
        $this->assertSame(2, $data['counts']['people']);
        $this->assertSame(['active' => 1], $data['counts']['automations']);
        $this->assertSame(['completed' => 1], $data['counts']['broadcasts']);
        $this->assertSame('email', $data['channels'][0]['type']);
        $this->assertSame(0, $data['engine']['overdue_delays']);
        $this->assertStringEndsWith('+01:00', $data['engine']['last_message_at'], 'Times are in the workspace timezone.');
        $this->assertSame('masked', $data['mcp']['contact_details']);
        $this->assertTrue($data['mcp']['read_only']);
    }

    public function test_events_and_attributes_give_real_field_names(): void
    {
        $event = collect($this->tool($this->key, 'list_events')['items'])->firstWhere('name', 'customer_sign_up');

        $this->assertSame(1, $event['occurrences']);
        $this->assertSame(['plan', 'referrer'], collect($event['payload_keys'])->pluck('key')->sort()->values()->all());
        $this->assertSame('g***@e***.com', collect($event['payload_keys'])->firstWhere('key', 'referrer')['example']);
        $this->assertSame([['id' => $this->automation->id, 'name' => $this->automation->name, 'status' => 'active', 'role' => 'trigger']], $event['used_by_automations']);

        $attributes = $this->tool($this->key, 'list_attributes');
        $byKey = collect($attributes['attributes'])->keyBy('key');

        $this->assertSame(2, $attributes['people']);
        $this->assertSame(['key' => 'email', 'filled' => 2], $attributes['columns'][1]);
        $this->assertEquals(100, $byKey['plan']['fill_rate']);
        $this->assertSame('[1 value redacted]', $byKey['expo_push_tokens']['example'], 'Push tokens never come back.');
        $this->assertSame('+234*******999', $byKey['whatsapp_number']['example']);
        $this->assertSame('2026-11-01T09:00:00Z', $byKey['mobile_app_seen_at']['example'], 'A timestamp under a mobile_* key is not a phone number.');
    }

    public function test_people_come_back_with_contact_details_masked(): void
    {
        $found = $this->tool($this->key, 'search_people', ['query' => 'lovelace']);
        $this->assertSame(['user-42'], array_column($found['items'], 'external_id'));
        $this->assertSame('a***@e***.com', $found['items'][0]['email']);
        $this->assertSame('+234*******678', $found['items'][0]['phone']);
        $this->assertArrayNotHasKey('attributes', $found['items'][0]);

        // A person can still be looked up by the full address the result masks.
        $person = $this->tool($this->key, 'get_person', ['email' => 'ada.lovelace@example.com']);
        $this->assertSame('user-42', $person['person']['external_id']);
        $this->assertSame('[1 value redacted]', $person['person']['attributes']['expo_push_tokens']);
        $this->assertSame(1, $person['counts']['runs']);
        $this->assertSame('completed', $person['recent_runs'][0]['status']);
        $this->assertSame('a***@e***.com', $person['recent_messages'][0]['to']);
        $this->assertTrue($person['channels']['email']['subscribed']);
        $this->assertTrue($person['channels']['push']['reachable']);
        $this->assertSame('All people', $person['segments'][0]['name']);
        $this->assertSame('g***@e***.com', $person['recent_events'][0]['payload']['referrer']);
        $this->assertStringNotContainsString('lovelace', json_encode($person));
        $this->assertStringNotContainsString('abc123', json_encode($person));
    }

    public function test_operators_can_choose_to_show_contact_details_but_never_tokens(): void
    {
        config(['trigger-engage-server.mcp.reveal_contact_details' => true]);

        $person = $this->tool($this->key, 'get_person', ['external_id' => 'user-42']);

        $this->assertSame('ada.lovelace@example.com', $person['person']['email']);
        $this->assertSame('+2348012345678', $person['person']['phone']);
        $this->assertSame('[1 value redacted]', $person['person']['attributes']['expo_push_tokens']);
        $this->assertSame('shown', $this->tool($this->key, 'get_workspace')['mcp']['contact_details']);
    }

    public function test_segment_rules_are_validated_previewed_and_described(): void
    {
        $rules = ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'plan', 'operator' => 'equals', 'value' => 'premium']]];

        $preview = $this->tool($this->key, 'preview_segment', ['rules' => $rules]);
        $this->assertSame(1, $preview['matches']);
        $this->assertSame(2, $preview['out_of']);
        $this->assertSame('person.plan equals "premium"', $preview['rules_in_words']);
        $this->assertSame('user-42', $preview['sample'][0]['external_id']);

        $this->assertSame(['user-42'], array_column($this->tool($this->key, 'search_people', ['rules' => $rules])['items'], 'external_id'));

        $error = $this->toolError($this->key, 'preview_segment', ['rules' => ['match' => 'all', 'conditions' => [['kind' => 'attribute', 'field' => 'plan', 'operator' => 'resembles']]]]);
        $this->assertStringContainsString('Invalid arguments for preview_segment', $error);

        $segment = Segment::create(['workspace_id' => $this->workspace->id, 'name' => 'Premium', 'type' => Segment::TYPE_RULE, 'rules' => $rules]);
        app(SegmentManager::class)->recompute($segment);

        $listed = collect($this->tool($this->key, 'list_segments')['items']);
        $this->assertSame('All people', $listed->first()['name']);
        $this->assertSame(1, $listed->firstWhere('name', 'Premium')['people']);

        $detail = $this->tool($this->key, 'get_segment', ['segment_id' => $segment->public_id]);
        $this->assertSame('person.plan equals "premium"', $detail['segment']['rules_in_words']);
        $this->assertSame('user-42', $detail['members_sample'][0]['external_id']);
        $this->assertSame(1, $detail['people']);
    }

    public function test_templates_render_for_a_real_person_and_report_missing_variables(): void
    {
        $listed = $this->tool($this->key, 'list_templates')['items'][0];
        $this->assertSame('Welcome', $listed['name']);
        $this->assertSame($this->automation->id, $listed['used_by_automations'][0]['id']);

        $template = $this->tool($this->key, 'get_template', ['template_id' => $this->template->id]);
        $this->assertSame(['event.plan', 'person.first_name'], $template['template']['variables']);

        $rendered = $this->tool($this->key, 'render_template', ['template_id' => $this->template->id, 'external_id' => 'user-42']);
        $this->assertSame('Welcome, Ada!', $rendered['subject']);
        $this->assertSame('Hi Ada, you are on .', $rendered['body']);
        $this->assertSame(['event.plan'], $rendered['missing_variables']);
        $this->assertSame('user-42', $rendered['rendered_for']['external_id']);

        $withEvent = $this->tool($this->key, 'render_template', ['template_id' => $this->template->id, 'external_id' => 'user-42', 'event' => ['plan' => 'wellness']]);
        $this->assertSame('Hi Ada, you are on wellness.', $withEvent['body']);
        $this->assertSame([], $withEvent['missing_variables']);

        $html = $this->tool($this->key, 'render_template', ['template_id' => $this->template->id, 'format' => 'html']);
        $this->assertSame('the sample preview context', $html['rendered_for']);
        $this->assertStringContainsString('<html', strtolower($html['body']));
    }

    public function test_automations_read_as_steps_with_their_outcomes(): void
    {
        $listed = $this->tool($this->key, 'list_automations')['items'][0];
        $this->assertSame('customer_sign_up', $listed['trigger_event']['name']);
        $this->assertSame(1, $listed['runs']['completed']);

        $automation = $this->tool($this->key, 'get_automation', ['automation_id' => $this->automation->id]);
        $steps = collect($automation['steps']);

        $this->assertSame(['trigger', 'send', 'done'], $steps->pluck('id')->all());
        $this->assertSame('Send email "Welcome" via Test channel (array)', $steps[1]['summary']);
        $this->assertSame(['completed' => 1], $steps[1]['outcomes']['counts']);
        $this->assertSame([['to' => 'send']], $steps[0]['next']);
        $this->assertTrue($automation['version']['live']);
        $this->assertSame(['completed' => 1], $automation['runs_on_this_version']);
        $this->assertSame([], $automation['editor_issues']);
        $this->assertArrayNotHasKey('graph', $automation);
        $this->assertArrayHasKey('graph', $this->tool($this->key, 'get_automation', ['automation_id' => $this->automation->id, 'include_graph' => true]));
    }

    public function test_runs_show_their_timeline(): void
    {
        $runs = $this->tool($this->key, 'list_runs', ['external_id' => 'user-42'])['items'];
        $this->assertCount(1, $runs);
        $this->assertSame('customer_sign_up', $runs[0]['trigger_event']);

        $this->assertSame([], $this->tool($this->key, 'list_runs', ['status' => 'stalled'])['items']);

        $run = $this->tool($this->key, 'get_run', ['run_id' => $runs[0]['id']]);
        $this->assertSame('Completed.', $run['now']['state']);
        $this->assertSame('premium', $run['trigger']['payload']['plan']);
        $send = collect($run['steps'])->firstWhere('node_id', 'send');
        $this->assertSame('completed', $send['status']);
        $this->assertSame('a***@e***.com', $send['message']['to']);
    }

    public function test_messages_log_and_message_detail(): void
    {
        $all = $this->tool($this->key, 'search_messages');
        $this->assertSame(['failed' => 1, 'sent' => 1], collect($all['counts_by_status'])->sortKeys()->all());

        $problems = $this->tool($this->key, 'search_messages', ['status' => 'problem'])['items'];
        $this->assertCount(1, $problems);
        $this->assertSame('+234*******007', $problems[0]['to']);
        $this->assertSame('Termii rejected the sender id.', $problems[0]['error']);

        $fromAutomation = $this->tool($this->key, 'search_messages', ['automation_id' => $this->automation->id])['items'];
        $this->assertSame('automation', $fromAutomation[0]['source']['type']);

        $message = Message::query()->where('person_id', $this->ada->id)->sole();
        $message->update(['body' => '<html><head><style>p{color:red}</style></head><body><p>Hi Ada</p>'
            .'<a href="https://app.test/unsubscribe/1?expires=999&amp;signature=deadbeef42">Unsubscribe</a>'
            .'<p>Reply to ada.lovelace@example.com</p></body></html>']);

        $detail = $this->tool($this->key, 'get_message', ['message_id' => $message->id]);
        $this->assertStringStartsWith('Hi Ada', $detail['body']);
        $this->assertStringNotContainsString('<p>', $detail['body']);
        $this->assertStringNotContainsString('color:red', $detail['body']);
        $this->assertStringContainsString('signature=[redacted]', $detail['body']);
        $this->assertStringNotContainsString('deadbeef42', $detail['body']);
        $this->assertStringContainsString('a***@e***.com', $detail['body']);
        $this->assertSame($this->automation->id, $detail['source']['automation']['id']);

        $html = $this->tool($this->key, 'get_message', ['message_id' => $message->id, 'format' => 'html']);
        $this->assertStringContainsString('<p>Hi Ada</p>', $html['body']);
        $this->assertStringNotContainsString('deadbeef42', $html['body']);
    }

    public function test_broadcasts_report_outcomes_and_draft_audiences(): void
    {
        $report = $this->tool($this->key, 'get_broadcast', ['broadcast_id' => $this->broadcast->id]);
        $this->assertSame(1, $report['counts']['failed']);
        $this->assertSame([['status' => 'failed', 'reason' => 'Mailbox t***@e***.com is full', 'people' => 1]], $report['reasons']);
        $this->assertSame('user-7', $report['problem_recipients'][0]['person']['external_id']);
        $this->assertNull($report['audience_estimate']);

        $draft = $this->workspace->broadcasts()->create([
            'name' => 'December news', 'channel' => 'email', 'status' => 'draft',
            'segment_id' => $this->broadcast->segment_id, 'template_id' => $this->template->id, 'channel_id' => $this->channel->id,
        ]);

        $listed = collect($this->tool($this->key, 'list_broadcasts')['items'])->keyBy('id');
        $this->assertSame(2, $listed[$draft->id]['audience_estimate']['sendable']);
        $this->assertNull($listed[$this->broadcast->id]['audience_estimate']);
        $this->assertSame(1, $listed[$this->broadcast->id]['recipients']['failed']);
    }

    public function test_channels_never_show_secrets(): void
    {
        $this->workspace->channels()->create([
            'type' => 'email', 'driver' => 'smtp', 'name' => 'ZeptoMail',
            'credentials' => ['host' => 'smtp.zeptomail.com', 'port' => 587, 'username' => 'emailapikey', 'password' => 'stored-secret', 'from_address' => 'hello@mytherapist.test', 'access_token' => 'tok-1'],
        ]);

        $data = $this->tool($this->key, 'list_channels', ['type' => 'email']);
        $zepto = collect($data['channels'])->firstWhere('name', 'ZeptoMail');

        $this->assertSame('smtp.zeptomail.com', $zepto['settings']['host']);
        $this->assertSame('hello@mytherapist.test', $zepto['settings']['from_address'], 'A sender address is configuration, not a person.');
        $this->assertSame(['password', 'access_token'], $zepto['secrets_stored']);
        $this->assertStringNotContainsString('stored-secret', json_encode($data));
        $this->assertStringNotContainsString('tok-1', json_encode($data));

        $default = collect($data['channels'])->firstWhere('id', $this->channel->id);
        $this->assertSame([['id' => $this->automation->id, 'name' => $this->automation->name, 'status' => 'active']], $default['used_by_automations']);
    }

    public function test_analytics_match_the_dashboard(): void
    {
        $data = $this->tool($this->key, 'get_analytics', ['days' => 7]);

        $this->assertSame(7, $data['days']);
        $this->assertSame(2, $data['totals']['messages']['current']);
        $this->assertSame(1, $data['totals']['failed']['current']);
        $this->assertArrayNotHasKey('series', $data);
        $this->assertCount(7, $this->tool($this->key, 'get_analytics', ['days' => 7, 'include_series' => true])['series']['messages']);
    }

    public function test_subscription_topics_are_listed_with_their_choices(): void
    {
        $topic = $this->workspace->subscriptionTopics()->create(['key' => 'news', 'name' => 'News', 'default_subscribed' => true]);
        $topic->preferences()->create(['person_id' => $this->tobi->id, 'subscribed' => false, 'source' => 'api']);

        $topics = $this->tool($this->key, 'list_subscription_topics')['topics'];

        $this->assertSame('news', $topics[0]['key']);
        $this->assertSame(1, $topics[0]['stored_opt_outs']);
    }
}
