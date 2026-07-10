<?php

namespace App\Engine;

use Illuminate\Support\Arr;

/**
 * Minimal {{ dot.path }} substitution over the run context
 * ({{ person.first_name }}, {{ event.plan }}). Missing variables render
 * empty and are reported so the run log can surface a warning.
 * Deliberately dependency-free for v0.1; full Liquid support can slot in
 * behind this same interface later.
 */
class TemplateRenderer
{
    /** @var array<int, string> */
    protected array $missing = [];

    public function render(string $template, array $context): string
    {
        $this->missing = [];

        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.\-]+)\s*\}\}/',
            function (array $matches) use ($context) {
                $value = Arr::get($context, $matches[1]);

                if (is_null($value)) {
                    $this->missing[] = $matches[1];

                    return '';
                }

                return is_scalar($value) ? (string) $value : json_encode($value);
            },
            $template
        );
    }

    /** @return array<int, string> */
    public function missingVariables(): array
    {
        return array_values(array_unique($this->missing));
    }
}
