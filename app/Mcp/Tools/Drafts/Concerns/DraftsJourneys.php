<?php

namespace TriggerEngage\Server\Mcp\Tools\Drafts\Concerns;

use Illuminate\Support\Facades\Validator;
use TriggerEngage\Server\Engine\EditorFidelity;
use TriggerEngage\Server\Engine\JourneySteps;
use TriggerEngage\Server\Mcp\Context;
use TriggerEngage\Server\Mcp\Support\GraphDescriber;
use TriggerEngage\Server\Mcp\ToolError;
use TriggerEngage\Server\Models\Automation;

/**
 * Journey drafts are written as the editor's own step list and built into a
 * graph by the editor's own builder, so whatever an agent drafts opens in the
 * editor, can be changed there, and publishes without losing anything.
 */
trait DraftsJourneys
{
    protected JourneySteps $journeySteps;

    public function writes(): bool
    {
        return true;
    }

    public const STEP_FORMAT = 'Steps run in order after the trigger; the journey ends after the last one. Step shapes: '
        .'{"type":"delay","days":1,"hours":0,"minutes":0} or {"type":"delay","until_time":"09:00"} (workspace time); '
        .'{"type":"send_email"|"send_sms"|"send_push","template_id":12,"channel_id":3 (optional, default channel),'
        .'"on_failure":"continue"|"fail"}; '
        .'{"type":"wait_for_event","event_id":7,"timeout_days":3,"timeout_action":"continue"|"exit"|"send_email",'
        .'"timeout_template_id":12 (when the timeout sends),"incoming_field":"appointment_id","trigger_field":"appointment_id" '
        .'(optional: only an event whose field matches the trigger event\'s)}; '
        .'{"type":"segment","segment_id":4,"in":true} (a filter: people outside it leave the journey); '
        .'{"type":"split","variants":[{"key":"a","weight":50,"type":"email","template_id":12},{"key":"b","weight":50,'
        .'"type":"email","template_id":13}]} (an A/B test of sends). Goal (optional): {"enabled":true,"event_id":9} ends a '
        .'run early when the person does that event. Trigger filters (optional): [{"field":"plan","operator":"equals",'
        .'"value":"premium"}] on the trigger event\'s payload.';

    /** @return array<string, array<string, mixed>> */
    protected function journeyProperties(): array
    {
        return [
            'steps' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => self::STEP_FORMAT],
            'goal' => ['type' => 'object', 'description' => '{"enabled":true,"event_id":9}, or {"enabled":false}.'],
            'trigger_filters' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => 'Up to 5 filters on the trigger event\'s payload.'],
        ];
    }

    /** @return array<string, mixed> */
    protected function journeyArgumentRules(): array
    {
        // The detailed step rules need the workspace, so handle() applies them.
        return [
            'steps' => ['present', 'array', 'max:100'],
            'goal' => ['nullable', 'array'],
            'trigger_filters' => ['nullable', 'array', 'max:5'],
        ];
    }

    /**
     * Validate and build a graph from editor steps, exactly as a publish from
     * the editor would.
     *
     * @return array<string, mixed>
     */
    protected function buildDraft(array $arguments, Context $context): array
    {
        $input = [
            'steps' => $arguments['steps'] ?? [],
            'goal' => $arguments['goal'] ?? null,
            'trigger_filters' => $arguments['trigger_filters'] ?? [],
        ];
        $validated = Validator::make($input, $this->journeySteps->rules($context->workspace->id))->validate();

        $graph = $this->journeySteps->build(
            $context->workspace->id,
            $validated['steps'] ?? [],
            $validated['goal'] ?? null,
            $validated['trigger_filters'] ?? [],
        );

        $issues = EditorFidelity::issues($graph);
        if ($issues !== []) {
            throw new ToolError('The editor could not hold this journey: '.collect($issues)->pluck('message')->implode(' '));
        }

        return $graph;
    }

    protected function saveDraft(Automation $automation, array $graph, Context $context): void
    {
        $automation->update([
            'draft_graph' => $graph,
            'draft_saved_at' => now(),
            'draft_saved_by' => $context->actor,
        ]);
    }

    /** @return array<string, mixed> */
    protected function draftResult(Automation $automation, Context $context): array
    {
        $described = (new GraphDescriber($context->workspace))->describe($automation->draft_graph ?? []);

        return [
            'automation' => [
                ...$automation->only('id', 'name', 'status', 'reentry_policy'),
                'trigger_event' => $automation->triggerEvent?->only('id', 'name'),
                'live_version_untouched' => $automation->active_version_id !== null,
            ],
            'draft_steps' => collect($described['steps'])->map(fn (array $step) => [
                'id' => $step['id'],
                'summary' => $context->redactor->text($step['summary']),
            ])->all(),
            'goals' => $described['goals'],
            'next' => 'A person reviews the draft in the editor and publishes it to make it live.',
            'url' => $context->url('automations.edit', $automation),
        ];
    }
}
