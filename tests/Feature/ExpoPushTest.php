<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\Channels\PushChannel;
use TriggerEngage\Server\Jobs\PollExpoPushReceipts;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

class ExpoPushTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_expo_push_action_targets_every_device_token(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'ok', 'id' => 'ticket-1'],
            ['status' => 'ok', 'id' => 'ticket-2'],
        ]])]);
        [$workspace, $key] = $this->makeWorkspace();
        [$template, $channel] = $this->makeExpoAutomation($workspace);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[aaaa]', 'ExponentPushToken[bbbb]'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $this->assertDatabaseHas('messages', [
            'channel' => 'push',
            'status' => 'sent',
            'provider_message_id' => 'ticket-1',
            'to_address' => 'ExponentPushToken[aaaa] +1 more',
        ]);
        $this->assertSame(['ticket-1', 'ticket-2'], Message::query()->sole()->pending_receipts);
        Http::assertSent(fn ($request) => $request->url() === 'https://exp.host/--/api/v2/push/send'
            && $request[0]['to'] === 'ExponentPushToken[aaaa]'
            && $request[1]['to'] === 'ExponentPushToken[bbbb]'
            && $request[0]['title'] === 'Reminder'
            && $request[0]['body'] === 'Hi Ada');
    }

    public function test_expo_push_accepts_a_single_token_attribute(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_token' => 'ExponentPushToken[solo]',
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $this->assertDatabaseHas('messages', ['status' => 'sent', 'to_address' => 'ExponentPushToken[solo]']);
    }

    public function test_expo_push_is_skipped_when_the_profile_holds_no_usable_token(): void
    {
        Http::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace);

        // A raw FCM token is not addressable by Expo and must not be sent.
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['fcm-token-not-expo'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $this->assertDatabaseCount('messages', 0);
        Http::assertNothingSent();
    }

    public function test_a_dead_token_is_pruned_and_the_send_still_counts_as_sent(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'error', 'message' => 'not a registered recipient', 'details' => ['error' => 'DeviceNotRegistered']],
            ['status' => 'ok', 'id' => 'ticket-2'],
        ]])]);
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace);

        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[dead]', 'ExponentPushToken[live]'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $message = Message::query()->sole();
        $this->assertSame('sent', $message->status);
        $this->assertStringContainsString('Partially delivered', $message->error);
        $this->assertSame(['ExponentPushToken[live]'], $person->refresh()->getAttribute('attributes')['expo_push_tokens']);
        // Pruning a token must not mute the person for the whole channel.
        $this->assertFalse($person->isSuppressed('push'));
    }

    public function test_a_send_fails_when_expo_rejects_every_token(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            ['status' => 'error', 'message' => 'not a registered recipient', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[dead]'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $message = Message::query()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('rejected every token', $message->error);
    }

    public function test_a_whole_request_rejection_fails_the_message(): void
    {
        Http::fake(['exp.host/*' => Http::response(['errors' => [['code' => 'UNAUTHORIZED', 'message' => 'bad token']]])]);
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[aaaa]'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        $message = Message::query()->sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('UNAUTHORIZED', $message->error);
    }

    public function test_the_access_token_is_sent_when_configured(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket-1']]])]);
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeExpoAutomation($workspace, ['access_token' => 'secret-token']);

        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[aaaa]'],
        ]]);

        $this->postJson('/api/v1/events', ['name' => 'remind', 'person_id' => 'user-42'], $this->authHeaders($workspace, $key))->assertAccepted();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer secret-token'));
    }

    public function test_receipt_polling_marks_a_message_delivered(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => ['ticket-1' => ['status' => 'ok']]])]);
        [$workspace] = $this->makeWorkspace();
        $channel = $this->makeExpoChannel($workspace);
        $message = $this->makeSentMessage($workspace, ['ticket-1']);

        (new PollExpoPushReceipts($workspace->id))->handle(app(PushChannel::class));

        $message->refresh();
        $this->assertSame('delivered', $message->status);
        $this->assertNotNull($message->delivered_at);
        $this->assertSame([], $message->pending_receipts);
    }

    public function test_receipt_polling_prunes_a_token_that_died_after_the_send(): void
    {
        Http::fake(['exp.host/*' => Http::response(['data' => [
            'ticket-1' => ['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']],
        ]])]);
        [$workspace] = $this->makeWorkspace();
        $this->makeExpoChannel($workspace);
        $message = $this->makeSentMessage($workspace, ['ticket-1']);

        (new PollExpoPushReceipts($workspace->id))->handle(app(PushChannel::class));

        $message->refresh();
        $this->assertSame('failed', $message->status);
        $this->assertSame([], $message->person->getAttribute('attributes')['expo_push_tokens']);
    }

    public function test_receipts_older_than_the_retention_window_stop_being_polled(): void
    {
        Http::fake();
        [$workspace] = $this->makeWorkspace();
        $this->makeExpoChannel($workspace);
        $message = $this->makeSentMessage($workspace, ['ticket-1']);
        $message->update(['sent_at' => now()->subHours(PollExpoPushReceipts::RECEIPT_TTL_HOURS + 1)]);

        (new PollExpoPushReceipts($workspace->id))->handle(app(PushChannel::class));

        $this->assertNull($message->refresh()->pending_receipts);
        Http::assertNothingSent();
    }

    /** @param array<int, string> $tickets */
    protected function makeSentMessage(Workspace $workspace, array $tickets): Message
    {
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'attributes' => [
            'expo_push_tokens' => ['ExponentPushToken[aaaa]'],
        ]]);

        return Message::create([
            'workspace_id' => $workspace->id,
            'person_id' => $person->id,
            'channel' => 'push',
            'to_address' => 'ExponentPushToken[aaaa]',
            'body' => 'Hi Ada',
            'status' => 'sent',
            'provider_message_id' => $tickets[0],
            'pending_receipts' => $tickets,
            'sent_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $credentials */
    protected function makeExpoChannel(Workspace $workspace, array $credentials = []): Channel
    {
        return $workspace->channels()->create([
            'type' => 'push',
            'driver' => 'expo',
            'name' => 'Expo',
            'is_default' => true,
            'credentials' => $credentials,
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{0: Template, 1: Channel}
     */
    protected function makeExpoAutomation(Workspace $workspace, array $credentials = []): array
    {
        $template = $workspace->templates()->create(['channel' => 'push', 'name' => 'Push', 'subject' => 'Reminder', 'body' => 'Hi Ada']);
        $channel = $this->makeExpoChannel($workspace, $credentials);

        $this->makeAutomation($workspace, 'remind', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'send', 'type' => 'send_push', 'config' => ['template_id' => $template->id, 'channel_id' => $channel->id]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'send'], ['from' => 'send', 'to' => 'done']],
        ]);

        return [$template, $channel];
    }
}
