<?php

namespace TriggerEngage\Server\Http\Controllers\Web;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Http\Controllers\Concerns\EditsMessageContent;
use TriggerEngage\Server\Http\Controllers\Controller;
use TriggerEngage\Server\Models\Template;

/**
 * One brand for every branded email in the workspace. A template or
 * broadcast stores only the keys it changes, so a new logo here reaches every
 * email that didn't pick its own.
 */
class BrandController extends Controller
{
    use EditsMessageContent;

    public function __construct(
        protected EmailLayoutRenderer $layouts,
        protected TemplateRenderer $renderer,
    ) {}

    public function edit(Request $request): Response
    {
        $workspace = $request->attributes->get('workspace');
        $emailTemplates = $workspace->templates()->where('channel', 'email')->where('layout', '!=', 'plain')->get(['id', 'name', 'settings']);

        return Inertia::render('Brand/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'brand' => $this->layouts->brandFor($workspace),
            'defaults' => $this->layouts->defaultSettings(),
            'customized' => $workspace->brand !== null,
            // Templates that override part of the brand keep those parts when
            // it changes; the page lists them so that isn't a surprise.
            'overriding' => $emailTemplates
                ->map(fn (Template $template) => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'keys' => array_keys($this->layouts->overridesFrom($template->settings ?? [], $workspace)),
                ])
                ->filter(fn (array $template) => $template['keys'] !== [])
                ->values(),
            'templateCount' => $emailTemplates->count(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $data = $request->validate(['brand' => ['required', 'array'], ...$this->brandRules('brand.')]);

        // Stored as the difference from the shipped defaults, like templates
        // store their difference from the brand.
        $brand = $this->layouts->overridesFrom($data['brand'], null);
        $workspace->update(['brand' => $brand ?: null]);

        return back()->with('success', 'Brand saved. Every branded email uses it from the next send.');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->attributes->get('workspace')->update(['brand' => null]);

        return back()->with('success', 'Brand reset to the defaults.');
    }
}
