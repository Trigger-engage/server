<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Mail\TemplatedMail;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Message;
use TriggerEngage\Server\Models\Person;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;

class WorkspaceBrandTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    protected const LOGO = 'https://example.com/acme-logo.png';

    protected function brand(array $changes = []): array
    {
        return [...config('email_templates.mytherapist'), ...$changes];
    }

    public function test_the_brand_page_saves_only_what_differs_from_the_defaults(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);

        $this->get('/app/brand', $headers)->assertInertia(fn (Assert $page) => $page
            ->component('Brand/Edit')
            ->where('brand.accent_color', '#FED325')
            ->where('customized', false));

        $this->put('/app/brand', ['brand' => $this->brand(['logo_url' => self::LOGO, 'accent_color' => '#ff6600', 'brand_name' => 'acme'])], $headers)
            ->assertSessionHasNoErrors();

        $this->assertSame(['brand_name' => 'acme', 'logo_url' => self::LOGO, 'accent_color' => '#ff6600'], $workspace->refresh()->brand);
        $this->get('/app/brand', $headers)->assertInertia(fn (Assert $page) => $page
            ->where('brand.logo_url', self::LOGO)
            ->where('brand.card_color', '#FFFFFF')
            ->where('customized', true));

        $this->put('/app/brand', ['brand' => $this->brand(['accent_color' => 'orange'])], $headers)->assertSessionHasErrors('brand.accent_color');

        $this->delete('/app/brand', [], $headers);
        $this->assertNull($workspace->refresh()->brand);
    }

    public function test_emails_follow_the_brand_except_where_a_template_changed_it(): void
    {
        Mail::fake();
        [$workspace, $key] = $this->makeWorkspace();
        $workspace->update(['brand' => ['logo_url' => self::LOGO, 'company_line' => 'Acme Ltd · Lagos']]);
        $channel = $this->makeLogEmailChannel($workspace);
        $follows = $this->makeEmailTemplate($workspace, 'Follows');
        $own = $this->makeEmailTemplate($workspace, 'Own logo');
        $own->update(['settings' => ['logo_url' => 'https://example.com/campaign.png']]);

        $this->makeAutomation($workspace, 'a', $this->linearEmailGraph($follows, $channel));
        $this->makeAutomation($workspace, 'b', $this->linearEmailGraph($own, $channel));
        $headers = $this->authHeaders($workspace, $key);
        $this->putJson('/api/v1/people/user-1', ['email' => 'ada@example.com'], $headers);
        $this->postJson('/api/v1/events', ['name' => 'a', 'person_id' => 'user-1'], $headers);
        $this->postJson('/api/v1/events', ['name' => 'b', 'person_id' => 'user-1'], $headers);

        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->renderedSubject === 'Follows'
            && str_contains($mail->renderedBody, self::LOGO) && str_contains($mail->renderedBody, 'Acme Ltd · Lagos'));
        Mail::assertSent(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->renderedSubject === 'Own logo'
            && str_contains($mail->renderedBody, 'https://example.com/campaign.png') && ! str_contains($mail->renderedBody, self::LOGO)
            // It only changed the logo: the rest still comes from the brand.
            && str_contains($mail->renderedBody, 'Acme Ltd · Lagos'));
    }

    public function test_the_template_editor_stores_only_overrides_and_resets_to_the_brand(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $headers = $this->authHeaders($workspace, $key);
        $workspace->update(['brand' => ['logo_url' => self::LOGO]]);
        $template = $this->makeEmailTemplate($workspace);
        $brand = $this->brand(['logo_url' => self::LOGO]);

        $this->get("/app/templates/{$template->id}/edit", $headers)->assertInertia(fn (Assert $page) => $page
            ->where('defaultSettings.logo_url', self::LOGO)
            ->where('template.settings.logo_url', self::LOGO));

        // Saving the brand values as they are stores nothing...
        $this->put("/app/templates/{$template->id}", ['channel' => 'email', 'name' => 'Welcome', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'settings' => $brand], $headers)->assertSessionHasNoErrors();
        $this->assertNull($template->refresh()->settings);

        // ...and one change stores one key.
        $this->put("/app/templates/{$template->id}", ['channel' => 'email', 'name' => 'Welcome', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'settings' => [...$brand, 'footer_note' => 'Sent because you asked.', 'reply_to' => 'care@example.com']], $headers);
        $this->assertSame(['footer_note' => 'Sent because you asked.', 'reply_to' => 'care@example.com'], $template->refresh()->settings);
    }

    public function test_the_preview_fills_unchanged_settings_from_the_brand(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $workspace->update(['brand' => ['logo_url' => self::LOGO]]);

        $this->postJson('/app/templates/preview', [
            'channel' => 'email', 'name' => 'x', 'subject' => 'Hi', 'body' => '<p>Hi</p>', 'layout' => 'mytherapist',
            'settings' => ['footer_note' => 'Preview footer'],
        ], $this->authHeaders($workspace, $key))
            ->assertOk()
            ->assertJsonPath('html', fn (string $html) => str_contains($html, self::LOGO) && str_contains($html, 'Preview footer'));
    }

    public function test_broadcasts_copy_only_the_templates_overrides(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $workspace->update(['brand' => ['logo_url' => self::LOGO]]);
        $template = $this->makeEmailTemplate($workspace);
        $template->update(['settings' => ['footer_note' => 'Template footer', 'reply_to' => 'care@example.com']]);

        $this->post('/app/broadcasts', [
            'name' => 'July', 'channel' => 'email', 'segment_id' => $workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'template_id' => $template->id, 'channel_id' => $this->makeLogEmailChannel($workspace)->id,
        ], $this->authHeaders($workspace, $key));

        $broadcast = Broadcast::sole();
        $this->assertSame(['footer_note' => 'Template footer', 'reply_to' => 'care@example.com'], $broadcast->settings);
        $this->get("/app/broadcasts/{$broadcast->id}/edit", $this->authHeaders($workspace, $key))->assertInertia(fn (Assert $page) => $page
            ->where('broadcast.settings.logo_url', self::LOGO)
            ->where('broadcast.settings.footer_note', 'Template footer')
            ->where('preview.html', fn (string $html) => str_contains($html, self::LOGO)));
    }

    public function test_the_brand_page_lists_templates_that_override_part_of_it(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeEmailTemplate($workspace, 'Plain one');
        $custom = $this->makeEmailTemplate($workspace, 'Custom');
        $custom->update(['settings' => ['logo_url' => 'https://example.com/other.png', 'reply_to' => 'care@example.com']]);

        $this->get('/app/brand', $this->authHeaders($workspace, $key))->assertInertia(fn (Assert $page) => $page
            ->where('templateCount', 2)
            ->has('overriding', 1)
            ->where('overriding.0.id', $custom->id)
            ->where('overriding.0.keys', ['logo_url']));
    }

    public function test_the_migration_slims_stored_settings_without_changing_how_they_render(): void
    {
        [$workspace] = $this->makeWorkspace();
        $defaults = config('email_templates.mytherapist');
        // How the old editor stored a template: every key, two of them changed.
        $template = $this->makeEmailTemplate($workspace);
        DB::table('templates')->where('id', $template->id)->update(['settings' => json_encode([
            ...$defaults, 'accent_color' => '#fed325', 'footer_note' => 'Custom footer', 'reply_to' => 'care@example.com',
        ])]);
        $renderer = app(EmailLayoutRenderer::class);
        $before = $renderer->normalizeSettings($template->refresh()->settings, $workspace);

        $migration = require base_path('database/migrations/trigger-engage/2026_11_06_000018_add_brand_to_workspaces.php');
        $migration->down();
        $migration->up();

        // The lower-case accent equals the default colour, so it goes too.
        $this->assertSame(['footer_note' => 'Custom footer', 'reply_to' => 'care@example.com'], Template::find($template->id)->settings);
        $this->assertSame($before['footer_note'], $renderer->normalizeSettings(Template::find($template->id)->settings, $workspace->refresh())['footer_note']);
        $this->assertEquals(
            array_diff_key($before, ['accent_color' => true]),
            array_diff_key($renderer->normalizeSettings(Template::find($template->id)->settings, $workspace), ['accent_color' => true]),
        );
    }

    public function test_the_unsubscribe_and_preference_pages_wear_the_workspace_brand(): void
    {
        [$workspace] = $this->makeWorkspace();
        $workspace->update(['brand' => ['logo_url' => self::LOGO, 'support_email' => 'help@acme.example']]);
        $person = Person::create(['workspace_id' => $workspace->id, 'external_id' => 'ada', 'email' => 'ada@example.com']);
        $message = Message::create([
            'workspace_id' => $workspace->id, 'person_id' => $person->id, 'template_id' => $this->makeEmailTemplate($workspace)->id,
            'channel' => 'email', 'to_address' => 'ada@example.com', 'status' => 'sent',
        ]);

        $this->get(URL::temporarySignedRoute('unsubscribe.show', now()->addDay(), ['message' => $message->id]))
            ->assertOk()->assertSee(self::LOGO)->assertSee('help@acme.example');
        $this->get(URL::temporarySignedRoute('preferences.show', now()->addDay(), ['person' => $person->id]))
            ->assertOk()->assertSee(self::LOGO)->assertSee('help@acme.example');
    }
}
