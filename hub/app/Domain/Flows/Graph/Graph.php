<?php

declare(strict_types=1);

namespace App\Domain\Flows\Graph;

/**
 * Grafo de una versión (`flow_versions.graph`): nodos `{id, type, config,
 * position?}` y aristas `{from, to, branch?}`. `branch` null es la salida normal.
 */
final class Graph
{
    /**
     * @param  array<string, array{id: string, type: string, config: array<string, mixed>, position?: array{x: float, y: float}}>  $nodes
     * @param  list<array{from: string, to: string, branch: string|null}>  $edges
     */
    private function __construct(public readonly array $nodes, public readonly array $edges) {}

    /** @param  array<mixed>  $data */
    public static function fromArray(array $data): self
    {
        $nodes = [];
        foreach ((array) ($data['nodes'] ?? []) as $node) {
            if (! is_array($node) || ! is_string($node['id'] ?? null) || ! is_string($node['type'] ?? null)) {
                continue;
            }
            $entry = ['id' => $node['id'], 'type' => $node['type'], 'config' => is_array($node['config'] ?? null) ? $node['config'] : []];
            if (is_array($node['position'] ?? null)) {
                $entry['position'] = ['x' => (float) ($node['position']['x'] ?? 0), 'y' => (float) ($node['position']['y'] ?? 0)];
            }
            $nodes[$node['id']] = $entry;
        }
        $edges = [];
        foreach ((array) ($data['edges'] ?? []) as $edge) {
            if (! is_array($edge) || ! is_string($edge['from'] ?? null) || ! is_string($edge['to'] ?? null)) {
                continue;
            }
            $branch = $edge['branch'] ?? null;
            $edges[] = ['from' => $edge['from'], 'to' => $edge['to'], 'branch' => is_string($branch) && $branch !== '' ? $branch : null];
        }

        return new self($nodes, $edges);
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return [
            'nodes' => array_values($this->nodes),
            'edges' => array_map(fn (array $e) => array_filter($e, fn ($v) => $v !== null), $this->edges),
        ];
    }

    /** @return array{id: string, type: string, config: array<string, mixed>, position?: array{x: float, y: float}}|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function triggerId(): ?string
    {
        foreach ($this->nodes as $node) {
            if ($node['type'] === NodeTypes::TRIGGER) {
                return $node['id'];
            }
        }

        return null;
    }

    /** Primer nodo después del disparador. */
    public function entry(): ?string
    {
        $trigger = $this->triggerId();

        return $trigger === null ? null : $this->next($trigger);
    }

    /**
     * Destino de la rama `branch` desde `from`. Con `fallback`, si no hay arista
     * para esa rama se sigue por la salida normal (p. ej. un envío negado sin
     * rama «falló» continúa como si se hubiera enviado).
     */
    public function next(string $from, ?string $branch = null, bool $fallback = false): ?string
    {
        foreach ($this->edges as $edge) {
            if ($edge['from'] === $from && $edge['branch'] === $branch) {
                return $edge['to'];
            }
        }
        if ($fallback && $branch !== null) {
            return $this->next($from);
        }

        return null;
    }

    /** @return list<array{from: string, to: string, branch: string|null}> */
    public function outgoing(string $id): array
    {
        return array_values(array_filter($this->edges, fn (array $e) => $e['from'] === $id));
    }

    /** @return list<array{id: string, type: string, config: array<string, mixed>, position?: array{x: float, y: float}}> */
    public function nodesOfType(string ...$types): array
    {
        return array_values(array_filter($this->nodes, fn (array $n) => in_array($n['type'], $types, true)));
    }

    /**
     * ¿Los nodos que siguen vivos en `$other` tienen el mismo tipo aquí? Sirve para
     * decidir si se pueden migrar los runs en curso a una versión nueva.
     *
     * @param  list<string>  $nodeIds
     */
    public function compatibleWith(self $other, array $nodeIds): bool
    {
        foreach ($nodeIds as $id) {
            $mine = $this->node($id);
            $theirs = $other->node($id);
            if ($mine === null || $theirs === null || $mine['type'] !== $theirs['type']) {
                return false;
            }
        }

        return true;
    }
}
