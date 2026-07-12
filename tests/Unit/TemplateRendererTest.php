<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use TriggerEngage\Server\Engine\TemplateRenderer;

class TemplateRendererTest extends TestCase
{
    public function test_it_renders_liquid_filters_and_conditionals(): void
    {
        $renderer = new TemplateRenderer;

        $result = $renderer->render(
            '{% if person.active %}{{ person.first_name | upcase }}{% endif %}',
            ['person' => ['active' => true, 'first_name' => 'Ada']]
        );

        $this->assertSame('ADA', $result);
        $this->assertSame([], $renderer->missingVariables());
    }

    public function test_it_reports_missing_output_variables(): void
    {
        $renderer = new TemplateRenderer;

        $this->assertSame('', $renderer->render('{{ person.missing }}', ['person' => []]));
        $this->assertSame(['person.missing'], $renderer->missingVariables());
    }
}
