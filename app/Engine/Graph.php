<?php

namespace TriggerEngage\Server\Engine;

/**
 * Immutable view over an automation version's graph JSON:
 * { "nodes": [{"id","type","config"}], "edges": [{"from","to","branch"?}] }
 */
class Graph
{
    protected array $graph;

    /** @var array<string, array> */
    protected array $nodes = [];

    /** @var array<int, array{from: string, to: string, branch?: string}> */
    protected array $edges = [];

    public function __construct(array $graph)
    {
        $this->graph = $graph;

        foreach ($graph['nodes'] ?? [] as $node) {
            $this->nodes[$node['id']] = $node + ['config' => []];
        }

        $this->edges = $graph['edges'] ?? [];
    }

    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function triggerNode(): ?array
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === 'trigger') {
                return $node;
            }
        }

        return null;
    }

    /** @return array<int, array> */
    public function goals(): array
    {
        return $this->graph['goals'] ?? [];
    }

    /**
     * The node that follows $fromId. For branch nodes, $branch selects the
     * "true" or "false" edge; plain edges (no branch key) match any outcome.
     */
    public function after(string $fromId, ?string $branch = null): ?array
    {
        foreach ($this->edges as $edge) {
            if ($edge['from'] !== $fromId) {
                continue;
            }

            if (! isset($edge['branch']) || $edge['branch'] === $branch) {
                return $this->node($edge['to']);
            }
        }

        return null;
    }
}
