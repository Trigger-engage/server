<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\ApiKey;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Workspace;
use TriggerEngage\Server\Support\ClientIdentity;

class ClientApiTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    /** @return array{0: Workspace, 1: string, 2: ApiKey, 3: string} workspace, server key, client key, identity secret */
    protected function makeClient(array $origins = [], array $events = []): array
    {
        [$workspace, $serverKey] = $this->makeWorkspace();
        [$client, $secret] = ApiKey::issueClient($workspace, 'App', $origins ?: null, $events ?: null);

        return [$workspace, $serverKey, $client, $secret];
    }

    /** @return array<string, string> */
    protected function clientHeaders(ApiKey $client, ?string $person = null, ?string $token = null): array
    {
        return array_filter([
            'X-TE-Key' => $client->public_key,
            'X-TE-Person' => $person,
            'X-TE-Identity' => $token ?? ($person ? $client->identityTokenFor($person) : null),
        ]);
    }

    public function test_a_client_key_never_opens_the_server_api(): void
    {
        [$workspace, $serverKey, $client] = $this->makeClient();

        $this->getJson('/api/v1/people', ['Authorization' => 'Basic '.base64_encode($workspace->public_id.':'.$client->public_key)])->assertUnauthorized();
        $this->getJson('/api/v1/people', $this->authHeaders($workspace, $serverKey))->assertOk();

        ApiKey::query()->where('kind', 'server')->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/people', $this->authHeaders($workspace, $serverKey))->assertUnauthorized();
    }

    public function test_an_anonymous_device_can_send_events_and_is_merged_when_it_identifies(): void
    {
        [$workspace, , $client] = $this->makeClient();

        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $this->clientHeaders($client))
            ->assertUnprocessable()->assertJsonValidationErrors('anonymous_id');
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened', 'anonymous_id' => 'device-1', 'data' => ['screen' => 'home']], $this->clientHeaders($client))
            ->assertStatus(202)->assertJson(['accepted' => true, 'duplicate' => false]);

        $anonymous = Person::query()->where('anonymous_id', 'device-1')->sole();
        $this->assertNull($anonymous->external_id);

        $this->postJson('/api/v1/client/identify', [
            'anonymous_id' => 'device-1',
            'properties' => ['favourite_tool' => 'breathing', 'email' => 'attacker@example.com'],
        ], $this->clientHeaders($client, 'user-42'))->assertOk();

        $person = Person::query()->where('external_id', 'user-42')->sole();
        $this->assertSame('breathing', $person->properties()['favourite_tool']);
        $this->assertNull($person->email, 'an app cannot change the email on its profile');
        $this->assertSame($person->id, EventOccurrence::sole()->person_id, 'the anonymous history moved to the person');
    }

    public function test_identified_events_need_a_matching_token_and_start_journeys(): void
    {
        Mail::fake();
        [$workspace, , $client] = $this->makeClient();
        $template = $this->makeEmailTemplate($workspace);
        $this->makeAutomation($workspace, 'app_opened', $this->linearEmailGraph($template, $this->makeLogEmailChannel($workspace)));
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'email' => 'ada@example.com']);

        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $this->clientHeaders($client, 'user-42', 'forged'))
            ->assertUnauthorized();
        // Signed for someone else.
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $this->clientHeaders($client, 'user-42', $client->identityTokenFor('user-7')))
            ->assertUnauthorized();
        Mail::assertNothingSent();

        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $this->clientHeaders($client, 'user-42'))->assertStatus(202);
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('ada@example.com'));
    }

    public function test_client_idempotency_keys_are_their_own_namespace(): void
    {
        [$workspace, $serverKey, $client] = $this->makeClient();
        $this->postJson('/api/v1/events', ['name' => 'paid', 'person_id' => 'user-1', 'idempotency_key' => 'abc'], $this->authHeaders($workspace, $serverKey))->assertStatus(202);

        $headers = $this->clientHeaders($client, 'user-1');
        $this->postJson('/api/v1/client/events', ['name' => 'paid', 'idempotency_key' => 'abc'], $headers)->assertJson(['duplicate' => false]);
        $this->postJson('/api/v1/client/events', ['name' => 'paid', 'idempotency_key' => 'abc'], $headers)->assertJson(['duplicate' => true]);
        $this->assertSame(2, EventOccurrence::count());
    }

    public function test_a_key_can_be_limited_to_some_events_and_some_origins(): void
    {
        // As in an embedded host, whose CORS config doesn't cover these routes.
        config(['cors.paths' => []]);
        [, , $client] = $this->makeClient(['https://app.example.com'], ['app_opened']);
        $headers = $this->clientHeaders($client, 'user-1');

        $this->postJson('/api/v1/client/events', ['name' => 'payment_succeeded'], $headers)->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], [...$headers, 'Origin' => 'https://evil.example'])->assertForbidden();
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], [...$headers, 'Origin' => 'https://app.example.com'])
            ->assertStatus(202)->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
        // A mobile app sends no Origin at all.
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $headers)->assertStatus(202)->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_browsers_get_their_preflight_answered(): void
    {
        config(['cors.paths' => []]);
        $this->call('OPTIONS', '/api/v1/client/events', [], [], [], ['HTTP_ORIGIN' => 'https://app.example.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com')
            ->assertHeader('Access-Control-Allow-Headers', 'Content-Type, X-TE-Key, X-TE-Person, X-TE-Identity, X-TE-Platform');

        $this->postJson('/api/v1/client/events', ['name' => 'x', 'anonymous_id' => 'd'], ['X-TE-Key' => 'pk_nope', 'Origin' => 'https://app.example.com'])
            ->assertUnauthorized()->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');

        // A standalone server's own CORS middleware (api/*) answers instead; any
        // allow-origin header lets the browser through, and the key still decides.
        config(['cors.paths' => ['api/*']]);
        $this->call('OPTIONS', '/api/v1/client/events', [], [], [], ['HTTP_ORIGIN' => 'https://app.example.com', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST'])
            ->assertNoContent()->assertHeader('Access-Control-Allow-Origin');
    }

    public function test_push_tokens_are_kept_per_person_and_removed_on_log_out(): void
    {
        [, , $client] = $this->makeClient();
        $headers = $this->clientHeaders($client, 'user-42');

        $this->postJson('/api/v1/client/push-tokens', ['token' => 'ExponentPushToken[a]'], $this->clientHeaders($client))->assertUnauthorized();
        $this->postJson('/api/v1/client/push-tokens', ['token' => 'not-a-token'], $headers)->assertUnprocessable();

        foreach (range(1, 12) as $n) {
            $this->postJson('/api/v1/client/push-tokens', ['token' => "ExponentPushToken[{$n}]"], $headers)->assertOk();
        }
        $this->postJson('/api/v1/client/push-tokens', ['token' => 'ExponentPushToken[5]'], $headers)->assertOk();

        $tokens = Person::query()->where('external_id', 'user-42')->sole()->properties()['expo_push_tokens'];
        $this->assertCount(10, $tokens);
        $this->assertSame('ExponentPushToken[5]', end($tokens), 'a re-registered token moves to the newest slot');
        $this->assertNotContains('ExponentPushToken[1]', $tokens);

        $this->deleteJson('/api/v1/client/push-tokens', ['token' => 'ExponentPushToken[5]'], $headers)->assertOk()->assertJson(['tokens' => 9]);
    }

    public function test_identified_requests_record_last_seen(): void
    {
        [, , $client] = $this->makeClient();
        $this->freezeSecond();

        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], [...$this->clientHeaders($client, 'user-42'), 'X-TE-Platform' => 'ios'])->assertStatus(202);
        $properties = Person::query()->where('external_id', 'user-42')->sole()->properties();
        $this->assertSame(now()->toIso8601ZuluString(), $properties['last_seen_at']);
        $this->assertSame('ios', $properties['last_seen_platform']);

        // Within a few minutes nothing is rewritten; later it is.
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], [...$this->clientHeaders($client, 'user-42'), 'X-TE-Platform' => 'ios']);
        $this->assertSame($properties['last_seen_at'], Person::query()->where('external_id', 'user-42')->sole()->properties()['last_seen_at']);
        $this->travel(10)->minutes();
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened'], $this->clientHeaders($client, 'user-42'));
        $this->assertSame(now()->toIso8601ZuluString(), Person::query()->where('external_id', 'user-42')->sole()->properties()['last_seen_at']);
    }

    public function test_the_connect_page_issues_and_revokes_keys(): void
    {
        [$workspace, $serverKey] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $serverKey);

        $this->get('/app/connect', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Connect/Index')
            ->where('endpoint', url('api/v1'))
            ->has('keys', 1)
            ->missing('keys.0.key_hash'));

        $this->post('/app/connect/keys', ['kind' => 'client', 'name' => 'App', 'allowed_origins' => 'app.example.com'], $headers)->assertSessionHasErrors('allowed_origins');
        $this->post('/app/connect/keys', ['kind' => 'client', 'name' => 'App', 'allowed_origins' => "https://app.example.com/\nhttps://www.example.com", 'allowed_events' => 'app_opened, screen_viewed'], $headers)
            ->assertSessionHas('issued', fn (array $issued) => str_starts_with($issued['key'], 'pk_') && str_starts_with($issued['secret'], 'tes_'));
        $client = ApiKey::query()->where('kind', 'client')->sole();
        $this->assertSame(['https://app.example.com', 'https://www.example.com'], $client->allowed_origins);
        $this->assertSame(['app_opened', 'screen_viewed'], $client->allowed_events);

        // The embedded host's helper signs with that key.
        config(['trigger-engage-server.workspace_id' => $workspace->public_id]);
        $this->assertSame(hash_hmac('sha256', 'user-1', session('issued')['secret']), ClientIdentity::token('user-1'));

        $this->post('/app/connect/keys', ['kind' => 'server', 'name' => 'Backend'], $headers)->assertSessionHas('issued', fn (array $issued) => str_starts_with($issued['key'], 'te_'));
        $issuedServerKey = session('issued')['key'];
        $this->getJson('/api/v1/people', $this->authHeaders($workspace, $issuedServerKey))->assertOk();

        $this->post("/app/connect/keys/{$client->id}/revoke", [], $headers);
        $this->postJson('/api/v1/client/events', ['name' => 'app_opened', 'anonymous_id' => 'd'], ['X-TE-Key' => $client->public_key])->assertUnauthorized();
        $this->assertNull(ClientIdentity::token('user-1'));
    }

    public function test_the_last_server_key_cannot_be_revoked_from_a_standalone_dashboard(): void
    {
        [$workspace, $serverKey] = $this->makeWorkspace();
        $key = ApiKey::query()->where('kind', 'server')->sole();

        $this->post("/app/connect/keys/{$key->id}/revoke", [], $this->authHeaders($workspace, $serverKey))->assertSessionHasErrors('key');
        $this->assertNull($key->refresh()->revoked_at);
    }
}
