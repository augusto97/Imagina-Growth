<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

/**
 * Arma el grafo de un flujo de la biblioteca. Siempre empieza con el nodo
 * `trigger`; las posiciones para el constructor se calculan por niveles (la
 * distancia desde el disparador) y se reparten a lo ancho.
 */
final class LibraryGraph
{
    /** @var array<string, array{id: string, type: string, config: array<string, mixed>}> */
    private array $nodes = ['trigger' => ['id' => 'trigger', 'type' => 'trigger', 'config' => []]];

    /** @var list<array{from: string, to: string, branch?: string}> */
    private array $edges = [];

    /** @param array<string, mixed> $config */
    public function node(string $id, string $type, array $config = []): self
    {
        $this->nodes[$id] = ['id' => $id, 'type' => $type, 'config' => $config];

        return $this;
    }

    public function edge(string $from, string $to, ?string $branch = null): self
    {
        $this->edges[] = $branch === null ? ['from' => $from, 'to' => $to] : ['from' => $from, 'to' => $to, 'branch' => $branch];

        return $this;
    }

    public function chain(string ...$ids): self
    {
        for ($i = 0; $i < count($ids) - 1; $i++) {
            $this->edge($ids[$i], $ids[$i + 1]);
        }

        return $this;
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['nodes' => self::layout(array_values($this->nodes), $this->edges), 'edges' => $this->edges];
    }

    /**
     * Posición por niveles (camino más largo desde el disparador, sin ciclos) y
     * reparto horizontal dentro de cada nivel.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{from: string, to: string, branch?: string}>  $edges
     * @return list<array<string, mixed>>
     */
    public static function layout(array $nodes, array $edges): array
    {
        $depth = ['trigger' => 0];
        for ($pass = 0; $pass < count($nodes); $pass++) {
            $changed = false;
            foreach ($edges as $edge) {
                if (isset($depth[$edge['from']]) && ($depth[$edge['to']] ?? -1) < $depth[$edge['from']] + 1 && count($nodes) > $depth[$edge['from']] + 1) {
                    $depth[$edge['to']] = $depth[$edge['from']] + 1;
                    $changed = true;
                }
            }
            if (! $changed) {
                break;
            }
        }
        $levels = [];
        foreach ($nodes as $node) {
            $levels[$depth[$node['id']] ?? 0][] = $node['id'];
        }
        $out = [];
        foreach ($nodes as $node) {
            $level = $depth[$node['id']] ?? 0;
            $row = $levels[$level];
            $index = (int) array_search($node['id'], $row, true);
            $node['position'] = ['x' => ($index - (count($row) - 1) / 2) * 300, 'y' => $level * 150];
            $out[] = $node;
        }

        return $out;
    }
}
