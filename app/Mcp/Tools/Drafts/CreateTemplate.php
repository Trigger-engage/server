<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsContent;

class CreateTemplate extends Tool
{
    use DraftsContent;

    public function __construct(EmailLayoutRenderer $layouts, TemplateRenderer $renderer)
    {
        $this->layouts = $layouts;
        $this->renderer = $renderer;
    }

    public function name(): string
    {
        return 'create_template';
    }

    public function title(): string
    {
        return 'Create a template';
    }

    public function description(): string
    {
        return 'Write a new email, SMS or push template. It sends nothing on its own: a journey step or a broadcast has '
            .'to use it, and a person publishes or sends those. The content is checked like the template editor checks it '
            .'(Liquid must render). Returns the template and any {{ variables }} that came out empty in the sample preview. '
            .'Use render_template to see it for a real person and send_test to get it in your own inbox.';
    }

    public function properties(): array
    {
        return $this->contentProperties(creating: true);
    }

    public function required(): array
    {
        return ['channel', 'name', 'body'];
    }

    public function rules(): array
    {
        return $this->contentRulesFor(creating: true);
    }

    public function handle(array $arguments, Context $context): array
    {
        $content = collect($arguments)->except('subscription_topic')->all();
        $stored = $this->checkedContent($content, $context);
        $template = $context->workspace->templates()->create([
            ...$stored,
            'subscription_topic_id' => $this->topicId($arguments, $context),
        ]);

        Audit::record($context, 'template.created', $template, "Created {$template->channel} template \"{$template->name}\".");

        return [
            'template' => $template->only('id', 'channel', 'name', 'subject', 'layout'),
            'empty_in_preview' => $this->previewWarnings($content, $context),
            'url' => $context->url('templates.edit', $template),
        ];
    }
}
