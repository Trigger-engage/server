<?php

namespace TriggerEngage\Server\Mcp\Support;

use Illuminate\Support\Collection;
use TriggerEngage\Server\Models\Automation;
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

    public function __construct(Workspace $workspace)
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
        return $this->matching(fn (array $node) => in_array($node['type'] ?? null, ['send_email', 'send_sms', 'send_push'], true)
            && (int) ($node['config']['template_id'] ?? 0) === $templateId);
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
        return $this->matching(function (array $node) use ($segmentId): bool {
            if (($node['type'] ?? null) === 'segment') {
                return (int) ($node['config']['segment_id'] ?? 0) === $segmentId;
            }

            if (($node['type'] ?? null) === 'trigger') {
                return collect($node['config']['filters'] ?? [])->contains(fn ($filter) => is_array($filter)
                    && in_array($filter['field'] ?? null, ['segment_id', 'event.segment_id'], true)
                    && (string) ($filter['value'] ?? '') === (string) $segmentId);
            }

            return false;
        });
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
