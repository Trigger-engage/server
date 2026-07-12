<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Template;

class EmailTemplateCustomizationTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_new_email_template_uses_the_current_mytherapist_design_by_default(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $response = $this->post('/app/templates', [
            'channel' => 'email',
            'name' => 'Welcome',
            'subject' => 'Welcome {{ person.first_name }}',
            'body' => '<h1>Hello {{ person.first_name }}</h1>',
        ], $this->authHeaders($workspace, $key));

        $template = Template::sole();
        $response->assertRedirect('/app/templates/'.$template->id.'/edit');
        $this->assertSame('mytherapist', $template->layout);
        $this->assertSame('#FFFAF4', $template->settings['background_color']);
        $this->assertSame('#FED325', $template->settings['accent_color']);
        $this->assertSame('https://mytherapist.ng/assets/images/email/logo.png', $template->settings['logo_url']);
        $this->assertTrue($template->settings['show_app_badges']);
    }

    public function test_preview_uses_sample_liquid_context_and_custom_brand_settings(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $response = $this->postJson('/app/templates/preview', [
            'channel' => 'email',
            'name' => 'Branded welcome',
            'subject' => 'Hello {{ person.first_name }}',
            'preheader' => '{{ event.plan }} membership update',
            'body' => '<h1>Welcome {{ person.first_name }}</h1><p>Your plan is {{ event.plan }}.</p>',
            'layout' => 'mytherapist',
            'settings' => [
                'brand_name' => 'Care',
                'brand_suffix' => '.co',
                'logo_url' => 'https://cdn.example.com/logo.png',
                'accent_color' => '#12AB34',
            ],
        ], $this->authHeaders($workspace, $key));

        $response
            ->assertOk()
            ->assertJsonPath('subject', 'Hello Ada')
            ->assertJsonPath('warnings', [])
            ->assertJson(fn ($json) => $json
                ->whereType('html', 'string')
                ->etc()
            );

        $html = $response->json('html');
        $this->assertStringContainsString('Welcome Ada', $html);
        $this->assertStringContainsString('wellness membership update', $html);
        $this->assertStringContainsString('https://cdn.example.com/logo.png', $html);
        $this->assertStringContainsString('#12AB34', $html);
        $this->assertMatchesRegularExpression('/<h1[^>]+style="[^"]*color:\s*#1B1D3E/i', $html);
    }

    public function test_template_editor_is_workspace_scoped_and_returns_exact_preview(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$otherWorkspace] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $otherTemplate = $this->makeEmailTemplate($otherWorkspace);

        $this->get('/app/templates/'.$template->id.'/edit', $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Templates/Edit')
                ->where('template.id', $template->id)
                ->where('template.layout', 'mytherapist')
                ->where('defaultSettings.tagline', 'Licensed therapy, made for Africa.')
                ->where('preview.subject', 'Welcome, Ada!')
            );

        $this->get('/app/templates/'.$otherTemplate->id.'/edit', $this->authHeaders($workspace, $key))
            ->assertNotFound();
        $this->put('/app/templates/'.$otherTemplate->id, [
            'channel' => 'email',
            'name' => 'Stolen',
            'body' => '<p>No</p>',
        ], $this->authHeaders($workspace, $key))->assertNotFound();
    }

    public function test_updating_a_template_cannot_change_its_channel(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);

        $this->put('/app/templates/'.$template->id, [
            'channel' => 'sms',
            'name' => 'Updated email',
            'subject' => 'Still email',
            'body' => '<p>Updated</p>',
            'layout' => 'plain',
        ], $this->authHeaders($workspace, $key))->assertRedirect();

        $this->assertSame('email', $template->refresh()->channel);
        $this->assertSame('plain', $template->layout);
    }

    public function test_invalid_design_color_is_rejected(): void
    {
        [$workspace, $key] = $this->makeWorkspace();

        $response = $this->postJson('/app/templates/preview', [
            'channel' => 'email',
            'name' => 'Invalid',
            'body' => '<p>Hello</p>',
            'settings' => ['accent_color' => 'red; background:url(javascript:alert(1))'],
        ], $this->authHeaders($workspace, $key));

        $this->assertSame(422, $response->getStatusCode(), $response->getContent());
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayHasKey('settings.accent_color', $payload['errors']);
    }

    public function test_email_send_uses_branded_layout_and_places_unsubscribe_inside_document(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $template->update([
            'preheader' => 'A personal welcome for {{ person.first_name }}',
            'settings' => ['accent_color' => '#22C55E'],
        ]);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->makeAutomation($workspace, 'customer_sign_up', $this->linearEmailGraph($template, $channel));
        Person::create([
            'workspace_id' => $workspace->id,
            'external_id' => 'user-42',
            'email' => 'ada@example.com',
            'attributes' => ['first_name' => 'Ada'],
        ]);

        $this->postJson('/api/v1/events', [
            'name' => 'customer_sign_up',
            'person_id' => 'user-42',
            'data' => ['plan' => 'wellness'],
        ], $this->authHeaders($workspace, $key))->assertAccepted();

        Mail::assertSent(TemplatedMail::class, function (TemplatedMail $mail): bool {
            $body = $mail->renderedBody;

            return str_starts_with(strtolower($body), '<!doctype html>')
                && str_contains($body, 'A personal welcome for Ada')
                && str_contains($body, 'https://mytherapist.ng/assets/images/email/logo.png')
                && str_contains($body, 'https://mytherapist.ng/assets/images/email/app-store-badge.png')
                && str_contains($body, '#22C55E')
                && str_contains($body, 'Hi Ada, you are on wellness.')
                && str_contains($body, '/unsubscribe/')
                && strpos($body, '/unsubscribe/') < strpos($body, '</body>');
        });

        $this->assertSame(AutomationRun::STATUS_COMPLETED, AutomationRun::sole()->status);
        $this->assertStringContainsString('<!doctype html>', strtolower(Message::sole()->body));
    }

    public function test_plain_layout_remains_available_for_raw_html_email(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $template = $this->makeEmailTemplate($workspace);
        $template->update(['layout' => 'plain', 'body' => '<p>Plain hello</p>']);
        $channel = $this->makeLogEmailChannel($workspace);
        $this->makeAutomation($workspace, 'plain_event', $this->linearEmailGraph($template, $channel));
        Person::create(['workspace_id' => $workspace->id, 'external_id' => 'user-42', 'email' => 'ada@example.com']);

        $this->postJson('/api/v1/events', [
            'name' => 'plain_event',
            'person_id' => 'user-42',
        ], $this->authHeaders($workspace, $key));

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => str_starts_with($mail->renderedBody, '<p>Plain hello</p>')
            && ! str_contains($mail->renderedBody, 'app-store-badge.png')
            && str_contains($mail->renderedBody, '/unsubscribe/'));
    }
}
