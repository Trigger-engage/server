<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;

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

    public function test_smtp_test_requires_a_host(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $this->post('/app/channels/test', [
            'driver' => 'smtp',
        ], $this->authHeaders($workspace, $key))
            ->assertSessionHasErrors(['host', 'port']);
    }
}
