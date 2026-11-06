<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Events\SubscriptionChanged;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\RunStep;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\SubscriptionPreference;
use TriggerEngage\Server\Models\SubscriptionTopic;
use TriggerEngage\Server\Models\Workspace;
use TriggerEngage\Server\Services\Subscriptions;

class SubscriptionPreferencesTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected function topic(Workspace $workspace, array $attributes = []): SubscriptionTopic
    {
        return $workspace->subscriptionTopics()->create([
            'key' => 'care_reminders', 'name' => 'Care reminders', 'default_subscribed' => true, ...$attributes,
        ]);
    }

    /** Fires the trigger for each person through a trigger → send_email → exit journey. */
    protected function sendJourney(Workspace $workspace, string $key, SubscriptionTopic $topic, array $people): void
    {
        $template = $this->makeEmailTemplate($workspace);
        $template->update(['subscription_topic_id' => $topic->id]);
        $this->makeAutomation($workspace, 'session_reminder', $this->linearEmailGraph($template, $this->makeLogEmailChannel($workspace)));

        foreach ($people as $externalId => $properties) {
            $this->putJson("/api/v1/people/{$externalId}", ['email' => "{$externalId}@example.com", 'properties' => $properties], $this->authHeaders($workspace, $key))->assertOk();
            $this->postJson('/api/v1/events', ['name' => 'session_reminder', 'person_id' => $externalId], $this->authHeaders($workspace, $key))->assertStatus(202);
        }
    }

    public function test_a_journey_skips_people_who_opted_out_of_the_templates_topic(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $topic = $this->topic($workspace);
        $out = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'out']);
        SubscriptionPreference::create(['person_id' => $out->id, 'subscription_topic_id' => $topic->id, 'subscribed' => false, 'source' => 'api']);

        $this->sendJourney($workspace, $key, $topic, ['in' => [], 'out' => []]);

        Mail::assertSent(TemplatedMail::class, 1);
        $skipped = RunStep::query()->where('type', 'send_email')->where('status', 'skipped')->sole();
        $this->assertSame('person unsubscribed from Care reminders', $skipped->output['reason']);
        // The message that did go out remembers its topic for the unsubscribe page.
        $this->assertSame($topic->id, Message::sole()->subscription_topic_id);
    }

    public function test_a_topic_can_follow_a_true_false_profile_property(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $topic = $this->topic($workspace, ['property' => 'email_communication']);

        $this->sendJourney($workspace, $key, $topic, [
            'yes' => ['email_communication' => true],
            'no' => ['email_communication' => false],
            'unset' => [],
        ]);

        // true and missing (topic default = subscribed) get it; false does not.
        Mail::assertSent(TemplatedMail::class, 2);
        $this->assertSame(['no'], RunStep::query()->where('status', 'skipped')->with('run.person')->get()->pluck('run.person.external_id')->all());
    }

    public function test_an_opt_in_topic_only_reaches_people_who_opted_in(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $topic = $this->topic($workspace, ['default_subscribed' => false]);
        $in = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'in']);
        SubscriptionPreference::create(['person_id' => $in->id, 'subscription_topic_id' => $topic->id, 'subscribed' => true, 'source' => 'api']);

        $this->sendJourney($workspace, $key, $topic, ['in' => [], 'never-asked' => []]);

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('in@example.com'));
        Mail::assertSent(TemplatedMail::class, 1);
    }

    public function test_broadcasts_skip_topic_opt_outs_and_the_preview_counts_them(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $rowTopic = $this->topic($workspace);
        $propertyTopic = $this->topic($workspace, ['key' => 'news', 'name' => 'News', 'property' => 'wants_news']);
        $template = $this->makeEmailTemplate($workspace);
        $template->update(['subscription_topic_id' => $rowTopic->id]);
        $channel = $this->makeLogEmailChannel($workspace);

        $ada = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com', 'attributes' => ['wants_news' => false]]);
        $tobi = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'tobi', 'email' => 'tobi@example.com']);
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'chi', 'email' => 'chi@example.com']);
        SubscriptionPreference::create(['person_id' => $tobi->id, 'subscription_topic_id' => $rowTopic->id, 'subscribed' => false, 'source' => 'api']);
        $segmentId = $workspace->segments()->where('type', Segment::TYPE_ALL)->value('id');

        // The draft snapshots the template's topic.
        $this->post('/app/broadcasts', ['name' => 'Reminder', 'channel' => 'email', 'segment_id' => $segmentId, 'template_id' => $template->id, 'channel_id' => $channel->id], $this->authHeaders($workspace, $key));
        $broadcast = Broadcast::sole();
        $this->assertSame($rowTopic->id, $broadcast->subscription_topic_id);

        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))->assertInertia(fn (Assert $page) => $page
            ->where('broadcasts.0.audience.total', 3)
            ->where('broadcasts.0.audience.unsubscribed_from_topic', 1)
            ->where('broadcasts.0.audience.sendable', 2));

        // Switching the broadcast to the property topic changes who is skipped.
        $this->put("/app/broadcasts/{$broadcast->id}", ['channel' => 'email', 'name' => 'Reminder', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'subscription_topic_id' => $propertyTopic->id], $this->authHeaders($workspace, $key))->assertSessionHasNoErrors();
        $this->post("/app/broadcasts/{$broadcast->id}/send", [], $this->authHeaders($workspace, $key));

        $this->assertSame('skipped', $broadcast->recipients()->where('person_id', $ada->id)->value('status'));
        $this->assertSame('Person unsubscribed from this topic.', $broadcast->recipients()->where('person_id', $ada->id)->value('error'));
        $this->assertSame(2, $broadcast->recipients()->where('status', 'sent')->count());
        $this->assertSame($propertyTopic->id, Message::query()->where('to_address', 'tobi@example.com')->value('subscription_topic_id'));
    }

    public function test_a_topic_from_another_workspace_is_rejected(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $foreign = $this->topic($other);
        $template = $this->makeEmailTemplate($workspace);

        $this->put("/app/templates/{$template->id}", ['channel' => 'email', 'name' => 'Welcome', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'subscription_topic_id' => $foreign->id], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('subscription_topic_id');
        $this->assertNull($template->refresh()->subscription_topic_id);
    }

    protected function sentMessage(?SubscriptionTopic $topic = null): array
    {
        [$workspace, $key] = $this->makeWorkspace();
        $topic = $topic ?? $this->topic($workspace);
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        $message = Message::create([
            'workspace_id' => $workspace->id, 'person_id' => $person->id, 'channel' => 'email', 'to_address' => 'ada@example.com',
            'status' => 'sent', 'subscription_topic_id' => $topic->id,
        ]);

        return [$workspace, $key, $topic, $person, $message];
    }

    public function test_the_unsubscribe_link_stops_the_topic_by_default_and_can_be_undone(): void
    {
        Event::fake([SubscriptionChanged::class]);
        [$workspace, , $topic, $person, $message] = $this->sentMessage();
        $url = URL::temporarySignedRoute('unsubscribe.show', now()->addDay(), ['message' => $message->id]);

        $this->get($url)->assertOk()
            ->assertSee('Stop “Care reminders” emails?', false)
            ->assertSee('Test Workspace')
            ->assertDontSee('workspace');

        $this->from($url)->post($url, ['scope' => 'topic'])->assertRedirect($url);
        $this->assertFalse(app(Subscriptions::class)->isSubscribed($person, $topic));
        $this->assertFalse($person->isSuppressed('email'));
        Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->scope === 'topic' && $event->target === 'care_reminders' && ! $event->subscribed && $event->source === 'unsubscribe_link');

        $this->post(URL::temporarySignedRoute('unsubscribe.undo', now()->addDay(), ['message' => $message->id]), ['scope' => 'topic'])->assertRedirect();
        $this->assertTrue(app(Subscriptions::class)->isSubscribed($person, $topic));

        // "Stop all emails instead" is a channel-level unsubscribe.
        $this->from($url)->post($url, ['scope' => 'channel']);
        $this->assertTrue($person->isSuppressed('email'));
        $this->assertFalse($person->isSuppressed('sms'));
    }

    public function test_one_click_unsubscribe_needs_no_session_but_needs_the_signature(): void
    {
        [, , $topic, $person, $message] = $this->sentMessage();

        $this->post(route('unsubscribe.one-click', ['message' => $message->id]))->assertForbidden();

        // Mailbox providers POST with no cookies and no CSRF token.
        $this->post(URL::temporarySignedRoute('unsubscribe.one-click', now()->addDay(), ['message' => $message->id]))
            ->assertOk()->assertSee('Unsubscribed.');
        $this->assertFalse(app(Subscriptions::class)->isSubscribed($person, $topic));
        $this->assertSame('one_click', SubscriptionPreference::sole()->source);
    }

    public function test_emails_carry_one_click_unsubscribe_headers(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $this->sendJourney($workspace, $key, $this->topic($workspace), ['ada' => []]);

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            $headers = $mail->headers()->text;

            return str_starts_with($headers['List-Unsubscribe'], '<') && str_contains($headers['List-Unsubscribe'], '/one-click?')
                && $headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click';
        });
    }

    public function test_the_preference_center_saves_topics_and_channels_but_never_lifts_a_bounce(): void
    {
        [$workspace, , $topic, $person] = $this->sentMessage();
        $news = $this->topic($workspace, ['key' => 'news', 'name' => 'News', 'description' => 'Product news, monthly']);
        $person->update(['phone' => '+2348000000000']);
        $person->suppressions()->create(['workspace_id' => $workspace->id, 'channel' => 'email', 'reason' => 'bounce']);
        $url = URL::temporarySignedRoute('preferences.show', now()->addDay(), ['person' => $person->id]);

        $this->get($url)->assertOk()->assertSee('Care reminders')->assertSee('Product news, monthly')->assertSee('We can’t deliver to ada@example.com', false);
        $this->get(route('preferences.show', ['person' => $person->id]))->assertForbidden();

        // Ticks News and email (which the page showed disabled), unticks texts.
        $this->from($url)->post($url, ['topics' => ['news'], 'channels' => ['email']])->assertRedirect($url);

        $subscriptions = app(Subscriptions::class);
        $this->assertFalse($subscriptions->isSubscribed($person, $topic));
        $this->assertTrue($subscriptions->isSubscribed($person, $news));
        $this->assertTrue($person->isSuppressed('email'), 'a bounce cannot be lifted from a public page');
        $this->assertTrue($person->isSuppressed('sms'));
    }

    public function test_resubscribing_one_channel_after_unsubscribing_from_everything_keeps_the_others_off(): void
    {
        [$workspace, , , $person] = $this->sentMessage();
        $person->suppressions()->create(['workspace_id' => $workspace->id, 'channel' => 'all', 'reason' => 'unsubscribe']);

        app(Subscriptions::class)->setChannel($person, 'email', true, 'api');

        $this->assertFalse($person->isSuppressed('email'));
        $this->assertTrue($person->isSuppressed('sms'));
        $this->assertTrue($person->isSuppressed('push'));
    }

    public function test_the_dashboard_manages_topics_and_a_persons_switches(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $template = $this->makeEmailTemplate($workspace);

        $this->post('/app/subscriptions', ['name' => 'Care reminders', 'description' => 'Session nudges', 'default_subscribed' => true], $headers)->assertSessionHasNoErrors();
        $topic = SubscriptionTopic::sole();
        $this->assertSame('care_reminders', $topic->key);
        $this->post('/app/subscriptions', ['name' => 'Care Reminders', 'default_subscribed' => true], $headers)->assertSessionHasErrors('key');
        $this->post('/app/subscriptions', ['name' => 'Bad', 'default_subscribed' => true, 'property' => 'not a property'], $headers)->assertSessionHasErrors('property');

        $template->update(['subscription_topic_id' => $topic->id]);
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        $person->suppressions()->create(['workspace_id' => $workspace->id, 'channel' => 'email', 'reason' => 'bounce']);

        $this->post("/app/people/{$person->id}/subscriptions", ['scope' => 'topic', 'target' => 'care_reminders', 'subscribed' => false], $headers);
        // The team can lift a bounce; a recipient can't.
        $this->post("/app/people/{$person->id}/subscriptions", ['scope' => 'channel', 'target' => 'email', 'subscribed' => true], $headers);

        $this->get("/app/people/{$person->id}", $headers)->assertInertia(fn (Assert $page) => $page
            ->where('subscriptions.channels.email.subscribed', true)
            ->where('subscriptions.topics.0.subscribed', false)
            ->where('subscriptions.topics.0.decided_by', 'preference')
            ->where('subscriptions.topics.0.source', 'dashboard'));

        $this->get('/app/subscriptions', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Subscriptions/Index')
            ->where('topics.0.unsubscribed_count', 1)
            ->where('topics.0.templates.0.id', $template->id));

        $this->delete("/app/subscriptions/{$topic->id}", [], $headers);
        $this->assertNull($template->refresh()->subscription_topic_id);
        $this->assertSame(0, SubscriptionPreference::count());
    }

    public function test_the_api_reads_and_writes_a_persons_subscriptions(): void
    {
        Event::fake([SubscriptionChanged::class]);
        [$workspace, $key] = $this->makeWorkspace();
        $topic = $this->topic($workspace, ['property' => 'email_communication']);
        $headers = $this->authHeaders($workspace, $key);
        $this->putJson('/api/v1/people/user-1', ['email' => 'ada@example.com', 'properties' => ['email_communication' => true]], $headers)->assertOk();

        $this->putJson('/api/v1/people/user-1/subscriptions', ['topics' => ['care_reminders' => false], 'channels' => ['sms' => false]], $headers)
            ->assertOk()
            ->assertJsonPath('topics.care_reminders.subscribed', false)
            ->assertJsonPath('channels.sms.subscribed', false)
            ->assertJsonPath('channels.email.subscribed', true);

        // A property topic writes the property, so the profile and the topic agree.
        $person = Person::query()->where('external_id', 'user-1')->sole();
        $this->assertFalse($person->properties()['email_communication']);
        Event::assertDispatched(SubscriptionChanged::class, fn ($event) => $event->property === 'email_communication' && ! $event->subscribed);

        $this->putJson('/api/v1/people/user-1/subscriptions', ['topics' => ['nope' => false], 'channels' => ['fax' => false]], $headers)
            ->assertUnprocessable()->assertJsonValidationErrors(['topics', 'channels']);
        $this->getJson('/api/v1/people/user-1/subscriptions', $headers)->assertOk()->assertJsonPath('topics.care_reminders.name', 'Care reminders');
        $this->assertTrue($topic->exists);
    }
}
