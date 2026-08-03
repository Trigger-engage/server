<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Mailer;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\Channels\EmailChannel;

class ChannelConnectionTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_log_driver_reports_a_healthy_connection_without_saving(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->post('/app/channels/test', [
            'driver' => 'log',
        ], $this->authHeaders($workspace, $key))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(0, $workspace->channels()->count());
    }

    public function test_unreachable_smtp_host_reports_a_failure(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        // Port 1 on localhost refuses immediately, so the probe fails fast and offline.
        $this->post('/app/channels/test', [
            'driver' => 'smtp',
            'host' => '127.0.0.1',
            'port' => 1,
            'username' => 'user',
            'password' => 'secret',
            'encryption' => 'tls',
        ], $this->authHeaders($workspace, $key))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(0, $workspace->channels()->count());
    }

    /**
     * An smtp channel must resolve through a mailer this package registers by name.
     * Mail::build() is Laravel 11+, and composer.json declares support from ^10.48 —
     * on a Laravel 10 host every smtp send failed with "Method
     * Illuminate\\Mail\\Mailer::build does not exist", and the CI matrix here (which
     * runs a newer Laravel, where build() exists) could never see it. Asserting the
     * named registration is what makes that regression visible on any version.
     */
    public function test_smtp_channels_resolve_through_a_registered_named_mailer(): void
    {
        [$workspace] = $this->makeWorkspace();

        $channel = $workspace->channels()->create([
            'type' => 'email',
            'driver' => 'smtp',
            'name' => 'Workspace SMTP',
            'credentials' => [
                'host' => 'smtp.example.test',
                'port' => '2525',
                'username' => 'postmaster',
                'password' => 'secret',
                'encryption' => 'tls',
            ],
        ]);

        $emailChannel = $this->app->make(EmailChannel::class);
        $resolve = new \ReflectionMethod($emailChannel, 'mailer');
        $resolve->setAccessible(true);

        $this->assertInstanceOf(Mailer::class, $resolve->invoke($emailChannel, $channel));

        $registered = collect(config('mail.mailers'))
            ->filter(fn ($config, $name) => str_starts_with((string) $name, 'trigger-engage-'));

        $this->assertCount(1, $registered, 'The smtp channel did not register a named mailer.');
        $this->assertSame('smtp.example.test', $registered->first()['host']);
        $this->assertSame(2525, $registered->first()['port']);
    }

    public function test_smtp_test_requires_a_host(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->post('/app/channels/test', [
            'driver' => 'smtp',
        ], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors(['host', 'port']);
    }
}
