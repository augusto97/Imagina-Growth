<?php

declare(strict_types=1);

namespace App\Domain\Segments\Compiler;

use RuntimeException;

/** La consulta del segmento superó el tiempo máximo (hub/CLAUDE.md §6: 10 s). */
final class SegmentTimeout extends RuntimeException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(__('El segmento tardó demasiado en calcularse (más de 10 segundos). Simplifica las condiciones de comportamiento o acorta las ventanas de tiempo.'), 0, $previous);
    }

    public static function looksLike(\Throwable $e): bool
    {
        $m = $e->getMessage();

        return str_contains($m, 'max_statement_time') || str_contains($m, 'Query execution was interrupted')
            || str_contains($m, 'TIMEOUT_EXCEEDED') || str_contains($m, 'Timeout exceeded') || str_contains($m, 'cURL error 28');
    }
}
