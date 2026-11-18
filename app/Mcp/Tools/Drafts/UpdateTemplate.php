<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\EmailLayoutRenderer;
use TriggerEngage\Server\Engine\TemplateRenderer;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsContent;
use TriggerEngage\Server\Models\Template;

class UpdateTemplate extends Tool
{
    use DraftsContent;

    public function __construct(EmailLayoutRenderer $layouts, TemplateRenderer $renderer)
    {
        $this->layouts = $layouts;
        $this->renderer = $renderer;
    }

    public function name(): string
    {
        return 'update_template';
    }

    public function title(): string
    {
        return 'Update a template';
    }

    public function description(): string
    {
        return 'Change a template that no live journey sends. Templates have no draft state: a change applies to the next '
            .'send. So a template that an active journey sends, or that people are still moving through a journey towards, '
            .'is refused. For those, duplicate_template, edit the copy, and point a journey draft at it. Name only the fields '
            .'that change. Broadcasts keep their own copy of the content, so editing a template never changes one.';
    }

    public function properties(): array
    {
        return ['template_id' => ['type' => 'integer'], ...$this->contentProperties(creating: false)];
    }

    public function required(): array
    {
        return ['template_id'];
    }

    public function rules(): array
    {
        return ['template_id' => ['required', 'integer'], ...$this->contentRulesFor(creating: false)];
    }

    public function annotations(): array
    {
        return [...parent::annotations(), 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Template $template */
        $template = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');

        $live = (new AutomationIndex($context->workspace))->liveUsingTemplate($template->id);
        if ($live !== []) {
            throw new ToolError('Template '.$template->id.' is sent by live journeys: '
                .collect($live)->map(fn ($automation) => '"'.$automation['name'].'" ('.$automation['id'].')')->implode(', ')
                .'. Changing it would change what people receive next. Use duplicate_template, edit the copy, and point a journey draft at it.');
        }

        $changes = collect($arguments)->except(['template_id', 'subscription_topic'])->all();
        $content = [
            ...$template->only('channel', 'name', 'subject', 'body', 'layout', 'preheader', 'from_name', 'from_address', 'settings'),
            ...$changes,
            'channel' => $template->channel,
        ];

        $template->update([
            ...$this->checkedContent($content, $context),
            'subscription_topic_id' => $this->topicId($arguments, $context, $template->subscription_topic_id),
        ]);

        $fields = array_keys(collect($arguments)->except('template_id')->all());
        Audit::record($context, 'template.updated', $template, "Updated template \"{$template->name}\": ".implode(', ', $fields).'.', ['fields' => $fields]);

        return [
            'template' => $template->only('id', 'channel', 'name', 'subject', 'layout'),
            'changed' => $fields,
            'empty_in_preview' => $this->previewWarnings($content, $context),
            'url' => $context->url('templates.edit', $template),
        ];
    }
}
