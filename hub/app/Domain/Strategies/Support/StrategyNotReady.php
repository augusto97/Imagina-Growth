<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use RuntimeException;

/**
 * La estrategia no se pudo dejar funcionando: falta un requisito o el bloque tiene pendientes (p. ej. el flujo pide
 * elegir una plantilla). La API responde 422 `needs_setup` con los requisitos, los pendientes y adónde ir; o 422
 * `coming_soon` si su flujo todavía no está en la biblioteca.
 */
final class StrategyNotReady extends RuntimeException
{
    /**
     * @param  list<Requirement>  $requirements
     * @param  array<string, list<string>>  $issues  pendientes del bloque, por campo
     */
    public function __construct(
        string $message,
        public readonly array $requirements = [],
        public readonly array $issues = [],
        public readonly ?string $to = null,
        public readonly string $error = 'needs_setup',
    ) {
        parent::__construct($message);
    }
}
