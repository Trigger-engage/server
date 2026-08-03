<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Jobs\SendBroadcastRecipient;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Workspace;

class BroadcastReportTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    /**
     * A completed broadcast with one recipient in each outcome, statuses and
     * errors written the way SendBroadcastRecipient writes them.
     *
     * @return array{0: Workspace, 1: string, 2: Broadcast, 3: array<string, Person>}
     */
    protected function makeReportedBroadcast(): array
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        $people = [
            'sent' => Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']),
            'skipped' => Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi']),
            'failed' => Person::create(['workspace_id' => $workspace->id, 'external_id' => 'chi', 'email' => 'chi@example.com']),
            'failed_too' => Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ife', 'email' => 'ife@example.com']),
        ];

        $broadcast = $workspace->broadcasts()->create([
            'name' => 'July update',
            'channel' => 'email',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'channel_id' => $channel->id,
            'status' => 'completed',
            'body' => '<p>Hello</p>',
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
        ]);

        $broadcast->recipients()->createMany([
            ['person_id' => $people['sent']->id, 'status' => 'sent', 'sent_at' => now()],
            ['person_id' => $people['skipped']->id, 'status' => 'skipped', 'error' => 'Person has no delivery destination.'],
            ['person_id' => $people['failed']->id, 'status' => 'failed', 'error' => 'Method TriggerEngage\Server\Fake::build does not exist.'],
            ['person_id' => $people['failed_too']->id, 'status' => 'failed', 'error' => 'Method TriggerEngage\Server\Fake::build does not exist.'],
        ]);

        return [$workspace, $key, $broadcast, $people];
    }

    public function test_report_page_splits_outcomes_and_groups_reasons(): void
    {
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        $this->get("/app/broadcasts/{$broadcast->id}", $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Broadcasts/Show')
                ->where('counts.sent', 1)
                ->where('counts.skipped', 1)
                ->where('counts.failed', 2)
                ->where('counts.pending', 0)
                ->where('counts.total', 4)
                // The identical crash on two people groups into ONE reason row.
                ->has('reasons', 2)
                ->where('reasons.0.status', 'failed')
                ->where('reasons.0.total', 2)
                ->has('recipients.data', 4)
                // Failed rows sort first so problems lead the table.
                ->where('recipients.data.0.status', 'failed')
                ->has('recipients.data.0.person.external_id')
            );
    }

    public function test_report_page_filters_recipients_by_status(): void
    {
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        $this->get("/app/broadcasts/{$broadcast->id}?status=skipped", $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('recipients.data', 1)
                ->where('recipients.data.0.status', 'skipped')
                ->where('recipients.data.0.error', 'Person has no delivery destination.')
                // Counts stay unfiltered so the outcome bar keeps the whole story.
                ->where('counts.total', 4)
            );
    }

    public function test_report_for_a_draft_redirects_to_the_composer(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $broadcast = $workspace->broadcasts()->create([
            'name' => 'Draft', 'channel' => 'email', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => '<p>x</p>',
        ]);

        $this->get("/app/broadcasts/{$broadcast->id}", $this->authHeaders($workspace, $key))
            ->assertRedirect("/app/broadcasts/{$broadcast->id}/edit");
    }

    public function test_report_is_workspace_scoped(): void
    {
        [, , $broadcast] = $this->makeReportedBroadcast();
        [$otherWorkspace, $otherKey] = $this->makeWorkspace();

        $this->get("/app/broadcasts/{$broadcast->id}", $this->authHeaders($otherWorkspace, $otherKey))
            ->assertNotFound();
    }

    public function test_retry_requeues_only_failed_recipients(): void
    {
        Bus::fake([SendBroadcastRecipient::class]);
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($workspace, $key))
            ->assertRedirect();

        $broadcast->refresh();
        $this->assertSame('sending', $broadcast->status);
        $this->assertNull($broadcast->completed_at);
        $this->assertSame(2, $broadcast->recipients()->where('status', 'queued')->whereNull('error')->count());
        $this->assertSame(1, $broadcast->recipients()->where('status', 'sent')->count());
        $this->assertSame(1, $broadcast->recipients()->where('status', 'skipped')->count());
        Bus::assertDispatchedTimes(SendBroadcastRecipient::class, 2);
    }

    public function test_retried_recipients_actually_send_and_the_broadcast_completes(): void
    {
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        // Queue is sync in tests, so the retried sends run inline.
        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($workspace, $key))
            ->assertRedirect();

        $broadcast->refresh();
        $this->assertSame('completed', $broadcast->status);
        $this->assertSame(3, $broadcast->recipients()->where('status', 'sent')->count());
        $this->assertSame(0, $broadcast->recipients()->where('status', 'failed')->count());
    }

    public function test_retry_is_workspace_scoped(): void
    {
        Bus::fake([SendBroadcastRecipient::class]);
        [, , $broadcast] = $this->makeReportedBroadcast();
        [$otherWorkspace, $otherKey] = $this->makeWorkspace();

        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($otherWorkspace, $otherKey))
            ->assertNotFound();

        $broadcast->refresh();
        $this->assertSame('completed', $broadcast->status);
        $this->assertSame(2, $broadcast->recipients()->where('status', 'failed')->count());
        Bus::assertNothingDispatched();
    }

    public function test_retry_reuses_the_recipients_existing_message_row(): void
    {
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        // A recipient failed by a real send carries a failed message row; the
        // retry must re-send through THAT row, not mint a duplicate.
        $recipient = $broadcast->recipients()->where('status', 'failed')->first();
        $message = $workspace->messages()->create([
            'person_id' => $recipient->person_id,
            'template_id' => $broadcast->template_id,
            'channel' => 'email',
            'to_address' => $recipient->person->email,
            'status' => 'failed',
            'error' => 'Original transport error.',
        ]);
        $recipient->update(['message_id' => $message->id]);
        $messagesBefore = $workspace->messages()->count();

        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($workspace, $key))
            ->assertRedirect();

        $recipient->refresh();
        $this->assertSame('sent', $recipient->status);
        $this->assertSame($message->id, $recipient->message_id);
        $this->assertSame('sent', $message->fresh()->status);
        // The other failed recipient minted its first message; ours reused its row.
        $this->assertSame($messagesBefore + 1, $workspace->messages()->count());
    }

    public function test_retry_refuses_when_nothing_failed(): void
    {
        Bus::fake([SendBroadcastRecipient::class]);
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();
        $broadcast->recipients()->where('status', 'failed')->update(['status' => 'sent', 'error' => null]);

        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('broadcast');
        Bus::assertNothingDispatched();
    }

    public function test_retry_refuses_while_a_send_is_in_flight(): void
    {
        Bus::fake([SendBroadcastRecipient::class]);
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();
        $broadcast->update(['status' => 'sending']);

        $this->post("/app/broadcasts/{$broadcast->id}/retry-failed", [], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('broadcast');
        Bus::assertNothingDispatched();
    }

    public function test_index_splits_sent_skipped_and_failed_counts(): void
    {
        [$workspace, $key, $broadcast] = $this->makeReportedBroadcast();

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcasts.0.sent_count', 1)
                ->where('broadcasts.0.skipped_count', 1)
                ->where('broadcasts.0.failed_count', 2)
                ->where('broadcasts.0.recipients_count', 4)
                ->where('broadcasts.0.audience', null)
            );
    }

    public function test_drafts_carry_a_pre_send_audience_preview(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ok', 'email' => 'ok@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'no-email']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'gone', 'email' => 'gone@example.com', 'unsubscribed_at' => now()]);

        $broadcast = $workspace->broadcasts()->create([
            'name' => 'Draft', 'channel' => 'email', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => '<p>x</p>',
        ]);

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcasts.0.audience.total', 3)
                ->where('broadcasts.0.audience.sendable', 1)
                ->where('broadcasts.0.audience.no_destination', 1)
                ->where('broadcasts.0.audience.suppressed', 1)
            );

        $this->get("/app/broadcasts/{$broadcast->id}/edit", $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcast.audience.total', 3)
                ->where('broadcast.audience.sendable', 1)
            );
    }

    public function test_audience_preview_counts_empty_string_destinations_as_missing(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        // The send job skips on blank($address) — '' is as unreachable as null.
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'empty', 'email' => '']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ok', 'email' => 'ok@example.com']);

        $workspace->broadcasts()->create([
            'name' => 'Draft', 'channel' => 'email', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => '<p>x</p>',
        ]);

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcasts.0.audience.sendable', 1)
                ->where('broadcasts.0.audience.no_destination', 1)
            );
    }

    public function test_sms_audience_preview_checks_phone_numbers(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $workspace->templates()->create(['channel' => 'sms', 'name' => 'SMS', 'body' => 'Hi {{ person.first_name }}']);
        $channel = $workspace->channels()->create(['type' => 'sms', 'driver' => 'log', 'name' => 'SMS log', 'is_default' => true]);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'has-phone', 'phone' => '2348010000000', 'email' => 'a@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'email-only', 'email' => 'b@example.com']);

        $workspace->broadcasts()->create([
            'name' => 'SMS draft', 'channel' => 'sms', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => 'Hi',
        ]);

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcasts.0.audience.total', 2)
                ->where('broadcasts.0.audience.sendable', 1)
                ->where('broadcasts.0.audience.no_destination', 1)
            );
    }

    public function test_push_audience_preview_treats_anonymous_profiles_as_unreachable(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $template = $workspace->templates()->create(['channel' => 'push', 'name' => 'Push', 'subject' => 'Hey', 'body' => 'Hi']);
        $channel = $workspace->channels()->create(['type' => 'push', 'driver' => 'onesignal', 'name' => 'Push', 'credentials' => ['app_id' => 'x', 'api_key' => 'y'], 'is_default' => true]);

        // Identified: reachable via external_id fallback. Anonymous with a stored
        // OneSignal id: reachable. Anonymous with nothing: the send job skips it.
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => null, 'anonymous_id' => 'anon-1', 'attributes' => ['onesignal_external_id' => 'osid-1']]);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => null, 'anonymous_id' => 'anon-2']);

        $workspace->broadcasts()->create([
            'name' => 'Push draft', 'channel' => 'push', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id, 'status' => 'draft', 'body' => 'Hi',
        ]);

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page
                ->where('broadcasts.0.audience.total', 3)
                ->where('broadcasts.0.audience.sendable', 2)
                ->where('broadcasts.0.audience.no_destination', 1)
            );
    }
}
