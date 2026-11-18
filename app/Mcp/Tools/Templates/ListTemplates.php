<?php

namespace TriggerEngage\Server\Mcp\Tools\Templates;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\AutomationIndex;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Template;

class ListTemplates extends Tool
{
    public function name(): string
    {
        return 'list_templates';
    }

    public function title(): string
    {
        return 'List templates';
    }

    public function description(): string
    {
        return 'The message templates (email, SMS, push) with their subject, subscription topic, when they last changed, '
            .'and which published automations send them. Use get_template for the content and render_template to see '
            .'it filled in.';
    }

    public function properties(): array
    {
        return [
            'channel' => ['type' => 'string', 'enum' => ['email', 'sms', 'push']],
            'search' => ['type' => 'string', 'description' => 'Only templates whose name or subject contains this text.'],
            ...$this->paginationProperties(),
        ];
    }

    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'in:email,sms,push'],
            'search' => ['nullable', 'string', 'max:150'],
            ...$this->paginationRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $usage = new AutomationIndex($context->workspace);
        $query = Template::query()
            ->where('workspace_id', $context->workspace->id)
            ->when($arguments['channel'] ?? null, fn ($query, $channel) => $query->where('channel', $channel))
            ->when($arguments['search'] ?? null, fn ($query, $search) => $query->where(fn ($nested) => $nested
                ->where('name', 'like', '%'.$search.'%')->orWhere('subject', 'like', '%'.$search.'%')))
            ->with('subscriptionTopic:id,key,name')
            ->orderBy('name');

        return $this->page($query, $arguments, fn (Template $template) => [
            ...$template->only('id', 'channel', 'name', 'subject', 'layout'),
            'topic' => $template->subscriptionTopic?->only('key', 'name'),
            'updated_at' => $context->time($template->updated_at),
            'used_by_automations' => $usage->usingTemplate($template->id),
        ]);
    }
}
