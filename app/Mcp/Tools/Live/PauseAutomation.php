<?php

namespace TriggerEngage\Server\Mcp\Tools\Live;

use Illuminate\Database\Eloquent\Model;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Live\Concerns\ConfirmsLiveAction;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;

class PauseAutomation extends Tool
{
    use ConfirmsLiveAction;

    public function name(): string
    {
        return 'pause_automation';
    }

    public function title(): string
    {
        return 'Pause a journey';
    }

    public function description(): string
    {
        return 'Stop a live journey from starting new runs, as Pause in the editor does. People already in it carry on to '
            .'the end of their version; publish_automation resumes it. Two calls: a preview (how many people are mid-journey, '
            .'how many a week start it) and a confirmation, then the call with that confirmation pauses. Show the person the '
            .'preview and pause only if they say yes.';
    }

    public function properties(): array
    {
        return ['automation_id' => ['type' => 'integer'], ...$this->confirmationProperty()];
    }

    public function required(): array
    {
        return ['automation_id'];
    }

    public function rules(): array
    {
        return ['automation_id' => ['required', 'integer'], 'confirmation' => ['nullable', 'string', 'max:200']];
    }

    protected function subject(array $arguments, Context $context): Model
    {
        return $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation')->load('triggerEvent:id,name');
    }

    protected function guard(Model $subject, Context $context): void
    {
        /** @var Automation $subject */
        if ($subject->status !== 'active') {
            throw new ToolError("Journey \"{$subject->name}\" is {$subject->status}, not live, so there is nothing to pause.");
        }
    }

    protected function fingerprint(Model $subject): string
    {
        /** @var Automation $subject */
        return md5(json_encode([$subject->status, $subject->active_version_id]));
    }

    protected function preview(Model $subject, Context $context): array
    {
        /** @var Automation $subject */
        return [
            'journey' => [...$subject->only('id', 'name', 'status'), 'trigger_event' => $subject->triggerEvent?->name],
            'what_happens' => 'No new runs start. People already in the journey carry on to the end of their version.',
            'people_mid_journey_who_carry_on' => AutomationRun::query()->where('automation_id', $subject->id)
                ->whereIn('status', AutomationRun::activeStatuses())->count(),
            'trigger_events_last_7_days' => $subject->trigger_event_id ? EventOccurrence::query()
                ->where('event_id', $subject->trigger_event_id)->where('occurred_at', '>=', now()->subDays(7))->count() : 0,
            'url' => $context->url('automations.edit', $subject),
        ];
    }

    protected function perform(Model $subject, Context $context): array
    {
        /** @var Automation $subject */
        $subject->update(['status' => 'paused']);

        return [
            'summary' => "Paused journey \"{$subject->name}\".",
            'result' => ['automation' => $subject->only('id', 'name', 'status'), 'url' => $context->url('automations.edit', $subject)],
        ];
    }
}
