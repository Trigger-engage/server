<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Arr;
use Liquid\Template;

/**
 * Liquid rendering over the run context. Missing output variables are
 * collected before rendering so the run timeline can surface warnings.
 */
class TemplateRenderer
{
    /** @var array<int, string> */
    protected array $missing = [];

    public function reset(): void
    {
        $this->missing = [];
    }

    public function render(string $template, array $context): string
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.\-]+)/', $template, $matches);

        foreach ($matches[1] ?? [] as $path) {
            if (is_null(Arr::get($context, $path))) {
                $this->missing[] = $path;
            }
        }

        $liquid = new Template;
        $liquid->parse($template);

        return $liquid->render($context);
    }

    /** @return array<int, string> */
    public function missingVariables(): array
    {
        return array_values(array_unique($this->missing));
    }
}
