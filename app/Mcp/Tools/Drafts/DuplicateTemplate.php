<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Models\Template;

class DuplicateTemplate extends Tool
{
    public function name(): string
    {
        return 'duplicate_template';
    }

    public function title(): string
    {
        return 'Duplicate a template';
    }

    public function description(): string
    {
        return 'Copy a template, content, sender, settings and topic included, under a new name. The way to change a '
            .'template a live journey sends: edit the copy with update_template, then point a journey draft at it.';
    }

    public function writes(): bool
    {
        return true;
    }

    public function properties(): array
    {
        return [
            'template_id' => ['type' => 'integer'],
            'name' => ['type' => 'string', 'description' => 'Name for the copy (default: the original\'s name + " (copy)").'],
        ];
    }

    public function required(): array
    {
        return ['template_id'];
    }

    public function rules(): array
    {
        return ['template_id' => ['required', 'integer'], 'name' => ['nullable', 'string', 'max:150']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Template $original */
        $original = $this->findOwned(Template::class, $arguments['template_id'], $context, 'template');

        $copy = $original->replicate();
        $copy->name = filled($arguments['name'] ?? null) ? $arguments['name'] : $original->name.' (copy)';
        $copy->save();

        Audit::record($context, 'template.duplicated', $copy, "Copied template \"{$original->name}\" ({$original->id}) as \"{$copy->name}\".", ['from' => $original->id]);

        return [
            'template' => $copy->only('id', 'channel', 'name', 'subject', 'layout'),
            'copied_from' => $original->id,
            'url' => $context->url('templates.edit', $copy),
        ];
    }
}
