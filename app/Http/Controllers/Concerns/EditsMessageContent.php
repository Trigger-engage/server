<?php

namespace TriggerEngage\Server\Http\Controllers\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Models\Template;

/**
 * Shared message authoring: validation rules, normalization, and exact preview
 * rendering used by both the template editor and the broadcast composer.
 *
 * Consumers must expose an EmailLayoutRenderer as $this->layouts and a
 * TemplateRenderer as $this->renderer.
 */
trait EditsMessageContent
{
    /** @return array<string, mixed> */
    protected function contentRules(): array
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
            // Delivery settings, kept apart from the layout keys above: where a
            // push tap lands (Liquid allowed, so no url rule), what rides along
            // with it, and who a reply to an email reaches.
            'settings.url' => ['nullable', 'string', 'max:2048'],
            'settings.data' => ['nullable', 'array'],
            'settings.reply_to' => ['nullable', 'email', 'max:255'],
            'settings.reply_to_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /** Settings keys that describe delivery rather than the email layout. */
    protected const DELIVERY_SETTINGS = [
        'email' => ['reply_to', 'reply_to_name'],
        'push' => ['url', 'data'],
        'sms' => [],
    ];

    /**
     * The delivery settings a channel understands, blanks dropped, so a push
     * keeps its deep link and an email its reply-to through every save.
     *
     * @param  array<string, mixed>|null  $settings
     * @return array<string, mixed>
     */
    protected function deliverySettings(?array $settings, string $channel): array
    {
        $kept = [];

        foreach (self::DELIVERY_SETTINGS[$channel] ?? [] as $key) {
            $value = $settings[$key] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            if (blank($value)) {
                continue;
            }

            $kept[$key] = $value;
        }

        return $kept;
    }

    /**
     * What the editor is handed: the full layout set for an email (so every
     * brand control has a value) plus whatever delivery settings are stored.
     *
     * @return array<string, mixed>
     */
    protected function editorSettings(Template $template): array
    {
        $delivery = $this->deliverySettings($template->settings, $template->channel);

        return $template->channel === 'email'
            ? array_merge($this->layouts->normalizeSettings($template->settings), $delivery)
            : $delivery;
    }

    /**
     * Coerce raw authoring input into the fields a Template model expects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizedContent(array $data): array
    {
        $delivery = $this->deliverySettings($data['settings'] ?? null, $data['channel']);

        if ($data['channel'] !== 'email') {
            return [...$data, 'layout' => 'plain', 'preheader' => null, 'settings' => $delivery ?: null];
        }

        return [
            ...$data,
            'layout' => $data['layout'] ?? config('email_templates.default_layout', 'mytherapist'),
            'settings' => array_merge($this->layouts->normalizeSettings($data['settings'] ?? []), $delivery),
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

    /** @param  array<string, mixed>  $data */
    protected function validateLiquid(array $data): void
    {
        try {
            $template = new Template($this->normalizedContent($data));
            $this->renderPreview($template);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'body' => 'The Liquid template could not be rendered: '.$exception->getMessage(),
            ]);
        }
    }

    /** @return array<int, string> */
    protected function hexColorRule(): array
    {
        return ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];
    }
}
