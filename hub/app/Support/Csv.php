<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Celdas seguras para exportar a CSV (docs/auditoria-2026-11.md S10). Excel y Google Sheets ejecutan como fórmula una
 * celda que empieza por `=`, `+`, `-`, `@`, tabulador o retorno: un nombre de contacto como `=HYPERLINK(...)` se
 * volvería un enlace o un comando al abrir el archivo. Con un apóstrofo delante se muestra como texto.
 */
final class Csv
{
    public static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /**
     * @param  list<string|int|float>  $row
     * @return list<string|int|float>
     */
    public static function row(array $row): array
    {
        return array_map(fn ($v) => is_string($v) ? self::cell($v) : $v, $row);
    }
}
