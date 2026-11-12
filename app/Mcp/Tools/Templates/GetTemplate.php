<?php

namespace TriggerEngage\Server\Mcp\Tools\Templates;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Support\HtmlText;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Template;

class GetTemplate extends Tool
{
    public function name(): string
    {
        return 'get_template';
    }

    public function title(): string
    {
        return 'Get a template';
    }

    public function description(): string
    {
        return 'One template as written: subject, preheader and body in Liquid, sender, layout, delivery settings (push '
            .'deep link and data, email reply-to), subscription topic, the {{ variables }} it reads, and which '
            .'automations and broadcasts use it.';
    }

    public function properties(): array
    {
        return ['template_id' => ['type' => 'integer']];
    }

    public function required(): array
    {
        return ['template_id'];
    }

    public function rules(): array
    {
        return ['template_id' => ['required', 'integer']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Template $template */
        $template = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');
        $template->load('subscriptionTopic:id,key,name');
        [$body, $truncated] = HtmlText::cap((string) $template->body, 50000);

        return [
            'template' => [
                ...$template->only('id', 'channel', 'name', 'subject', 'preheader', 'layout', 'from_name', 'from_address'),
                'body' => $body,
                'body_truncated' => $truncated,
                'settings' => $context->redactor->withoutSecrets($template->settings ?? []),
                'topic' => $template->subscriptionTopic?->only('key', 'name'),
                'variables' => $this->variables(implode("\n", [(string) $template->subject, (string) $template->preheader, (string) $template->body])),
                'updated_at' => $context->time($template->updated_at),
                'url' => $context->url('templates.edit', $template),
            ],
            'used_by_automations' => (new AutomationIndex($context->workspace))->usingTemplate($template->id),
            'broadcasts' => $context->workspace->broadcasts()->where('template_id', $template->id)->count(),
        ];
    }

    /**
     * The context paths a template reads, e.g. person.first_name, from
     * output tags and from if/unless/for tags.
     *
     * @return array<int, string>
     */
    protected function variables(string $source): array
    {
        preg_match_all('/\{\{-?\s*([a-zA-Z_][a-zA-Z0-9_.\-\[\]]*)/', $source, $outputs);
        preg_match_all('/\{%-?\s*(?:if|unless|elsif|for\s+\w+\s+in)\s+([a-zA-Z_][a-zA-Z0-9_.\-\[\]]*)/', $source, $tags);

        return collect([...$outputs[1], ...$tags[1]])->unique()->sort()->values()->all();
    }
}
