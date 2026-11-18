<?php

namespace TriggerEngage\Server\Mcp\Tools\Templates;

use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\HtmlText;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Template;

class RenderTemplate extends Tool
{
    public function __construct(
        protected TemplateRenderer $renderer,
        protected EmailLayoutRenderer $layouts,
    ) {}

    public function name(): string
    {
        return 'render_template';
    }

    public function title(): string
    {
        return 'Render a template';
    }

    public function description(): string
    {
        return 'Fill a template in, exactly as a send would: for a real person (their profile as {{ person.* }}) and an '
            .'optional event payload ({{ event.* }}), or for the sample context the dashboard preview uses. Returns the '
            .'subject, preheader and body, and the variables that rendered empty. Nothing is sent. Email bodies come back '
            .'as readable text unless you ask for html (the full email with its layout).';
    }

    public function properties(): array
    {
        return [
            'template_id' => ['type' => 'integer'],
            ...$this->personProperties('Render for this person'),
            'event' => ['type' => 'object', 'description' => 'The trigger event payload to render {{ event.* }} with.'],
            'format' => ['type' => 'string', 'enum' => ['text', 'html'], 'description' => 'How to return an email body (default text).'],
        ];
    }

    public function required(): array
    {
        return ['template_id'];
    }

    public function rules(): array
    {
        return [
            'template_id' => ['required', 'integer'],
            ...$this->personRules(required: false),
            'event' => ['nullable', 'array'],
            'format' => ['nullable', 'in:text,html'],
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Template $template */
        $template = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');
        $template->setRelation('workspace', $context->workspace);

        $person = $this->hasPerson($arguments) ? $this->findPerson($arguments, $context) : null;
        $sample = config('email_templates.preview_context', []);
        $renderContext = [
            'person' => $person ? $person->toContext() : ($sample['person'] ?? []),
            'event' => $arguments['event'] ?? ($person ? [] : ($sample['event'] ?? [])),
        ];

        try {
            $this->renderer->reset();
            $subject = $this->renderer->render((string) ($template->subject ?? ''), $renderContext);
            $preheader = $this->renderer->render((string) ($template->preheader ?? ''), $renderContext);
            $body = $this->renderer->render((string) $template->body, $renderContext);
            $missing = $this->renderer->missingVariables();
        } catch (\Throwable $exception) {
            throw new ToolError('The template\'s Liquid could not be rendered: '.$exception->getMessage());
        }

        $html = ($arguments['format'] ?? 'text') === 'html';
        if ($template->channel === 'email') {
            $body = $html
                ? $this->layouts->render($template, $body, $preheader, 'https://example.com/unsubscribe-preview')
                : HtmlText::from($body);
        }
        [$body, $truncated] = HtmlText::cap($body, $html ? 100000 : HtmlText::MAX_LENGTH);

        return [
            'template' => $template->only('id', 'channel', 'name'),
            'rendered_for' => $person ? $context->person($person) : 'the sample preview context',
            'subject' => $context->redactor->text($subject),
            'preheader' => $context->redactor->text($preheader),
            'body' => $context->redactor->text($body),
            'body_truncated' => $truncated,
            'missing_variables' => array_values(array_unique($missing)),
        ];
    }
}
