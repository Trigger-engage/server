<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Contracts\WorkspaceResolver;
use TriggerEngage\Server\Http\Middleware\AuthorizeDashboard;
use TriggerEngage\Server\Models\EventOccurrence;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\User;
use TriggerEngage\Server\Models\Workspace;
use TriggerEngage\Server\Services\EmbeddedDispatcher;
use TriggerEngage\Server\Services\Ingest;

class EmbeddedPackageTest extends TestCase
{
    use BuildsWorkspaces, RefreshDatabase;

    public function test_embedded_dispatcher_uses_the_host_database_without_http_credentials(): void
    {
        [$workspace] = $this->makeWorkspace();
        $this->app->instance(WorkspaceResolver::class, new class($workspace) implements WorkspaceResolver
        {
            public function __construct(private $workspace) {}

            public function resolve(): Workspace
            {
                return $this->workspace;
            }
        });

        $dispatcher = new EmbeddedDispatcher($this->app->make(Ingest::class), $this->app->make(WorkspaceResolver::class));
        $dispatcher->identify('person-42', ['email' => 'person@example.com', 'appointments' => 3]);
        $dispatcher->setProperties('person-42', ['plan' => 'care']);
        $dispatcher->event('appointment_booked', ['source' => 'embedded'], 'person-42');

        $person = Person::whereBelongsTo($workspace)->where('external_id', 'person-42')->firstOrFail();
        $this->assertSame('person@example.com', $person->email);
        $this->assertSame(3, $person->attributes['appointments']);
        $this->assertSame('care', $person->attributes['plan']);
        $this->assertDatabaseHas(EventOccurrence::class, [
            'workspace_id' => $workspace->id,
            'person_id' => $person->id,
        ]);

        $segment = Segment::create([
            'workspace_id' => $workspace->id,
            'name' => 'Customers',
            'type' => Segment::TYPE_MANUAL,
        ]);
        $dispatcher->addToSegment($segment->public_id, 'person-42');
        $this->assertTrue($segment->people()->whereKey($person->id)->exists());

        $dispatcher->removeFromSegment($segment->public_id, 'person-42');
        $this->assertFalse($segment->people()->whereKey($person->id)->exists());
    }

    public function test_embedded_dashboard_fails_closed_without_a_host_login_route(): void
    {
        config(['trigger-engage-server.authorization_gate' => 'viewTriggerEngage']);
        $middleware = new AuthorizeDashboard;

        try {
            $middleware->handle(Request::create('/trigger-engage'), fn () => response('ok'));
            $this->fail('A guest should not reach the embedded dashboard.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $user = User::factory()->create();
        Auth::login($user);

        $response = $middleware->handle(Request::create('/trigger-engage'), fn () => response('ok'));
        $this->assertSame('ok', $response->getContent());
    }
}
