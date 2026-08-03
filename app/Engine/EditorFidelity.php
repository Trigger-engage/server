<?php

namespace TriggerEngage\Server\Engine;

use Illuminate\Support\Collection;

/**
 * Answers one question: can the visual editor round-trip this stored graph
 * without changing its behaviour?
 *
 * The editor's model is a flat step list — AutomationController::editableSteps()
 * discards every edge, and buildGraph() rebuilds a straight spine from the list
 * plus a fixed set of config keys. Loss therefore comes in three shapes, and
 * this class checks all three per node:
 *
 *   1. Structure the spine cannot hold — unsupported node types, edges that
 *      skip or diverge, and (just as important) edges that are MISSING: a
 *      dead-end step is spliced back onto the spine by a republish.
 *   2. Edge-order semantics — the engine's Graph::after() lets an unlabeled
 *      edge answer for every branch outcome, a shape buildGraph() never writes.
 *   3. Config the rebuild does not reproduce — correlation rules without their
 *      editor-level scalar mirrors, a timeout_action contradicting the edges,
 *      split variants out of sync with their generated send nodes, retry
 *      backoffs the editor has no field for.
 *
 * Every check mirrors a convention in AutomationController::buildGraph() /
 * stepConfig() / buildGoals(); if those change, change this with them.
 */
class EditorFidelity
{
    /** Node types the editor can display, edit, and publish. */
    private const EDITOR_TYPES = ['delay', 'wait_for_event', 'send_email', 'send_sms', 'send_push', 'split', 'segment'];

    private const SEND_TYPES = ['send_email', 'send_sms', 'send_push'];

    /**
     * Human-readable reasons this graph cannot be faithfully republished from
     * the editor. Empty means a publish is a faithful round-trip.
     *
     * @param  array<string, mixed>  $graph
     * @return array<int, array{node: string|null, message: string}>
     */
    public static function issues(array $graph): array
    {
        $nodes = collect($graph['nodes'] ?? []);

        if ($nodes->isEmpty()) {
            return [];
        }

        $issues = collect();

        $duplicates = $nodes->countBy('id')->filter(fn (int $count) => $count > 1)->keys();
        foreach ($duplicates as $id) {
            $issues->push(['node' => $id, 'message' => sprintf('Two steps share the id “%s” — the engine runs one, but the editor would keep both.', $id)]);
        }

        $byId = $nodes->keyBy('id');
        $isGenerated = fn (array $node): bool => (bool) (($node['config']['generated_for_wait'] ?? false) || ($node['config']['generated_for_split'] ?? false));
        $isExitId = fn (?string $id): bool => $id !== null && ($byId[$id]['type'] ?? null) === 'exit';

        // The steps exactly as editableSteps() would list them, in node order.
        $visible = $nodes
            ->reject(fn (array $node) => in_array($node['type'], ['trigger', 'exit'], true))
            ->reject($isGenerated)
            ->values();

        $flagged = [];
        foreach ($visible as $index => $node) {
            if (! in_array($node['type'], self::EDITOR_TYPES, true)) {
                $flagged[$node['id']] = true;
                $issues->push([
                    'node' => $node['id'],
                    'message' => sprintf('Step %d is a “%s” step, which this editor cannot display or keep.', $index + 1, str_replace('_', ' ', (string) $node['type'])),
                ]);
            }
        }

        $position = $visible->pluck('id')->flip();
        // The rebuild wires each step to the next visible one; null means exit.
        $nextId = function (string $id) use ($visible, $position): ?string {
            $next = $visible->get(($position[$id] ?? -1) + 1);

            return $next['id'] ?? null;
        };
        // Semantic target match: the literal next step, or any exit when the
        // rebuild would write 'exit' there.
        $matchesNext = fn (string $fromId, string $to): bool => $to === $nextId($fromId)
            || ($nextId($fromId) === null && ($isExitId($to) || $to === 'exit'));
        $matchesExit = fn (string $to): bool => $isExitId($to) || ($to === 'exit' && ! isset($byId['exit']));

        /** @var Collection<string, Collection<int, array>> $outgoing */
        $outgoing = collect($graph['edges'] ?? [])
            ->filter(fn (array $edge) => isset($edge['from'], $edge['to']))
            ->groupBy('from');

        $trigger = $nodes->firstWhere('type', 'trigger');
        if ($trigger) {
            $edges = $outgoing->get($trigger['id'], collect());
            $first = $visible->first();
            $ok = $edges->count() === 1
                && ($edges->first()['branch'] ?? null) === null
                && ($first !== null ? $edges->first()['to'] === $first['id'] : $matchesExit($edges->first()['to']));

            if (! $ok) {
                $issues->push(['node' => null, 'message' => 'The trigger does not lead cleanly to the first step — the editor would rewire it.']);
            }
        }

        foreach ($visible as $index => $node) {
            if (isset($flagged[$node['id']])) {
                continue;
            }

            foreach (self::nodeIssues($node, $index, $outgoing->get($node['id'], collect()), $byId, $matchesNext, $matchesExit, $nextId) as $issue) {
                $issues->push($issue);
            }
        }

        // Generated nodes must continue to their owner's next step — and their
        // send config must agree with what the owner's config would regenerate.
        foreach ($nodes->filter($isGenerated) as $node) {
            $owner = (string) ($node['config']['generated_for_wait'] ?? $node['config']['generated_for_split'] ?? '');
            $edges = $outgoing->get($node['id'], collect());
            $ownerNumber = ($position[$owner] ?? 0) + 1;

            if ($edges->count() !== 1 || ($edges->first()['branch'] ?? null) !== null || ! $matchesNext($owner, $edges->first()['to'] ?? '')) {
                $issues->push([
                    'node' => $node['id'],
                    'message' => sprintf('Step %d: a message generated for it continues somewhere the editor would rewire.', $ownerNumber),
                ]);
            }

            if (isset($node['config']['retry_backoff_seconds'])) {
                $issues->push([
                    'node' => $node['id'],
                    'message' => sprintf('Step %d: a custom retry backoff is set, which this editor has no field for — publishing resets it.', $ownerNumber),
                ]);
            }
        }

        $goals = array_values($graph['goals'] ?? []);
        if (count($goals) > 1) {
            $issues->push(['node' => null, 'message' => sprintf('This journey defines %d goals; the editor keeps only the first.', count($goals))]);
        }
        if ($goals !== [] && ! self::correlationRepresentable($goals[0], $goals[0])) {
            $issues->push(['node' => null, 'message' => 'The goal matches events with rules this editor cannot express; publishing would simplify how it completes runs.']);
        }

        return $issues->unique('message')->values()->all();
    }

    /**
     * The complete out-edge set + config checks for one editable step, against
     * exactly what buildGraph() would write at this position.
     *
     * @param  array<string, mixed>  $node
     * @param  Collection<int, array>  $edges
     * @return iterable<array{node: string|null, message: string}>
     */
    protected static function nodeIssues(array $node, int $index, Collection $edges, Collection $byId, callable $matchesNext, callable $matchesExit, callable $nextId): iterable
    {
        $id = $node['id'];
        $type = $node['type'];
        $number = $index + 1;
        $label = str_replace('_', ' ', (string) $type);
        $config = $node['config'] ?? [];

        $issue = fn (string $what): array => [
            'node' => $id,
            'message' => sprintf('Step %d (%s): %s — the editor would rewire it.', $number, $label, $what),
        ];

        $byBranch = $edges->groupBy(fn (array $edge) => $edge['branch'] ?? '');
        $unlabeled = $byBranch->get('', collect());

        switch ($type) {
            case 'delay':
            case 'send_email':
            case 'send_sms':
            case 'send_push':
                if ($edges->count() !== 1 || $unlabeled->count() !== 1) {
                    yield $issue($edges->isEmpty() ? 'it dead-ends, but the editor would wire it onward' : 'it has paths the editor cannot represent');
                } elseif (! $matchesNext($id, $unlabeled->first()['to'])) {
                    yield $issue('its path skips over the following step');
                }

                if (in_array($type, self::SEND_TYPES, true) && isset($config['retry_backoff_seconds'])) {
                    yield ['node' => $id, 'message' => sprintf('Step %d has a custom retry backoff, which this editor has no field for — publishing resets it.', $number)];
                }
                break;

            case 'segment':
                $true = $byBranch->get('true', collect());
                $false = $byBranch->get('false', collect());

                if ($unlabeled->isNotEmpty()) {
                    // Graph::after() lets an unlabeled edge answer for BOTH
                    // outcomes; buildGraph() never writes that shape.
                    yield $issue('an unlabeled path would answer for both outcomes');
                } elseif ($true->count() !== 1 || $false->count() !== 1 || $edges->count() !== 2) {
                    yield $issue('it is missing the yes/no paths the editor writes');
                } else {
                    if (! $matchesNext($id, $true->first()['to'])) {
                        yield $issue('the matching-people path skips ahead');
                    }
                    if (! $matchesExit($false->first()['to'])) {
                        yield $issue('the non-matching path continues instead of exiting');
                    }
                }
                break;

            case 'wait_for_event':
                yield from self::waitIssues($node, $number, $byBranch, $unlabeled, $byId, $matchesNext, $matchesExit, $nextId, $issue);
                break;

            case 'split':
                yield from self::splitIssues($node, $number, $byBranch, $unlabeled, $byId, $issue);
                break;
        }
    }

    /** @return iterable<array{node: string|null, message: string}> */
    protected static function waitIssues(array $node, int $number, Collection $byBranch, Collection $unlabeled, Collection $byId, callable $matchesNext, callable $matchesExit, callable $nextId, callable $issue): iterable
    {
        $id = $node['id'];
        $config = $node['config'] ?? [];
        $matched = $byBranch->get('matched', collect());
        $timedOut = $byBranch->get('timed_out', collect());

        if ($unlabeled->isNotEmpty()) {
            yield $issue('an unlabeled path would answer for both the matched and timed-out outcomes');

            return;
        }

        if ($matched->count() !== 1 || $timedOut->count() !== 1) {
            yield $issue('it is missing the matched/timed-out paths the editor writes');

            return;
        }

        if (! $matchesNext($id, $matched->first()['to'])) {
            yield $issue('the matched path skips ahead');
        }

        $target = $timedOut->first()['to'];
        $targetNode = $byId[$target] ?? null;
        // What the timed-out edge actually does today.
        $edgeAction = match (true) {
            $matchesExit($target) && ! $matchesNext($id, $target) => 'exit',
            (($targetNode['config']['generated_for_wait'] ?? null)) === $id => (string) $targetNode['type'],
            $matchesNext($id, $target) => 'continue',
            default => null,
        };

        if ($edgeAction === null) {
            yield $issue('the timed-out path goes its own way');
        } elseif (isset($config['timeout_action']) && $config['timeout_action'] !== $edgeAction
            && ! ($matchesNext($id, $target) && $matchesExit($target))) {
            // Stored timeout_action wins on republish; when it disagrees with
            // the edge, the republish changes behaviour. (When the wait is the
            // last step, continue and exit converge — not a real divergence.)
            yield $issue(sprintf('its timeout says “%s” but its timed-out path does “%s”; the editor trusts the former', $config['timeout_action'], $edgeAction));
        }

        if (! self::correlationRepresentable($config, $config)) {
            yield ['node' => $id, 'message' => sprintf('Step %d waits with correlation rules this editor cannot express; publishing would change what resumes it.', $number)];
        }
    }

    /** @return iterable<array{node: string|null, message: string}> */
    protected static function splitIssues(array $node, int $number, Collection $byBranch, Collection $unlabeled, Collection $byId, callable $issue): iterable
    {
        $id = $node['id'];
        $variants = collect($node['config']['variants'] ?? []);
        $keys = $variants->pluck('key')->all();

        if ($unlabeled->isNotEmpty()) {
            yield $issue('an unlabeled path would bypass the A/B branches');

            return;
        }

        $branches = $byBranch->keys()->reject(fn ($key) => $key === '')->all();

        if (array_diff($keys, $branches) !== [] || array_diff($branches, $keys) !== []) {
            yield $issue('its branches do not match its A/B variants');

            return;
        }

        foreach ($variants as $variant) {
            $edge = $byBranch->get($variant['key'])->first();
            $generated = $byId[$edge['to']] ?? null;

            if ((($generated['config']['generated_for_split'] ?? null)) !== $id) {
                yield $issue(sprintf('variant “%s” sends through a step the editor did not generate', $variant['key']));

                continue;
            }

            // RunEngine executes the GENERATED node; the editor republishes
            // from the variants list. If they disagree, publishing silently
            // swaps what the variant actually sends.
            $drifted = $generated['type'] !== 'send_'.($variant['type'] ?? 'email')
                || ($generated['config']['template_id'] ?? null) !== ($variant['template_id'] ?? null)
                || ($generated['config']['channel_id'] ?? null) !== ($variant['channel_id'] ?? null);

            if ($drifted) {
                yield ['node' => $id, 'message' => sprintf('Step %d: A/B variant “%s” is out of sync with the message it actually sends; publishing would swap it.', $number, $variant['key'])];
            }
        }
    }

    /**
     * Whether stored match_rules are exactly what the rebuild would regenerate
     * from the editor-level scalar fields. buildGraph()/buildGoals() write one
     * trigger-sourced rule from filled scalars, or none.
     *
     * @param  array<string, mixed>  $scalars  where incoming_field/trigger_field/match_operator live
     * @param  array<string, mixed>  $rulesHolder  where match_rules lives
     */
    protected static function correlationRepresentable(array $scalars, array $rulesHolder): bool
    {
        $rules = array_values($rulesHolder['match_rules'] ?? []);
        $incoming = $scalars['incoming_field'] ?? '';
        $trigger = $scalars['trigger_field'] ?? '';
        $operator = $scalars['match_operator'] ?? 'equals';

        $expected = filled($incoming)
            ? [[
                'incoming_field' => $incoming,
                'operator' => $operator,
                'source' => 'trigger_event',
                'source_field' => $trigger,
            ]]
            : [];

        if (count($rules) !== count($expected)) {
            return false;
        }

        foreach ($expected as $index => $rule) {
            foreach ($rule as $key => $value) {
                if (($rules[$index][$key] ?? null) !== $value) {
                    return false;
                }
            }
        }

        return true;
    }
}
