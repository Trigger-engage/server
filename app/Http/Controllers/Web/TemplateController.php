<?php

namespace App\Http\Controllers\Web;

use App\Engine\EmailLayoutRenderer;
use App\Engine\TemplateRenderer;
use App\Http\Controllers\Controller;
use App\Models\Template;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TemplateController extends Controller
{
    public function __construct(
        protected EmailLayoutRenderer $layouts,
        protected TemplateRenderer $renderer,
    ) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $this->validateLiquid($data);
        $template = $request->attributes->get('workspace')->templates()->create($this->normalized($data));

        return redirect()->route('engage.templates.edit', $template)
            ->with('success', 'Template created. Customize the design and preview it before using it.');
    }

    public function edit(Request $request, Template $template): Response
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $template);
        $template->settings = $this->layouts->normalizeSettings($template->settings);

        return Inertia::render('Templates/Edit', [
            'workspace' => $workspace->only('id', 'public_id', 'name', 'timezone'),
            'template' => $template->only([
                'id', 'channel', 'name', 'subject', 'body', 'layout', 'preheader',
                'settings', 'from_name', 'from_address', 'updated_at',
            ]),
            'preview' => $this->renderPreview($template),
            'defaultSettings' => $this->layouts->defaultSettings(),
        ]);
    }

    public function update(Request $request, Template $template): RedirectResponse
    {
        $workspace = $request->attributes->get('workspace');
        $this->ensureWorkspaceOwns($workspace->id, $template);
        $data = $this->validated($request);
        $data['channel'] = $template->channel;
        $this->validateLiquid($data);
        $template->update($this->normalized($data));

        return back()->with('success', 'Template saved. Future sends will use this version.');
    }

    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), $this->rules());

        if ($validator->fails()) {
            return response()->json([
                'message' => 'The template preview could not be rendered.',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $data = $validator->validated();

        try {
            $template = new Template($this->normalized($data));
            $preview = $this->renderPreview($template);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'body' => 'The Liquid template could not be rendered: '.$exception->getMessage(),
            ]);
        }

        return response()->json($preview);
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request): array
    {
        return $request->validate($this->rules());
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['email', 'sms', 'push'])],
            'name' => ['required', 'string', 'max:150'],
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:200000'],
            'layout' => ['nullable', Rule::in(['mytherapist', 'plain'])],
            'preheader' => ['nullable', 'string', 'max:255'],
            'from_name' => ['nullable', 'string', 'max:150'],
            'from_address' => ['nullable', 'email', 'max:255'],
            'settings' => ['nullable', 'array'],
            'settings.brand_name' => ['nullable', 'string', 'max:80'],
            'settings.brand_suffix' => ['nullable', 'string', 'max:20'],
            'settings.tagline' => ['nullable', 'string', 'max:160'],
            'settings.logo_url' => ['nullable', 'url', 'max:2048'],
            'settings.website_url' => ['nullable', 'url', 'max:2048'],
            'settings.support_email' => ['nullable', 'email', 'max:255'],
            'settings.background_color' => $this->hexColorRule(),
            'settings.card_color' => $this->hexColorRule(),
            'settings.heading_color' => $this->hexColorRule(),
            'settings.text_color' => $this->hexColorRule(),
            'settings.muted_color' => $this->hexColorRule(),
            'settings.footer_color' => $this->hexColorRule(),
            'settings.footer_divider_color' => $this->hexColorRule(),
            'settings.accent_color' => $this->hexColorRule(),
            'settings.show_app_badges' => ['nullable', 'boolean'],
            'settings.badge_caption' => ['nullable', 'string', 'max:160'],
            'settings.app_store_url' => ['nullable', 'url', 'max:2048'],
            'settings.app_store_badge_url' => ['nullable', 'url', 'max:2048'],
            'settings.play_store_url' => ['nullable', 'url', 'max:2048'],
            'settings.play_store_badge_url' => ['nullable', 'url', 'max:2048'],
            'settings.show_social_links' => ['nullable', 'boolean'],
            'settings.instagram_url' => ['nullable', 'url', 'max:2048'],
            'settings.youtube_url' => ['nullable', 'url', 'max:2048'],
            'settings.facebook_url' => ['nullable', 'url', 'max:2048'],
            'settings.tiktok_url' => ['nullable', 'url', 'max:2048'],
            'settings.linkedin_url' => ['nullable', 'url', 'max:2048'],
            'settings.x_url' => ['nullable', 'url', 'max:2048'],
            'settings.footer_note' => ['nullable', 'string', 'max:1000'],
            'settings.crisis_text' => ['nullable', 'string', 'max:1000'],
            'settings.company_line' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array<string, mixed> */
    protected function normalized(array $data): array
    {
        if ($data['channel'] !== 'email') {
            return [...$data, 'layout' => 'plain', 'preheader' => null, 'settings' => null];
        }

        return [
            ...$data,
            'layout' => $data['layout'] ?? config('email_templates.default_layout', 'mytherapist'),
            'settings' => $this->layouts->normalizeSettings($data['settings'] ?? []),
        ];
    }

    /** @return array{html: string, subject: string, warnings: array<int, string>} */
    protected function renderPreview(Template $template): array
    {
        $context = config('email_templates.preview_context', []);
        $this->renderer->reset();
        $subject = $this->renderer->render($template->subject ?? '', $context);
        $body = $this->renderer->render($template->body, $context);
        $preheader = $this->renderer->render($template->preheader ?? '', $context);
        $html = $template->channel === 'email'
            ? $this->layouts->render($template, $body, $preheader, 'https://example.com/unsubscribe-preview')
            : '<pre style="white-space:pre-wrap;font:16px/1.5 sans-serif">'.e($body).'</pre>';

        return [
            'html' => $html,
            'subject' => $subject,
            'warnings' => $this->renderer->missingVariables(),
        ];
    }

    protected function validateLiquid(array $data): void
    {
        try {
            $template = new Template($this->normalized($data));
            $this->renderPreview($template);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'body' => 'The Liquid template could not be rendered: '.$exception->getMessage(),
            ]);
        }
    }

    protected function ensureWorkspaceOwns(int $workspaceId, Template $template): void
    {
        abort_unless($template->workspace_id === $workspaceId, 404);
    }

    /** @return array<int, string> */
    protected function hexColorRule(): array
    {
        return ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];
    }
}
