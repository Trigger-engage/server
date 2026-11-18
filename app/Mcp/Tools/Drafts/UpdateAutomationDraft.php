<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use TriggerEngage\Server\Engine\JourneySteps;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsJourneys;
use TriggerEngage\Server\Models\Automation;

class UpdateAutomationDraft extends Tool
{
    use DraftsJourneys;

    public function __construct(JourneySteps $journeySteps)
    {
        $this->journeySteps = $journeySteps;
    }

    public function name(): string
    {
        return 'update_automation_draft';
    }

    public function title(): string
    {
        return 'Draft changes to a journey';
    }

    public function description(): string
    {
        return 'Save a new step list as the journey\'s draft. On a live journey the draft sits beside the live version: '
            .'people keep getting the live steps until a person publishes the draft in the editor. Give the whole step list '
            .'(get_automation shows the current one); goal and trigger filters keep their current values unless given. '
            .'The name, trigger event and re-entry policy only change on a journey that has never been published. '
            .self::STEP_FORMAT;
    }

    public function properties(): array
    {
        return [
            'automation_id' => ['type' => 'integer'],
            'name' => ['type' => 'string', 'description' => 'Only for a journey never published.'],
            ...$this->journeyProperties(),
        ];
    }

    public function required(): array
    {
        return ['automation_id', 'steps'];
    }

    public function rules(): array
    {
        return [
            'automation_id' => ['required', 'integer'],
            'name' => ['sometimes', 'string', 'max:150'],
            ...$this->journeyArgumentRules(),
        ];
    }

    public function annotations(): array
    {
        return [...parent::annotations(), 'destructiveHint' => true, 'idempotentHint' => true];
    }

    public function handle(array $arguments, Context $context): array
    {
        /** @var Automation $automation */
        $automation = $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation');
        $automation->load('triggerEvent:id,name', 'activeVersion');
        $current = $automation->editorGraph();

        if (array_key_exists('name', $arguments) && $arguments['name'] !== $automation->name) {
            if ($automation->active_version_id) {
                throw new ToolError('The name of a published journey changes straight away, so rename it in the dashboard.');
            }
            $automation->update(['name' => $arguments['name']]);
        }

        $graph = $this->buildDraft([
            'steps' => $arguments['steps'],
            'goal' => $arguments['goal'] ?? $this->journeySteps->goal($current),
            'trigger_filters' => $arguments['trigger_filters'] ?? $this->journeySteps->triggerFilters($current),
        ], $context);

        $this->saveDraft($automation, $graph, $context);

        Audit::record($context, 'automation.draft_saved', $automation, "Saved a draft of journey \"{$automation->name}\" with ".count($arguments['steps']).' steps'
            .($automation->active_version_id ? '; the live version is unchanged.' : '.'));

        return $this->draftResult($automation->refresh()->load('triggerEvent:id,name'), $context);
    }
}
