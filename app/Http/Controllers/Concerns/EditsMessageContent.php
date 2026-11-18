<?php

namespace TriggerEngage\Server\Http\Controllers\Concerns;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

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
            ...$this->brandRules('settings.'),
            // Delivery settings, kept apart from the layout keys above: where a
            // push tap lands (Liquid allowed, so no url rule), what rides along
            // with it, and who a reply to an email reaches.
            'settings.url' => ['nullable', 'string', 'max:2048'],
            'settings.data' => ['nullable', 'array'],
            'settings.reply_to' => ['nullable', 'email', 'max:255'],
            'settings.reply_to_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    /**
     * Validation for the email layout's brand keys, shared by the template and
     * broadcast composers and the workspace Brand page.
     *
     * @return array<string, mixed>
     */
    protected function brandRules(string $prefix = ''): array
    {
        return [
            $prefix.'brand_name' => ['nullable', 'string', 'max:80'],
            $prefix.'brand_suffix' => ['nullable', 'string', 'max:20'],
            $prefix.'tagline' => ['nullable', 'string', 'max:160'],
            $prefix.'logo_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'website_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'support_email' => ['nullable', 'email', 'max:255'],
            $prefix.'background_color' => $this->hexColorRule(),
            $prefix.'card_color' => $this->hexColorRule(),
            $prefix.'heading_color' => $this->hexColorRule(),
            $prefix.'text_color' => $this->hexColorRule(),
            $prefix.'muted_color' => $this->hexColorRule(),
            $prefix.'footer_color' => $this->hexColorRule(),
            $prefix.'footer_divider_color' => $this->hexColorRule(),
            $prefix.'accent_color' => $this->hexColorRule(),
            $prefix.'show_app_badges' => ['nullable', 'boolean'],
            $prefix.'badge_caption' => ['nullable', 'string', 'max:160'],
            $prefix.'app_store_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'app_store_badge_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'play_store_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'play_store_badge_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'show_social_links' => ['nullable', 'boolean'],
            $prefix.'instagram_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'youtube_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'facebook_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'tiktok_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'linkedin_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'x_url' => ['nullable', 'url', 'max:2048'],
            $prefix.'footer_note' => ['nullable', 'string', 'max:1000'],
            $prefix.'crisis_text' => ['nullable', 'string', 'max:1000'],
            $prefix.'company_line' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * A template or broadcast may name the subscription topic it belongs to;
     * people who opted out of that topic are skipped. None = only channel-level
     * unsubscribes apply.
     *
     * @return array<int, mixed>
     */
    protected function topicRule(int $workspaceId): array
    {
        return ['nullable', 'integer', Rule::exists('engage_subscription_topics', 'id')->where('workspace_id', $workspaceId)];
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
            ? array_merge($this->layouts->normalizeSettings($template->settings, $template->workspace), $delivery)
            : $delivery;
    }

    /**
     * Coerce raw authoring input into the fields a Template model expects.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizedContent(array $data, ?Workspace $workspace = null): array
    {
        $delivery = $this->deliverySettings($data['settings'] ?? null, $data['channel']);

        if ($data['channel'] !== 'email') {
            return [...$data, 'layout' => 'plain', 'preheader' => null, 'settings' => $delivery ?: null];
        }

        return [
            ...$data,
            'layout' => $data['layout'] ?? config('email_templates.default_layout', 'mytherapist'),
            // Only what differs from the workspace brand is stored, so a template
            // keeps following the brand for everything it didn't change.
            'settings' => array_merge($this->layouts->overridesFrom($data['settings'] ?? [], $workspace), $delivery) ?: null,
        ];
    }

    /**
     * Copy a template's message content into the fields the broadcast owns, so
     * the campaign starts as an editable snapshot rather than a live reference.
     *
     * @return array<string, mixed>
     */
    protected function snapshotFrom(Template $template): array
    {
        $content = $this->normalizedContent([
            'channel' => $template->channel,
            'name' => $template->name,
            'subject' => $template->subject,
            'body' => $template->body,
            'layout' => $template->layout,
            'preheader' => $template->preheader,
            'from_name' => $template->from_name,
            'from_address' => $template->from_address,
            'settings' => $template->settings,
        ], $template->workspace);

        return [
            'subject' => $content['subject'] ?? null,
            'body' => $content['body'] ?? '',
            'layout' => $content['layout'] ?? null,
            'preheader' => $content['preheader'] ?? null,
            'settings' => $content['settings'] ?? null,
            'from_name' => $content['from_name'] ?? null,
            'from_address' => $content['from_address'] ?? null,
            'subscription_topic_id' => $template->subscription_topic_id,
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
