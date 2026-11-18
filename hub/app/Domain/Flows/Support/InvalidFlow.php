<?php

declare(strict_types=1);

namespace App\Domain\Flows\Support;

use Illuminate\Validation\ValidationException;
use RuntimeException;

/** El flujo no se puede guardar o publicar. Errores por campo o por nodo (`graph.nodes.{id}`). */
final class InvalidFlow extends RuntimeException
{
    /** @param array<string, mixed> $errors mensaje o lista de mensajes por campo */
    public function __construct(public readonly array $errors)
    {
        $first = reset($errors);
        parent::__construct(is_array($first) ? (string) ($first[0] ?? '') : (string) $first);
    }

    public function toValidationException(): ValidationException
    {
        return ValidationException::withMessages(array_map(fn ($m) => is_array($m) ? array_map('strval', $m) : [(string) $m], $this->errors));
    }
}
