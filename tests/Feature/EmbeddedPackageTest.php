<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Contracts\WorkspaceResolver;
use TriggerEngage\Server\Http\Middleware\AuthorizeDashboard;
use TriggerEngage\Server\Models\AutomationRun;
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

    /**
     * A host may call Date::use(CarbonImmutable::class) — Mytherapist.ng does — which
     * makes now() return a CarbonImmutable. Every relative delay is computed from
     * now(), so a return type of Illuminate\Support\Carbon throws a TypeError there
     * and every automation stalls on its first delay node, silently: the run stays
     * "running" on the trigger and nothing is ever sent.
     */
    public function test_relative_delays_survive_a_host_that_uses_immutable_dates(): void
    {
        Date::use(CarbonImmutable::class);

        try {
            $this->travelTo(CarbonImmutable::parse('2026-08-02 15:00:00', 'UTC'));

            [$workspace, $key] = $this->makeWorkspace();
            $workspace->forceFill(['timezone' => 'UTC'])->save();

            $this->makeAutomation($workspace, 'customer_sign_up', [
                'nodes' => [
                    ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                    ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 30]],
                    ['id' => 'done', 'type' => 'exit', 'config' => []],
                ],
                'edges' => [
                    ['from' => 'trigger', 'to' => 'wait'],
                    ['from' => 'wait', 'to' => 'done'],
                ],
            ]);

            $headers = $this->authHeaders($workspace, $key);
            $this->postJson('/api/v1/events', [
                'name' => 'customer_sign_up',
                'person_id' => 'user-42',
            ], $headers)->assertAccepted();

            $run = AutomationRun::query()->latest('id')->firstOrFail();

            $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
            $this->assertSame('wait', $run->current_node_id);
            $this->assertNotNull($run->wake_at);
            $this->assertSame('2026-08-02 15:30', $run->wake_at->format('Y-m-d H:i'));
        } finally {
            Date::use(Carbon::class);
        }
    }
}
