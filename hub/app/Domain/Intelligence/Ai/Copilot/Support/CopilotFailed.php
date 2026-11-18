<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Support;

use RuntimeException;

/**
 * El copiloto no logró una propuesta válida (después del intento de corrección).
 * El mensaje es para la persona; `errors` lleva el detalle por campo.
 */
final class CopilotFailed extends RuntimeException
{
    /** @param array<string, string|list<string>> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}
