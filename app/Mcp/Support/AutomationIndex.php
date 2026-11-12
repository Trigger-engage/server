<?php

namespace TriggerEngage\Server\Mcp\Support;

use Illuminate\Support\Collection;
use TriggerEngage\Server\Models\Automation;
use TriggerEngage\Server\Models\AutomationRun;
use TriggerEngage\Server\Models\AutomationVersion;
use TriggerEngage\Server\Models\Workspace;

/**
 * Which automations use a template, channel, segment or event, read from
 * each automation's published graph. Drafts with nothing published use
 * nothing yet. Loaded once per tool call.
 */
class AutomationIndex
{
    /** @var Collection<int, Automation> */
    protected Collection $automations;

    /** @var Collection<int, AutomationVersion>|null */
    protected ?Collection $inFlightVersions = null;

    public function __construct(protected Workspace $workspace)
    {
        $this->automations = Automation::query()
            ->where('workspace_id', $workspace->id)
            ->with('activeVersion:id,automation_id,graph')
            ->orderBy('name')
            ->get(['id', 'name', 'status', 'trigger_event_id', 'active_version_id']);
    }

    /** @return array<int, array{id: int, name: string, status: string}> */
    public function usingTemplate(int $templateId): array
    {
        return $this->matching($this->templateTest($templateId));
    }

    /**
     * Journeys whose next sends would change if this template changed: active
     * ones whose live version sends it, and any version people are still
     * moving through (a paused journey's runs carry on).
     *
     * @return array<int, array{id: int, name: string, status: string}>
     */
    public function liveUsingTemplate(int $templateId): array
    {
        return $this->live($this->templateTest($templateId));
    }

    /**
     * Journeys that check this segment or start on people entering or
     * leaving it, live or still carrying people.
     *
     * @return array<int, array{id: int, name: string, status: string}>
     */
    public function liveUsingSegment(int $segmentId): array
    {
        return $this->live($this->segmentTest($segmentId));
    }

    protected function templateTest(int $templateId): callable
    {
        return function (array $node) use ($templateId): bool {
            if (in_array($node['type'] ?? null, ['send_email', 'send_sms', 'send_push'], true)) {
                return (int) ($node['config']['template_id'] ?? 0) === $templateId;
            }

            // A/B variants carry their own template ids.
            return collect($node['config']['variants'] ?? [])->contains(fn ($variant) => is_array($variant) && (int) ($variant['template_id'] ?? 0) === $templateId);
        };
    }

    /** @return array<int, array{id: int, name: string, status: string}> */
    protected function live(callable $test): array
    {
        $hits = $this->automations
            ->filter(fn (Automation $automation) => $automation->status === 'active' && $this->graphHas($automation->activeVersion?->graph ?? [], $test))
            ->keyBy('id');

        $this->inFlightVersions ??= AutomationVersion::query()
            ->whereIn('id', AutomationRun::query()
                ->where('workspace_id', $this->workspace->id)
                ->whereIn('status', AutomationRun::activeStatuses())
                ->distinct()
                ->select('automation_version_id'))
            ->get(['id', 'automation_id', 'graph']);

        foreach ($this->inFlightVersions as $version) {
            $automation = $this->automations->firstWhere('id', $version->automation_id);
            if ($automation && ! $hits->has($automation->id) && $this->graphHas($version->graph ?? [], $test)) {
                $hits->put($automation->id, $automation);
            }
        }

        return $hits->map(fn (Automation $automation) => $this->summary($automation))->values()->all();
    }

    protected function graphHas(array $graph, callable $test): bool
    {
        return collect($graph['nodes'] ?? [])->contains(fn ($node) => is_array($node) && $test($node));
    }

    /** @return array<int, array{id: int, name: string, status: string}> */
    public function usingChannel(int $channelId): array
    {
        return $this->matching(fn (array $node) => (int) ($node['config']['channel_id'] ?? 0) === $channelId);
    }

    /**
     * Segment steps, plus journeys whose trigger filter names the segment
     * (segment_entered / segment_left journeys).
     *
     * @return array<int, array{id: int, name: string, status: string}>
     */
    public function usingSegment(int $segmentId): array
    {
        return $this->matching($this->segmentTest($segmentId));
    }

    protected function segmentTest(int $segmentId): callable
    {
        return function (array $node) use ($segmentId): bool {
            if (($node['type'] ?? null) === 'segment') {
                return (int) ($node['config']['segment_id'] ?? 0) === $segmentId;
            }

            if (($node['type'] ?? null) === 'trigger') {
                return collect($node['config']['filters'] ?? [])->contains(fn ($filter) => is_array($filter)
                    && in_array($filter['field'] ?? null, ['segment_id', 'event.segment_id'], true)
                    && (string) ($filter['value'] ?? '') === (string) $segmentId);
            }

            return false;
        };
    }

    /**
     * @return array<int, array{id: int, name: string, status: string, role: string}>
     */
    public function usingEvent(int $eventId): array
    {
        return $this->automations->map(function (Automation $automation) use ($eventId): ?array {
            $graph = $automation->activeVersion?->graph ?? [];
            $roles = [];

            if ((int) $automation->trigger_event_id === $eventId) {
                $roles[] = 'trigger';
            }
            if (collect($graph['nodes'] ?? [])->contains(fn ($node) => is_array($node) && ($node['type'] ?? null) === 'wait_for_event' && (int) ($node['config']['event_id'] ?? 0) === $eventId)) {
                $roles[] = 'wait';
            }
            if (collect($graph['goals'] ?? [])->contains(fn ($goal) => is_array($goal) && (int) ($goal['event_id'] ?? 0) === $eventId)) {
                $roles[] = 'goal';
            }

            return $roles === [] ? null : [...$this->summary($automation), 'role' => implode(', ', $roles)];
        })->filter()->values()->all();
    }

    /** @return array<int, array{id: int, name: string, status: string}> */
    protected function matching(callable $test): array
    {
        return $this->automations
            ->filter(fn (Automation $automation) => collect($automation->activeVersion?->graph['nodes'] ?? [])
                ->contains(fn ($node) => is_array($node) && $test($node)))
            ->map(fn (Automation $automation) => $this->summary($automation))
            ->values()->all();
    }

    /** @return array{id: int, name: string, status: string} */
    protected function summary(Automation $automation): array
    {
        return ['id' => $automation->id, 'name' => $automation->name, 'status' => $automation->status];
    }
}
