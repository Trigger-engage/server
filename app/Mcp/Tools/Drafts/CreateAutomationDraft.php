<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts;

use Illuminate\Support\Facades\Validator;
use TriggerEngage\Server\Engine\JourneySteps;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\Audit;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Drafts\Concerns\DraftsJourneys;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\Event;

class CreateAutomationDraft extends Tool
{
    use DraftsJourneys;

    public function __construct(JourneySteps $journeySteps)
    {
        $this->journeySteps = $journeySteps;
    }

    public function name(): string
    {
        return 'create_automation_draft';
    }

    public function title(): string
    {
        return 'Draft a new journey';
    }

    public function description(): string
    {
        return 'Draft a new automation (journey): what starts it, how often a person may go through it, and its steps. It '
            .'is saved unpublished, so it starts no runs; a person opens it in the editor, reviews it and publishes. '
            .'Check names first with list_events, list_templates and list_segments. '.self::STEP_FORMAT;
    }

    public function properties(): array
    {
        return [
            'name' => ['type' => 'string'],
            'trigger_event' => ['type' => 'string', 'description' => 'Name of the event that starts it, e.g. "signed_up" (see list_events).'],
            'reentry_policy' => ['type' => 'string', 'enum' => [Automation::REENTRY_EVERY_TIME, Automation::REENTRY_ONE_ACTIVE, Automation::REENTRY_ONCE_EVER], 'description' => 'Default once_ever_per_person.'],
            ...$this->journeyProperties(),
        ];
    }

    public function required(): array
    {
        return ['name', 'trigger_event', 'steps'];
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'trigger_event' => ['required', 'string', 'max:150'],
            'reentry_policy' => ['nullable', 'string'],
            ...$this->journeyArgumentRules(),
        ];
    }

    public function handle(array $arguments, Context $context): array
    {
        $workspace = $context->workspace;
        $event = Event::query()->where('workspace_id', $workspace->id)->where('name', $arguments['trigger_event'])->first();

        if (! $event) {
            throw new ToolError("No event named \"{$arguments['trigger_event']}\" in this workspace. See list_events; a journey can only start on an event the host app already sends.");
        }

        $attributes = Validator::make([
            'name' => $arguments['name'],
            'trigger_event_id' => $event->id,
            'reentry_policy' => $arguments['reentry_policy'] ?? Automation::REENTRY_ONCE_EVER,
        ], $this->journeySteps->automationRules($workspace->id))->validate();

        $graph = $this->buildDraft($arguments, $context);

        $automation = $workspace->automations()->create([...$attributes, 'status' => 'draft']);
        $this->saveDraft($automation, $graph, $context);
        $automation->load('triggerEvent:id,name');

        Audit::record($context, 'automation.drafted', $automation, "Drafted journey \"{$automation->name}\" on \"{$event->name}\" with ".count($arguments['steps']).' steps.');

        return $this->draftResult($automation, $context);
    }
}
