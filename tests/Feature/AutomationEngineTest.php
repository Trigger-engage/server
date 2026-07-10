<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Message;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;

class AutomationEngineTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_full_loop_event_to_delayed_email(): void
    {
        Mail::fake();
        $this->freezeSecond();

        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        $this->makeAutomation($workspace, 'customer_sign_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 60]],
                ['id' => 'send', 'type' => 'send_email', 'config' => [
                    'template_id' => $template->id,
                    'channel_id' => $channel->id,
                ]],
                ['id' => 'done', 'type' => 'exit', 'config' => []],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'send'],
                ['from' => 'send', 'to' => 'done'],
            ],
        ]);

        // Identify, then fire the event (queue is sync in tests).
        $headers = $this->authHeaders($workspace, $key);
        $this->putJson('/api/v1/people/user-42', [
            'attributes' => ['email' => 'ada@example.com', 'first_name' => 'Ada'],
        ], $headers)->assertOk();

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'data' => ['plan' => 'free'],
        ], $headers)->assertStatus(202);

        // Run created and parked on the delay.
        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->status);
        $this->assertTrue($run->wake_at->equalTo(now()->addMinutes(60)));
        Mail::assertNothingSent();

        // Tick before the delay elapses: nothing wakes.
        $this->travel(30)->minutes();
        $this->artisan('engage:tick');
        $this->assertSame(AutomationRun::STATUS_WAITING, $run->refresh()->status);

        // Tick after: email renders from person + event context and run completes.
        $this->travel(31)->minutes();
        $this->artisan('engage:tick');

        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->refresh()->status);

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail) {
            return $mail->renderedSubject === 'Welcome, Ada!'
                && str_contains($mail->renderedBody, 'Hi Ada, you are on free.')
                && $mail->hasTo('ada@example.com');
        });

        $message = Message::sole();
        $this->assertSame('sent', $message->status);
        $this->assertSame('ada@example.com', $message->to_address);
        $this->assertNotNull($message->run_step_id);
    }

    public function test_branch_routes_by_event_payload(): void
    {
        Mail::fake();

        [$workspace, $key] = $this->makeWorkspace();
        $freeTemplate = $this->makeEmailTemplate($workspace, 'Upgrade?', '<p>Go premium</p>');
        $paidTemplate = $this->makeEmailTemplate($workspace, 'Thanks!', '<p>Enjoy premium</p>');
        $channel = $this->makeLogEmailChannel($workspace);

        $this->makeAutomation($workspace, 'customer_sign_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'is_free', 'type' => 'branch', 'config' => [
                    'field' => 'event.plan', 'operator' => 'equals', 'value' => 'free',
                ]],
                ['id' => 'nudge', 'type' => 'send_email', 'config' => [
                    'template_id' => $freeTemplate->id, 'channel_id' => $channel->id,
                ]],
                ['id' => 'thank', 'type' => 'send_email', 'config' => [
                    'template_id' => $paidTemplate->id, 'channel_id' => $channel->id,
                ]],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'is_free'],
                ['from' => 'is_free', 'to' => 'nudge', 'branch' => 'true'],
                ['from' => 'is_free', 'to' => 'thank', 'branch' => 'false'],
            ],
        ]);

        Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'data' => ['plan' => 'premium'],
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->renderedSubject === 'Thanks!');
        Mail::assertNotSent(TemplatedMail::class, fn ($m) => $m->renderedSubject === 'Upgrade?');
    }

    public function test_reentry_policy_once_ever(): void
    {
        Mail::fake();

        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);

        $this->makeAutomation(
            $workspace,
            'customer_sign_up',
            $this->linearEmailGraph($template, $channel),
            Automation::REENTRY_ONCE_EVER
        );

        Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
            'email' => 'ada@example.com',
        ]);

        $headers = $this->authHeaders($workspace, $key);
        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'user-42'], $headers);
        $this->postJson('/api/v1/events', ['name' => 'customer_sign_up', 'person_id' => 'user-42'], $headers);

        $this->assertSame(1, AutomationRun::count());
        Mail::assertSentCount(1);
    }

    public function test_suppressed_person_is_skipped(): void
    {
        Mail::fake();

        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->makeAutomation($workspace, 'customer_sign_up', $this->linearEmailGraph($template, $channel));

        $person = Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
            'email' => 'ada@example.com',
        ]);
        $person->suppressions()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'email',
            'reason' => 'unsubscribe',
        ]);

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        Mail::assertNothingSent();

        $run = AutomationRun::sole();
        $this->assertSame(AutomationRun::STATUS_COMPLETED, $run->status);
        $this->assertSame('skipped', $run->steps()->where('node_id', 'send')->sole()->status);
        $this->assertSame(0, Message::count());
    }

    public function test_person_without_email_is_skipped_not_failed(): void
    {
        Mail::fake();

        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->makeAutomation($workspace, 'customer_sign_up', $this->linearEmailGraph($template, $channel));

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-nobody', // person auto-created with no email
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        Mail::assertNothingSent();
        $this->assertSame(AutomationRun::STATUS_COMPLETED, AutomationRun::sole()->status);
    }

    public function test_published_version_is_pinned_for_inflight_runs(): void
    {
        Mail::fake();

        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace, 'Original', '<p>v1</p>');
        $newTemplate = $this->makeEmailTemplate($workspace, 'Rewritten', '<p>v2</p>');
        $channel = $this->makeLogEmailChannel($workspace);

        $automation = $this->makeAutomation($workspace, 'customer_sign_up', [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'wait', 'type' => 'delay', 'config' => ['minutes' => 10]],
                ['id' => 'send', 'type' => 'send_email', 'config' => [
                    'template_id' => $template->id, 'channel_id' => $channel->id,
                ]],
            ],
            'edges' => [
                ['from' => 'trigger', 'to' => 'wait'],
                ['from' => 'wait', 'to' => 'send'],
            ],
        ]);

        Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
            'email' => 'ada@example.com',
        ]);

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
        ], $this->authHeaders($workspace, $key))->assertStatus(202);

        // Republish with a different template while the run is waiting.
        $automation->publish([
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'send', 'type' => 'send_email', 'config' => [
                    'template_id' => $newTemplate->id, 'channel_id' => $channel->id,
                ]],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'send']],
        ]);

        $this->travel(11)->minutes();
        $this->artisan('engage:tick');

        // The in-flight run still executes the version it started on.
        Mail::assertSent(TemplatedMail::class, fn ($m) => $m->renderedSubject === 'Original');
        Mail::assertNotSent(TemplatedMail::class, fn ($m) => $m->renderedSubject === 'Rewritten');
    }
}
