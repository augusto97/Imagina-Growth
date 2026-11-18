<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Exceptions;

use RuntimeException;

/**
 * La IA no está disponible para este pedido. `reason`: disabled (sin clave o
 * apagada), budget (tope mensual), provider (error o caída del proveedor),
 * refusal (el modelo se negó), invalid (la respuesta no cumplió el esquema).
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '', ?\Throwable $previous = null)
    {
        parent::__construct($message !== '' ? $message : $reason, 0, $previous);
    }
}
