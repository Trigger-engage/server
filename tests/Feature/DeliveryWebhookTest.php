<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;

class DeliveryWebhookTest extends TestCase
{
    use BuildsWorkspaces, RefreshDatabase;

    public function test_termii_webhook_requires_signature_and_marks_delivered(): void
    {
        [$workspace] = $this->makeWorkspace();
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'u1']);
        $channel = $workspace->channels()->create(['type' => 'sms', 'driver' => 'termii', 'name' => 'Termii', 'credentials' => ['secret_key' => 'secret']]);
        $message = Message::create(['workspace_id' => $workspace->id, 'person_id' => $person->id, 'channel' => 'sms', 'to_address' => '2348000', 'provider_message_id' => 'm1', 'status' => 'sent']);
        $payload = json_encode(['id' => 'event-1', 'message_id' => 'm1', 'status' => 'DELIVERED']);
        $signature = hash_hmac('sha512', $payload, 'secret');

        $this->call('POST', "/api/v1/webhooks/termii/{$channel->id}", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TERMII_SIGNATURE' => $signature], $payload)->assertAccepted();

        $this->assertSame('delivered', $message->refresh()->status);
    }
}
