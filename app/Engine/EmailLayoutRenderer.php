<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Arr;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use TriggerEngage\Server\Models\Template;
use TriggerEngage\Server\Models\Workspace;

class EmailLayoutRenderer
{
    /** @return array<string, mixed> */
    public function defaultSettings(): array
    {
        return config('email_templates.mytherapist', []);
    }

    /**
     * The workspace's brand: the configured defaults with whatever the
     * workspace set on its Brand page on top.
     *
     * @return array<string, mixed>
     */
    public function brandFor(?Workspace $workspace): array
    {
        $defaults = $this->defaultSettings();

        return array_replace($defaults, Arr::only($workspace?->brand ?? [], array_keys($defaults)));
    }

    /**
     * Everything a branded email renders with: the workspace brand, then the
     * keys this template or broadcast overrides.
     *
     * @return array<string, mixed>
     */
    public function normalizeSettings(?array $settings, ?Workspace $workspace = null): array
    {
        $brand = $this->brandFor($workspace);

        return array_replace($brand, Arr::only($settings ?? [], array_keys($brand)));
    }

    /**
     * The layout keys in $settings that differ from the workspace brand. Only
     * these are stored on a template, so it follows later brand changes for
     * everything it didn't change itself.
     *
     * @return array<string, mixed>
     */
    public function overridesFrom(array $settings, ?Workspace $workspace): array
    {
        $brand = $this->brandFor($workspace);

        return array_filter(
            Arr::only($settings, array_keys($brand)),
            fn ($value, string $key) => ! $this->sameSetting($value, $brand[$key]),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    protected function sameSetting(mixed $value, mixed $brand): bool
    {
        if (is_string($value) && is_string($brand)) {
            // The colour picker upper-cases hex values; copy is compared exactly.
            return str_starts_with($brand, '#') ? strcasecmp(trim($value), $brand) === 0 : trim($value) === trim($brand);
        }

        return $value === $brand;
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
            'settings' => $this->normalizeSettings($template->settings, $template->workspace),
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
