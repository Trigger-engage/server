<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Workspace;

class MessagesLogTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    /**
     * One message sent by an automation run (through the real engine) and one
     * by a broadcast, plus a failed SMS, for the same workspace.
     *
     * @return array{0: Workspace, 1: string, 2: Automation, 3: Broadcast, 4: Person}
     */
    protected function makeSentMessages(): array
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $automation = $this->makeAutomation($workspace, 'customer_sign_up', $this->linearEmailGraph($template, $channel));

        $headers = $this->authHeaders($workspace, $key);
        $this->putJson('/api/v1/people/user-42', ['attributes' => ['email' => 'ada@example.com', 'first_name' => 'Ada']], $headers)->assertOk();
        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'user-42'], $headers)->assertStatus(202);
        $ada = Person::query()->where('external_id', 'user-42')->sole();

        $tobi = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-7', 'email' => 'tobi@example.com', 'phone' => '+2348000000007']);
        $broadcast = $workspace->broadcasts()->create([
            'name' => 'July update',
            'channel' => 'email',
            'segment_id' => $workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $template->id,
            'channel_id' => $channel->id,
            'status' => 'completed',
            'body' => '<p>Hello</p>',
        ]);
        $broadcastMessage = Message::create([
            'workspace_id' => $workspace->id, 'person_id' => $tobi->id, 'template_id' => $template->id,
            'channel' => 'email', 'to_address' => 'tobi@example.com', 'subject' => 'July update',
            'body' => '<html><head></head><body><p>Hello Tobi</p></body></html>', 'status' => 'delivered',
            'sent_at' => now(), 'delivered_at' => now(),
        ]);
        $broadcast->recipients()->create(['person_id' => $tobi->id, 'message_id' => $broadcastMessage->id, 'status' => 'sent', 'sent_at' => now()]);

        Message::create([
            'workspace_id' => $workspace->id, 'person_id' => $tobi->id, 'channel' => 'sms',
            'to_address' => '+2348000000007', 'body' => 'Your session starts in 15 minutes.',
            'status' => 'failed', 'error' => 'Termii rejected the sender id.',
        ]);

        return [$workspace, $key, $automation, $broadcast, $ada];
    }

    public function test_index_lists_every_message_with_where_it_came_from(): void
    {
        [$workspace, $key, $automation, $broadcast, $ada] = $this->makeSentMessages();
        $run = AutomationRun::sole();

        // Another workspace's message must never show up.
        [$other] = $this->makeWorkspace();
        $stranger = Person::create(['workspace_id' => $other->id, 'external_id' => 'user-99', 'email' => 'x@example.com']);
        Message::create(['workspace_id' => $other->id, 'person_id' => $stranger->id, 'channel' => 'email', 'to_address' => 'x@example.com', 'status' => 'sent']);

        $this->get('/app/messages', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Messages/Index')
                ->has('messages.data', 3)
                ->where('counts.all', 3)
                ->where('counts.problem', 1)
                // Newest first: the failed SMS, then the broadcast, then the run.
                ->where('messages.data.0.channel', 'sms')
                ->where('messages.data.0.excerpt', 'Your session starts in 15 minutes.')
                ->where('messages.data.0.error', 'Termii rejected the sender id.')
                ->where('messages.data.0.source', null)
                ->where('messages.data.1.source.type', 'broadcast')
                ->where('messages.data.1.source.broadcast.name', 'July update')
                ->where('messages.data.2.source.type', 'automation')
                ->where('messages.data.2.source.run_id', $run->id)
                ->where('messages.data.2.source.automation.id', $automation->id)
                ->where('messages.data.2.person.external_id', 'user-42')
                // Email rows never ship their rendered HTML to the list.
                ->missing('messages.data.1.body')
                ->where('messages.data.1.excerpt', null));
    }

    public function test_filters_narrow_by_status_person_source_and_search(): void
    {
        [$workspace, $key, $automation, $broadcast, $ada] = $this->makeSentMessages();
        $headers = $this->authHeaders($workspace, $key);

        $this->get('/app/messages?status=problem', $headers)->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)->where('messages.data.0.status', 'failed')
            // Chip counts ignore the status filter itself.
            ->where('counts.all', 3));

        $this->get("/app/messages?person={$ada->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)->where('messages.data.0.to_address', 'ada@example.com')
            ->where('scope.person.external_id', 'user-42'));

        $this->get("/app/messages?automation={$automation->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)->where('messages.data.0.source.type', 'automation'));

        $this->get("/app/messages?broadcast={$broadcast->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)->where('messages.data.0.subject', 'July update')
            ->where('scope.broadcast.name', 'July update'));

        $this->get('/app/messages?channel=sms', $headers)->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)->where('messages.data.0.channel', 'sms'));

        // Search reaches the address, the subject and the person's id.
        $this->get('/app/messages?search=tobi@', $headers)->assertInertia(fn (Assert $page) => $page->has('messages.data', 1));
        $this->get('/app/messages?search=user-4', $headers)->assertInertia(fn (Assert $page) => $page->has('messages.data', 1));
        // LIKE wildcards are searched literally.
        $this->get('/app/messages?search=%25', $headers)->assertInertia(fn (Assert $page) => $page->has('messages.data', 0));

        $this->travel(2)->days();
        $this->get('/app/messages?days=1', $headers)->assertInertia(fn (Assert $page) => $page->has('messages.data', 0));
        $this->get('/app/messages?days=7', $headers)->assertInertia(fn (Assert $page) => $page->has('messages.data', 3));
    }

    public function test_show_page_has_the_sent_content_and_is_workspace_scoped(): void
    {
        [$workspace, $key, , $broadcast] = $this->makeSentMessages();
        $message = Message::query()->where('subject', 'July update')->sole();

        $this->get("/app/messages/{$message->id}", $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Messages/Show')
                ->where('message.body', '<html><head></head><body><p>Hello Tobi</p></body></html>')
                ->where('message.status', 'delivered')
                ->where('message.source.broadcast.id', $broadcast->id)
                ->where('message.person.external_id', 'user-7'));

        [$other, $otherKey] = $this->makeWorkspace();
        $this->get("/app/messages/{$message->id}", $this->authHeaders($other, $otherKey))->assertNotFound();
    }

    public function test_engine_message_detail_links_back_to_its_run(): void
    {
        [$workspace, $key] = $this->makeSentMessages();
        $run = AutomationRun::sole();
        $message = Message::query()->where('to_address', 'ada@example.com')->sole();

        $this->get("/app/messages/{$message->id}", $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->where('message.source.type', 'automation')
                ->where('message.source.run_id', $run->id)
                ->where('message.status', 'sent'));
    }

    public function test_person_page_lists_recent_messages_and_runs(): void
    {
        [$workspace, $key, , , $ada] = $this->makeSentMessages();

        $this->get("/app/people/{$ada->id}", $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recentMessages', 1)
                ->where('recentMessages.0.to_address', 'ada@example.com')
                ->has('recentRuns', 1)
                ->where('recentRuns.0.automation.name', 'test_automation_customer_sign_up'));
    }

    public function test_runs_filter_by_status_automation_person_and_failed_steps(): void
    {
        [$workspace, $key, $automation, , $ada] = $this->makeSentMessages();
        $headers = $this->authHeaders($workspace, $key);
        $run = AutomationRun::sole();

        // A completed run whose send failed: green status, but a failed step.
        $failing = $run->replicate()->fill(['event_occurrence_id' => null, 'status' => AutomationRun::STATUS_COMPLETED]);
        $failing->save();
        $failing->steps()->create(['node_id' => 'send', 'type' => 'send_email', 'status' => 'failed', 'error' => 'SMTP timeout', 'executed_at' => now()]);

        $this->get('/app/runs?status=step_failed', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Runs/Index')
            ->has('runs.data', 1)
            ->where('runs.data.0.id', $failing->id)
            ->where('runs.data.0.failed_steps_count', 1)
            ->where('filters.status', 'step_failed'));

        $this->get('/app/runs?status=completed', $headers)->assertInertia(fn (Assert $page) => $page->has('runs.data', 2));
        $this->get('/app/runs?status=active', $headers)->assertInertia(fn (Assert $page) => $page->has('runs.data', 0));
        $this->get("/app/runs?automation={$automation->id}", $headers)->assertInertia(fn (Assert $page) => $page->has('runs.data', 2));
        $this->get('/app/runs?automation=999999', $headers)->assertInertia(fn (Assert $page) => $page->has('runs.data', 0));
        $this->get("/app/runs?person={$ada->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->has('runs.data', 2)
            ->where('scopedPerson.external_id', 'user-42'));
        // An unknown status is ignored rather than matching nothing.
        $this->get('/app/runs?status=bogus', $headers)->assertInertia(fn (Assert $page) => $page
            ->has('runs.data', 2)->where('filters.status', ''));
    }

    public function test_broadcast_report_links_each_recipient_to_their_message(): void
    {
        [$workspace, $key, , $broadcast] = $this->makeSentMessages();
        $message = Message::query()->where('subject', 'July update')->sole();

        $this->get("/app/broadcasts/{$broadcast->id}", $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where('recipients.data.0.message_id', $message->id));
    }
}
