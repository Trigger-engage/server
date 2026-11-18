<?php

namespace TriggerEngage\Server\Mcp\Tools\Live;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use TriggerEngage\Server\Engine\EditorFidelity;
use TriggerEngage\Server\Engine\JourneySteps;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\Tool;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Mcp\Tools\Live\Concerns\ConfirmsLiveAction;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\EventOccurrence;

class PublishAutomation extends Tool
{
    use ConfirmsLiveAction;

    public function __construct(protected JourneySteps $journeySteps) {}

    public function name(): string
    {
        return 'publish_automation';
    }

    public function title(): string
    {
        return 'Publish a journey';
    }

    public function description(): string
    {
        return 'Make a journey\'s draft live, as Publish draft in the editor does: it becomes a new version, people already '
            .'mid-journey stay on the version they started, and everyone who triggers it from now on gets the new steps. On '
            .'a paused journey with no draft, this resumes it. Two calls: the first returns a preview (what goes live, what '
            .'it replaces, how many people a week start it) and a confirmation; only the second call, with that '
            .'confirmation, publishes. Show the person the preview and publish only if they say yes.';
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
        return $this->findOwned(Automation::class, $arguments['automation_id'], $context, 'automation')->load('triggerEvent:id,name', 'activeVersion');
    }

    protected function guard(Model $subject, Context $context): void
    {
        /** @var Automation $subject */
        if (! $subject->hasDraft() && ! ($subject->status === 'paused' && $subject->active_version_id)) {
            throw new ToolError("Journey \"{$subject->name}\" has no draft to publish".($subject->status === 'active' ? ' and is already live.' : '.')
                .' Save one with update_automation_draft first.');
        }

        if ($subject->hasDraft()) {
            $this->rebuiltDraft($subject, $context);
        }
    }

    protected function fingerprint(Model $subject): string
    {
        /** @var Automation $subject */
        return md5(json_encode([$subject->draft_graph, $subject->status, $subject->active_version_id, $subject->draft_saved_at?->getTimestamp()]));
    }

    protected function preview(Model $subject, Context $context): array
    {
        /** @var Automation $subject */
        $describer = new GraphDescriber($context->workspace);
        $steps = fn (?array $graph) => $graph === null ? null : collect($describer->describe($graph)['steps'])
            ->map(fn (array $step) => $context->redactor->text($step['summary']))->values()->all();
        $liveGraph = $subject->activeVersion?->graph;

        return [
            'journey' => [...$subject->only('id', 'name', 'status'), 'trigger_event' => $subject->triggerEvent?->name],
            'what_happens' => match (true) {
                ! $subject->hasDraft() => 'Resumes the paused journey with its current live steps.',
                $subject->active_version_id === null => 'Publishes the journey for the first time: from now on, people who trigger it start these steps.',
                default => 'Replaces the live steps with the draft for everyone who triggers it from now on.',
            },
            'goes_live' => $subject->hasDraft() ? $steps($subject->draft_graph) : $steps($liveGraph),
            'replaces' => $subject->hasDraft() ? $steps($liveGraph) : null,
            'reactivates_a_paused_journey' => $subject->status === 'paused',
            'people_mid_journey_staying_on_their_version' => AutomationRun::query()->where('automation_id', $subject->id)
                ->whereIn('status', AutomationRun::activeStatuses())->count(),
            'trigger_events_last_7_days' => $subject->trigger_event_id ? EventOccurrence::query()
                ->where('event_id', $subject->trigger_event_id)->where('occurred_at', '>=', now()->subDays(7))->count() : 0,
            'live_parts_the_editor_cannot_show' => $subject->hasDraft() && $liveGraph
                ? collect(EditorFidelity::issues($liveGraph))->pluck('message')->all()
                : [],
            'url' => $context->url('automations.edit', $subject),
        ];
    }

    protected function perform(Model $subject, Context $context): array
    {
        /** @var Automation $subject */
        if (! $subject->hasDraft()) {
            $subject->update(['status' => 'active']);

            return [
                'summary' => "Resumed journey \"{$subject->name}\".",
                'result' => ['automation' => $subject->only('id', 'name', 'status'), 'url' => $context->url('automations.edit', $subject)],
            ];
        }

        $version = $subject->publish($this->rebuiltDraft($subject, $context));

        return [
            'summary' => "Published journey \"{$subject->name}\" as version {$version->id}.",
            'result' => [
                'automation' => $subject->fresh()->only('id', 'name', 'status'),
                'version_id' => $version->id,
                'url' => $context->url('automations.edit', $subject),
            ],
        ];
    }

    /**
     * The draft, read back as editor steps and built again, so a template or
     * channel deleted since it was saved stops the publish instead of failing
     * every send.
     */
    protected function rebuiltDraft(Automation $automation, Context $context): array
    {
        $graph = $automation->draft_graph;

        try {
            return $this->journeySteps->build(
                $context->workspace->id,
                $this->journeySteps->steps($graph),
                $this->journeySteps->goal($graph),
                $this->journeySteps->triggerFilters($graph),
            );
        } catch (ValidationException $exception) {
            throw new ToolError('The draft can\'t be published as it stands: '.collect($exception->errors())->flatten()->implode(' ')
                .' Fix it with update_automation_draft.');
        }
    }
}
