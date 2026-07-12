<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Arr;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use TriggerEngage\Server\Models\Template;

class EmailLayoutRenderer
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return config('email_templates.mytherapist', []);
    }

    /** @return array<string, mixed> */
    public function normalizeSettings(?array $settings): array
    {
        $defaults = $this->defaultSettings();

        return array_replace($defaults, Arr::only($settings ?? [], array_keys($defaults)));
    }

    public function render(
        Template $template,
        string $body,
        ?string $preheader = null,
        ?string $unsubscribeUrl = null,
    ): string {
        if ($template->layout === 'plain') {
            return $this->plain($body, $unsubscribeUrl);
        }

        $html = view('trigger-engage::emails.layouts.mytherapist', [
            'body' => $body,
            'preheader' => $preheader,
            'unsubscribeUrl' => $unsubscribeUrl,
            'settings' => $this->normalizeSettings($template->settings),
        ])->render();

        return (new CssToInlineStyles)->convert($html);
    }

    protected function plain(string $body, ?string $unsubscribeUrl): string
    {
        if (! $unsubscribeUrl || str_contains($body, $unsubscribeUrl)) {
            return $body;
        }

        return $body.'<p style="font-size:12px;color:#64748b"><a href="'.e($unsubscribeUrl).'">Unsubscribe</a></p>';
    }
}
