<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Support;

use Illuminate\Support\Facades\DB;

/** Actualizaciones por lotes de columnas de `contacts` (un UPDATE con CASE por cada 500). */
final class Bulk
{
    /**
     * @param  array<string, array<string, mixed>>  $rows  contact_id → [columna => valor]
     */
    public static function contacts(string $workspaceId, array $rows): int
    {
        $done = 0;
        foreach (array_chunk($rows, 500, true) as $chunk) {
            $columns = [];
            foreach ($chunk as $values) {
                foreach (array_keys($values) as $c) {
                    $columns[$c] = true;
                }
            }
            $sets = [];
            $bindings = [];
            foreach (array_keys($columns) as $column) {
                $case = '`'.$column.'` = CASE `id`';
                foreach ($chunk as $id => $values) {
                    if (array_key_exists($column, $values)) {
                        $case .= ' WHEN ? THEN ?';
                        $bindings[] = $id;
                        $bindings[] = is_array($values[$column]) ? json_encode($values[$column], JSON_UNESCAPED_UNICODE) : $values[$column];
                    }
                }
                $sets[] = $case.' ELSE `'.$column.'` END';
            }
            $ids = array_keys($chunk);
            $bindings[] = $workspaceId;
            array_push($bindings, ...$ids);
            $done += DB::update('UPDATE `contacts` SET '.implode(', ', $sets).' WHERE `workspace_id` = ? AND `id` IN ('.implode(',', array_fill(0, count($ids), '?')).')', $bindings);
        }

        return $done;
    }
}
