<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Event;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;

class SegmentsAndBroadcastsTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_every_workspace_has_an_all_people_segment_that_tracks_every_profile(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();

        $this->assertSame('All people', $segment->name);
        $this->assertSame(0, $segment->people()->count());

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi', 'email' => 'tobi@example.com']);

        $this->assertSame(['ada', 'tobi'], $segment->people()->orderBy('external_id')->pluck('external_id')->all());
        $this->assertSame(['system'], $segment->people()->pluck('source')->unique()->values()->all());

        $person = $segment->people()->first();
        $this->putJson("/api/v1/segments/{$segment->public_id}/people/{$person->external_id}", [], $this->authHeaders($workspace, $key))
            ->assertUnprocessable();
    }

    public function test_all_people_segment_can_be_used_for_a_workspace_wide_broadcast(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi', 'email' => 'tobi@example.com']);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        $this->post('/app/broadcasts', [
            'name' => 'Everyone update',
            'channel' => 'email',
            'segment_id' => $segment->id,
            'template_id' => $template->id,
            'channel_id' => $channel->id,
        ], $headers)->assertRedirect();

        $broadcast = $workspace->broadcasts()->sole();
        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $headers)->assertRedirect();

        $this->assertSame(2, $broadcast->recipients()->count());
        $this->assertSame(2, $workspace->messages()->count());
    }

    public function test_event_automatically_adds_person_to_matching_segments_once(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'session_booked']);
        $segment = $workspace->segments()->create(['name' => 'Booked', 'type' => Segment::TYPE_EVENT, 'event_id' => $event->id]);
        $headers = $this->authHeaders($workspace, $key);

        $payload = ['name' => 'session_booked', 'person_id' => 'user-1', 'idempotency_key' => 'booking-1'];
        $this->postJson('/api/v1/events', $payload, $headers)->assertAccepted();
        $this->postJson('/api/v1/events', $payload, $headers)->assertOk()->assertJsonPath('duplicate', true);

        $this->assertSame(['user-1'], $segment->people()->pluck('external_id')->all());
        $this->assertSame('event', $segment->people()->first()->pivot->source);
    }

    public function test_manual_segment_membership_can_be_added_and_removed_through_api(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        $segment = $workspace->segments()->create(['name' => 'VIP', 'type' => Segment::TYPE_MANUAL]);
        $headers = $this->authHeaders($workspace, $key);

        $this->putJson("/api/v1/segments/{$segment->public_id}/people/{$person->external_id}", [], $headers)
            ->assertOk()->assertJsonPath('member', true);
        $this->assertTrue($segment->people()->whereKey($person->id)->exists());

        $this->deleteJson("/api/v1/segments/{$segment->public_id}/people/{$person->external_id}", [], $headers)
            ->assertOk()->assertJsonPath('member', false);
        $this->assertFalse($segment->people()->whereKey($person->id)->exists());
    }

    public function test_manual_segment_management_page_can_search_add_and_remove_people(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $ada = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi', 'email' => 'tobi@example.com']);

        $this->get("/app/segments/{$segment->id}?add_search=ada", $headers)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Segments/Show')
                ->where('segment.name', 'Newsletter')
                ->where('segment.editable_membership', true)
                ->has('members.data', 0)
                ->has('availablePeople', 1)
                ->where('availablePeople.0.external_id', 'ada'));

        $this->post("/app/segments/{$segment->id}/people/{$ada->id}", [], $headers)->assertRedirect();
        $this->assertTrue($segment->people()->whereKey($ada->id)->exists());

        $this->get("/app/segments/{$segment->id}", $headers)
            ->assertInertia(fn (Assert $page) => $page
                ->has('members.data', 1)
                ->where('members.data.0.external_id', 'ada'));

        $this->delete("/app/segments/{$segment->id}/people/{$ada->id}", [], $headers)->assertRedirect();
        $this->assertFalse($segment->people()->whereKey($ada->id)->exists());
    }

    public function test_segments_can_be_renamed_and_deleted_but_all_people_is_protected(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Old name', 'type' => Segment::TYPE_MANUAL]);
        $allPeople = $workspace->segments()->where('type', Segment::TYPE_ALL)->sole();
        $otherSegmentId = $other->segments()->where('type', Segment::TYPE_ALL)->value('id');
        $outsider = Person::create(['workspace_id' => $other->id, 'external_id' => 'outside']);

        $this->put("/app/segments/{$segment->id}", ['name' => 'New name', 'description' => 'Updated audience'], $headers)
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('New name', $segment->refresh()->name);

        $this->put("/app/segments/{$allPeople->id}", ['name' => 'Everyone'], $headers)->assertUnprocessable();
        $this->delete("/app/segments/{$allPeople->id}", [], $headers)->assertUnprocessable();
        $this->post("/app/segments/{$segment->id}/people/{$outsider->id}", [], $headers)->assertNotFound();
        $this->get("/app/segments/{$otherSegmentId}", $headers)->assertNotFound();

        $this->delete("/app/segments/{$segment->id}", [], $headers)->assertRedirect('/app/segments');
        $this->assertModelMissing($segment);
        $this->assertModelExists($allPeople);
    }

    public function test_segment_used_by_broadcast_history_cannot_be_deleted(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->post('/app/broadcasts', [
            'name' => 'History', 'channel' => 'email', 'segment_id' => $segment->id,
            'template_id' => $template->id, 'channel_id' => $channel->id,
        ], $headers)->assertRedirect();

        $this->delete("/app/segments/{$segment->id}", [], $headers)
            ->assertRedirect()
            ->assertSessionHasErrors('segment');
        $this->assertModelExists($segment);
    }

    public function test_api_cannot_manually_change_automatic_or_cross_workspace_segments(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-1']);
        $event = Event::create(['workspace_id' => $workspace->id, 'name' => 'paid']);
        $automatic = $workspace->segments()->create(['name' => 'Paid', 'type' => Segment::TYPE_EVENT, 'event_id' => $event->id]);
        $private = $other->segments()->create(['name' => 'Private', 'type' => Segment::TYPE_MANUAL]);
        $headers = $this->authHeaders($workspace, $key);

        $this->putJson("/api/v1/segments/{$automatic->public_id}/people/{$person->external_id}", [], $headers)->assertUnprocessable();
        $this->putJson("/api/v1/segments/{$private->public_id}/people/{$person->external_id}", [], $headers)->assertNotFound();
    }

    public function test_broadcast_snapshots_segment_and_delivers_one_message_per_member(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $ada = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com', 'attributes' => ['first_name' => 'Ada']]);
        $tobi = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi', 'email' => 'tobi@example.com', 'attributes' => ['first_name' => 'Tobi']]);
        $segment->people()->attach([$ada->id => ['source' => 'api', 'added_at' => now()], $tobi->id => ['source' => 'api', 'added_at' => now()]]);
        $template = $this->makeEmailTemplate($workspace, 'Hello {{ person.first_name }}', '<p>Audience update</p>');
        $channel = $this->makeLogEmailChannel($workspace);

        $this->post('/app/broadcasts', ['name' => 'Newsletter', 'channel' => 'email', 'segment_id' => $segment->id, 'template_id' => $template->id, 'channel_id' => $channel->id], $headers)->assertRedirect();
        $broadcast = $workspace->broadcasts()->sole();
        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $headers)->assertRedirect();

        $this->assertSame('completed', $broadcast->refresh()->status);
        $this->assertSame(2, $broadcast->recipients()->where('status', 'sent')->count());
        $this->assertSame(2, $workspace->messages()->count());

        $late = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'late', 'email' => 'late@example.com']);
        $segment->people()->attach($late->id, ['source' => 'api', 'added_at' => now()]);
        $this->assertSame(2, $broadcast->recipients()->count());
        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $headers)->assertSessionHasErrors('broadcast');
        $this->assertSame(2, $workspace->messages()->count());
    }

    public function test_creating_a_broadcast_snapshots_template_content_and_opens_the_composer(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $template = $this->makeEmailTemplate($workspace, 'Template subject', '<p>Template body</p>');
        $template->update(['settings' => ['accent_color' => '#123456']]);
        $channel = $this->makeLogEmailChannel($workspace);

        $response = $this->post('/app/broadcasts', ['name' => 'July update', 'channel' => 'email', 'segment_id' => $segment->id, 'template_id' => $template->id, 'channel_id' => $channel->id], $headers);
        $broadcast = $workspace->broadcasts()->sole();

        $response->assertRedirect("/app/broadcasts/{$broadcast->id}/edit");
        $this->assertSame('Template subject', $broadcast->subject);
        $this->assertSame('<p>Template body</p>', $broadcast->body);
        $this->assertSame('mytherapist', $broadcast->layout);
        $this->assertSame('#123456', $broadcast->settings['accent_color']);
    }

    public function test_broadcast_composer_is_workspace_scoped_and_returns_preview(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other, $otherKey] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $template = $this->makeEmailTemplate($workspace, 'Hello {{ person.first_name }}', '<p>Body</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $this->post('/app/broadcasts', ['name' => 'July update', 'channel' => 'email', 'segment_id' => $segment->id, 'template_id' => $template->id, 'channel_id' => $channel->id], $headers);
        $broadcast = $workspace->broadcasts()->sole();

        $this->get("/app/broadcasts/{$broadcast->id}/edit", $headers)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Broadcasts/Edit')
                ->where('broadcast.id', $broadcast->id)
                ->where('broadcast.editable', true)
                ->where('broadcast.segment.name', 'Newsletter')
                ->where('preview.subject', 'Hello Ada')
            );

        $this->get("/app/broadcasts/{$broadcast->id}/edit")->assertUnauthorized();
        $this->get("/app/broadcasts/{$broadcast->id}/edit", $this->authHeaders($other, $otherKey))->assertNotFound();
    }

    public function test_broadcast_content_can_be_edited_and_overrides_the_template_on_send(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $ada = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com', 'attributes' => ['first_name' => 'Ada']]);
        $segment->people()->attach($ada->id, ['source' => 'api', 'added_at' => now()]);
        $template = $this->makeEmailTemplate($workspace, 'Template subject', '<p>Template body</p>');
        $channel = $this->makeLogEmailChannel($workspace);
        $this->post('/app/broadcasts', ['name' => 'July update', 'channel' => 'email', 'segment_id' => $segment->id, 'template_id' => $template->id, 'channel_id' => $channel->id], $headers);
        $broadcast = $workspace->broadcasts()->sole();

        $this->put("/app/broadcasts/{$broadcast->id}", [
            'channel' => 'sms', // must be ignored — the broadcast keeps its own channel
            'name' => 'July update',
            'subject' => 'A note for {{ person.first_name }}',
            'body' => '<p>Special offer for {{ person.first_name }}</p>',
            'layout' => 'mytherapist',
        ], $headers)->assertRedirect();

        $broadcast->refresh();
        $this->assertSame('email', $broadcast->channel);
        $this->assertSame('A note for {{ person.first_name }}', $broadcast->subject);

        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $headers)->assertRedirect();

        $message = $workspace->messages()->sole();
        $this->assertSame('A note for Ada', $message->subject);
        $this->assertStringContainsString('Special offer for Ada', $message->body);
        // The underlying template is untouched by the broadcast edit.
        $this->assertSame('Template subject', $template->refresh()->subject);
        $this->assertSame($template->id, $message->template_id);
    }

    public function test_sent_broadcast_content_cannot_be_edited(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $segment = $workspace->segments()->create(['name' => 'Newsletter', 'type' => Segment::TYPE_MANUAL]);
        $ada = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        $segment->people()->attach($ada->id, ['source' => 'api', 'added_at' => now()]);
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->post('/app/broadcasts', ['name' => 'July update', 'channel' => 'email', 'segment_id' => $segment->id, 'template_id' => $template->id, 'channel_id' => $channel->id], $headers);
        $broadcast = $workspace->broadcasts()->sole();
        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $headers)->assertRedirect();

        $this->put("/app/broadcasts/{$broadcast->id}", [
            'channel' => 'email', 'name' => 'Changed', 'body' => '<p>Too late</p>',
        ], $headers)->assertSessionHasErrors('broadcast');
    }

    public function test_segment_and_broadcast_pages_are_scoped(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $workspace->segments()->create(['name' => 'Visible', 'type' => Segment::TYPE_MANUAL]);
        $other->segments()->create(['name' => 'Hidden', 'type' => Segment::TYPE_MANUAL]);
        $headers = $this->authHeaders($workspace, $key);

        $this->get('/app/segments', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Segments/Index')
            ->has('segments', 2)
            ->where('segments.0.name', 'All people')
            ->where('segments.0.type', Segment::TYPE_ALL)
            ->where('segments.1.name', 'Visible'));
        $this->get('/app/broadcasts', $headers)->assertInertia(fn (Assert $page) => $page->component('Broadcasts/Index')->has('segments', 2));
        $this->get('/app/segments')->assertUnauthorized();
        $this->get('/app/broadcasts')->assertUnauthorized();
    }
}
