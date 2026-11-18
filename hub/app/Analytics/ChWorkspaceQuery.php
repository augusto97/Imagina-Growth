<?php

declare(strict_types=1);

namespace App\Analytics;

use InvalidArgumentException;

/**
 * Consulta de ClickHouse atada a un espacio de trabajo. El SQL debe filtrar con
 * {workspace_id:UInt32}; si no lo hace, se rechaza antes de ejecutarse.
 */
final class ChWorkspaceQuery
{
    public function __construct(private readonly Ch $ch, private readonly int $workspaceChId) {}

    /**
     * @param  array<string, scalar|null>  $params  parámetros {nombre:Tipo}; workspace_id lo pone esta clase
     * @return list<array<string, mixed>>
     */
    public function select(string $sql, array $params = []): array
    {
        if (! str_contains($sql, '{workspace_id:UInt32}')) {
            throw new InvalidArgumentException('Toda consulta a ClickHouse debe filtrar por {workspace_id:UInt32}.');
        }
        if (array_key_exists('workspace_id', $params)) {
            throw new InvalidArgumentException('workspace_id no se pasa como parámetro: lo inyecta Ch::forWorkspace().');
        }

        return $this->ch->selectWithParams($sql, ['workspace_id' => $this->workspaceChId] + $params);
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->select($sql, $params)[0] ?? null;

        return $row === null ? null : reset($row);
    }

    public function workspaceChId(): int
    {
        return $this->workspaceChId;
    }
}
