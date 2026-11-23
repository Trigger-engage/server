<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsWorkspaces;
use Tests\TestCase;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Models\Broadcast;
use TriggerEngage\Server\Models\Segment;
use TriggerEngage\Server\Models\Template;

class DefaultBroadcastTemplateTest extends TestCase
{
    use BuildsWorkspaces;
    use RefreshDatabase;

    public function test_the_broadcasts_page_gives_each_channel_a_default_template_once(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->makeEmailTemplate($workspace); // An existing template is not made the default.

        $page = fn () => $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))->assertOk();

        $page()->assertInertia(fn (Assert $page) => $page
            ->where('defaultTemplateIds.email', $this->defaultId($workspace->id, 'email'))
            ->where('defaultTemplateIds.sms', $this->defaultId($workspace->id, 'sms'))
            ->where('defaultTemplateIds.push', $this->defaultId($workspace->id, 'push'))
            // Defaults lead the list so they are easy to find.
            ->where('templates.0.is_default', true)
        );
        $page();

        $this->assertSame(1, Template::where('channel', 'email')->where('is_default', true)->count());
        $this->assertSame(4, Template::count(), 'Three starters plus the existing template, and no more on a second visit.');

        $starter = Template::where('channel', 'email')->where('is_default', true)->sole();
        $this->assertSame("News from {$workspace->name}", $starter->subject);
        $this->assertNull($starter->settings, 'The email starter follows the workspace brand.');
        $this->assertStringContainsString(
            'Hi there,',
            app(TemplateRenderer::class)->render($starter->body, ['person' => ['first_name' => null]]),
        );
    }

    public function test_a_broadcast_created_without_a_template_starts_from_the_default(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $chosen = $this->makeEmailTemplate($workspace, 'Our news', '<p>Chosen default</p>');
        $chosen->update(['is_default' => true]);

        $this->post('/app/broadcasts', [
            'name' => 'November update', 'channel' => 'email',
            'segment_id' => $workspace->segments()->where('type', Segment::TYPE_ALL)->value('id'),
            'channel_id' => $this->makeLogEmailChannel($workspace)->id,
        ], $this->authHeaders($workspace, $key))->assertRedirect();

        $broadcast = Broadcast::sole();
        $this->assertSame($chosen->id, $broadcast->template_id);
        $this->assertSame('Our news', $broadcast->subject);
        $this->assertSame('<p>Chosen default</p>', $broadcast->body);
        $this->assertSame(1, Template::count(), 'An existing default is used; no starter is made.');
    }

    public function test_making_a_template_the_default_replaces_the_old_one_on_its_channel_only(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        $this->get('/app/broadcasts', $this->authHeaders($workspace, $key))->assertOk();
        $smsDefault = $this->defaultId($workspace->id, 'sms');
        $template = $this->makeEmailTemplate($workspace);

        $this->post("/app/templates/{$template->id}/default", [], $this->authHeaders($workspace, $key))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame($template->id, $this->defaultId($workspace->id, 'email'));
        $this->assertSame(1, Template::where('channel', 'email')->where('is_default', true)->count());
        $this->assertSame($smsDefault, $this->defaultId($workspace->id, 'sms'));

        $this->get('/app/templates', $this->authHeaders($workspace, $key))
            ->assertInertia(fn (Assert $page) => $page->where(
                'templates',
                fn ($templates) => collect($templates)->firstWhere('id', $template->id)['is_default'] === true,
            ));
    }

    public function test_another_workspaces_template_cannot_be_made_the_default(): void
    {
        [$workspace, $key] = $this->makeWorkspace();
        [$other] = $this->makeWorkspace();
        $theirs = $this->makeEmailTemplate($other);

        $this->post("/app/templates/{$theirs->id}/default", [], $this->authHeaders($workspace, $key))->assertNotFound();
        $this->assertFalse($theirs->fresh()->is_default);
    }

    protected function defaultId(int $workspaceId, string $channel): ?int
    {
        return Template::where('workspace_id', $workspaceId)->where('channel', $channel)->where('is_default', true)->value('id');
    }
}
