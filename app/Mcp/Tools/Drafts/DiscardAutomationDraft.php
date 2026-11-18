<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Automation;

class DiscardAutomationDraft extends Tool
{
    public function name(): string
    {
        return 'discard_automation_draft';
    }

    public function title(): string
    {
        return 'Discard a journey draft';
    }

    public function description(): string
    {
        return 'Throw away a journey\'s unpublished draft. A live journey carries on with its live steps; a journey that '
            .'was never published is left with no steps.';
    }

    public function writes(): bool
    {
        return true;
    }

    public function annotations(): array
    {
        return [...parent::annotations(), 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function properties(): array
    {
        return ['automation_id' => ['type' => 'integer']];
    }

    public function required(): array
    {
        return ['automation_id'];
    }

    public function rules(): array
    {
        return ['automation_id' => ['required', 'integer']];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Automation $automation */
        $automation = $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation');

        if (! $automation->hasDraft()) {
            throw new ToolError("Journey \"{$automation->name}\" has no draft to discard.");
        }

        $automation->update(['draft_graph' => null, 'draft_saved_at' => null, 'draft_saved_by' => null]);
        Audit::record($context, 'automation.draft_discarded', $automation, "Discarded the draft of journey \"{$automation->name}\".");

        return [
            'automation' => $automation->only('id', 'name', 'status'),
            'draft' => null,
            'url' => $context->url('automations.edit', $automation),
        ];
    }
}
