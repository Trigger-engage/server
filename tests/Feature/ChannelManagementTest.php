<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\Channels\ChannelConnectionTester;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Channel;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Workspace;

class ChannelManagementTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected function smtpChannel(Workspace $workspace, array $attributes = []): Channel
    {
        return $workspace->channels()->create([
            'type' => 'email', 'driver' => 'smtp', 'name' => 'ZeptoMail', 'is_default' => true,
            'credentials' => ['host' => 'smtp.zeptomail.com', 'port' => 587, 'username' => 'emailapikey', 'password' => 'stored-secret', 'encryption' => 'tls'],
            ...$attributes,
        ]);
    }

    public function test_the_page_never_sends_secrets_and_shows_how_to_wire_delivery(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->smtpChannel($workspace);
        $workspace->channels()->create([
            'type' => 'sms', 'driver' => 'termii', 'name' => 'Termii', 'is_default' => false,
            'credentials' => ['base_url' => 'https://v3.api.termii.com', 'api_key' => 'k', 'secret_key' => 's', 'sender_id' => 'MyTherapist', 'route' => 'dnd'],
        ]);

        $response = $this->get('/app/channels', $this->authHeaders($workspace, $key));
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Channels/Index')
            ->where('channels.0.settings.host', 'smtp.zeptomail.com')
            ->missing('channels.0.settings.password')
            ->where('channels.0.secrets', ['password'])
            ->where('channels.0.webhook_url', null)
            ->where('channels.1.secrets', ['api_key', 'secret_key'])
            ->where('channels.1.webhook_url', fn ($url) => str_ends_with($url, '/api/v1/webhooks/termii/'.Channel::query()->where('driver', 'termii')->value('id'))));
        $this->assertStringNotContainsString('stored-secret', $response->getContent());
    }

    public function test_editing_keeps_a_secret_left_blank_and_can_move_a_log_channel_onto_smtp(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $channel = $this->smtpChannel($workspace);

        $this->put("/app/channels/{$channel->id}", ['name' => 'Transactional email', 'driver' => 'smtp', 'host' => 'smtp.example.com', 'port' => 465, 'username' => 'u', 'password' => '', 'encryption' => 'ssl'], $headers)
            ->assertSessionHasNoErrors();
        $channel->refresh();
        $this->assertSame('Transactional email', $channel->name);
        $this->assertSame('smtp.example.com', $channel->credentials['host']);
        $this->assertSame('stored-secret', $channel->credentials['password']);

        // The seeded "log" channel becomes a real one in place: same id, so
        // every journey that names it now sends through SMTP.
        $log = $workspace->channels()->create(['type' => 'email', 'driver' => 'log', 'name' => 'Email (log)']);
        $this->put("/app/channels/{$log->id}", ['name' => 'Email', 'driver' => 'smtp', 'host' => 'smtp.zeptomail.com', 'port' => 587, 'password' => 'new-secret'], $headers)
            ->assertSessionHasNoErrors();
        $this->assertSame(['smtp', 'new-secret'], [$log->refresh()->driver, $log->credentials['password']]);
    }

    public function test_a_secret_never_crosses_into_a_different_driver(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $channel = $workspace->channels()->create(['type' => 'push', 'driver' => 'onesignal', 'name' => 'Push', 'credentials' => ['app_id' => 'a', 'api_key' => 'onesignal-key', 'webhook_token' => str_repeat('t', 24)]]);

        $this->put("/app/channels/{$channel->id}", ['name' => 'Push', 'driver' => 'expo', 'priority' => 'high'], $this->authHeaders($workspace, $key))->assertSessionHasNoErrors();

        $this->assertSame('expo', $channel->refresh()->driver);
        $this->assertNull($channel->credentials['access_token']);
        $this->assertArrayNotHasKey('api_key', $channel->credentials);
    }

    public function test_the_driver_has_to_fit_the_channel_type(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $sms = $workspace->channels()->create(['type' => 'sms', 'driver' => 'log', 'name' => 'SMS']);

        $this->put("/app/channels/{$sms->id}", ['name' => 'SMS', 'driver' => 'smtp', 'host' => 'h', 'port' => 25], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('driver');
        $this->post('/app/channels', ['type' => 'push', 'name' => 'x', 'driver' => 'termii'], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors('driver');
        $this->assertSame('log', $sms->refresh()->driver);
    }

    public function test_a_channel_in_use_cannot_be_deleted(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $named = $this->smtpChannel($workspace, ['name' => 'Named', 'is_default' => false]);
        $default = $this->smtpChannel($workspace, ['name' => 'Default']);
        $spare = $this->smtpChannel($workspace, ['name' => 'Spare', 'is_default' => false]);
        $template = $this->makeEmailTemplate($workspace);

        // One journey names a channel; another leaves it blank, so uses the default.
        $this->makeAutomation($workspace, 'signed_up', $this->linearEmailGraph($template, $named));
        $graph = $this->linearEmailGraph($template, $named);
        unset($graph['nodes'][1]['config']['channel_id']);
        $this->makeAutomation($workspace, 'booked', $graph);

        $this->delete("/app/channels/{$named->id}", [], $headers)
            ->assertSessionHasErrors(['channel' => '“Named” is still used by automations: test_automation_signed_up. Switch them to another channel first.']);
        $this->delete("/app/channels/{$default->id}", [], $headers)->assertSessionHasErrors('channel');

        $this->get('/app/channels', $headers)->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.name', 'Default')
            ->where('channels.0.usage.automations', ['test_automation_booked']));

        $this->delete("/app/channels/{$spare->id}", [], $headers)->assertSessionHasNoErrors();
        $this->assertModelMissing($spare);
    }

    public function test_an_in_flight_run_on_an_old_version_still_counts_as_use(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $old = $this->smtpChannel($workspace, ['name' => 'Old', 'is_default' => false]);
        $new = $this->smtpChannel($workspace, ['name' => 'New']);
        $template = $this->makeEmailTemplate($workspace);
        $automation = $this->makeAutomation($workspace, 'signed_up', $this->linearEmailGraph($template, $old));
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada']);
        AutomationRun::create(['workspace_id' => $workspace->id, 'automation_id' => $automation->id, 'automation_version_id' => $automation->active_version_id, 'person_id' => $person->id, 'status' => AutomationRun::STATUS_WAITING]);

        // Republished onto the new channel: the waiting run is still on version 1.
        $automation->publish($this->linearEmailGraph($template, $new));

        $this->delete("/app/channels/{$old->id}", [], $this->authHeaders($workspace, $key))->assertSessionHasErrors('channel');
    }

    public function test_broadcasts_block_deletion_and_deleting_the_default_promotes_another(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $default = $this->smtpChannel($workspace, ['name' => 'Default']);
        $other = $this->smtpChannel($workspace, ['name' => 'Other', 'is_default' => false]);
        $broadcast = $workspace->broadcasts()->create([
            'name' => 'July', 'channel' => 'email', 'segment_id' => $workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $this->makeEmailTemplate($workspace)->id, 'channel_id' => $other->id, 'status' => 'completed',
        ]);

        $this->delete("/app/channels/{$other->id}", [], $headers)->assertSessionHasErrors(['channel' => '“Other” is still used by 1 broadcast. Switch them to another channel first.']);

        $broadcast->delete();
        $this->delete("/app/channels/{$default->id}", [], $headers)->assertSessionHas('success', '“Default” deleted. “Other” is now the default email channel.');
        $this->assertTrue($other->refresh()->is_default);
    }

    public function test_make_default_moves_the_default_within_the_type(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $a = $this->smtpChannel($workspace, ['name' => 'A']);
        $b = $this->smtpChannel($workspace, ['name' => 'B', 'is_default' => false]);
        $sms = $workspace->channels()->create(['type' => 'sms', 'driver' => 'log', 'name' => 'SMS', 'is_default' => true]);

        $this->post("/app/channels/{$b->id}/default", [], $this->authHeaders($workspace, $key))->assertSessionHasNoErrors();

        $this->assertFalse($a->refresh()->is_default);
        $this->assertTrue($b->refresh()->is_default);
        $this->assertTrue($sms->refresh()->is_default);
    }

    public function test_testing_uses_stored_secrets(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $channel = $this->smtpChannel($workspace);
        $tester = Mockery::mock(ChannelConnectionTester::class);
        $tester->shouldReceive('test')->twice()
            ->withArgs(fn (string $driver, array $credentials) => $driver === 'smtp' && $credentials['password'] === 'stored-secret')
            ->andReturn(['ok' => true, 'message' => 'Connected.']);
        $this->app->instance(ChannelConnectionTester::class, $tester);

        // From the card: exactly what is saved.
        $this->post("/app/channels/{$channel->id}/test", [], $headers)->assertSessionHas('success', 'ZeptoMail: Connected.');
        // From the edit form: a changed host, the password left blank.
        $this->post('/app/channels/test', ['driver' => 'smtp', 'channel_id' => $channel->id, 'host' => 'smtp.example.com', 'port' => 587, 'password' => ''], $headers)
            ->assertSessionHas('success', 'Connected.');
    }

    public function test_another_workspaces_channel_is_not_found(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $foreign = $this->smtpChannel($other);
        $headers = $this->authHeaders($workspace, $key);

        $this->put("/app/channels/{$foreign->id}", ['name' => 'Mine now', 'driver' => 'log'], $headers)->assertNotFound();
        $this->delete("/app/channels/{$foreign->id}", [], $headers)->assertNotFound();
        $this->post("/app/channels/{$foreign->id}/test", [], $headers)->assertNotFound();
        $this->post('/app/channels/test', ['driver' => 'smtp', 'channel_id' => $foreign->id, 'host' => 'h', 'port' => 1], $headers)->assertNotFound();
        $this->assertSame('ZeptoMail', $foreign->refresh()->name);
    }
}
