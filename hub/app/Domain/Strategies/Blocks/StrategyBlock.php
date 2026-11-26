<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyNotReady;

/**
 * Un bloque que ya existe en la plataforma (un flujo de la biblioteca, un widget, el programa de puntos, los ajustes
 * de reseñas, el agente…) visto desde una estrategia. La estrategia es solo orquestación: el bloque lee su estado y
 * llama a las acciones de su módulo para activarse o pausarse, nunca repite su lógica.
 */
interface StrategyBlock
{
    /** Nombre corto del bloque para la API (`flow`, `widget`, `loyalty_program`…). */
    public function kind(): string;

    /**
     * Requisitos propios del bloque (los comunes van en la definición de la estrategia).
     *
     * @param  array<string, mixed>  $options
     * @return list<Requirement>
     */
    public function requirements(StrategyContext $ctx, array $options = []): array;

    public function state(StrategyContext $ctx): BlockState;

    /**
     * Lo deja funcionando con los valores recomendados. Idempotente: si ya está activo, no hace nada.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, list<string>> lo que creó o tocó (`flows`, `widgets`…), para recordarlo
     *
     * @throws StrategyNotReady
     */
    public function activate(StrategyContext $ctx, array $options, ?string $userId): array;

    /**
     * Lo detiene sin borrar nada (se puede reanudar). Idempotente.
     *
     * @return array<string, mixed> lo que hay que guardar para reanudar igual
     */
    public function pause(StrategyContext $ctx, ?string $userId): array;

    /**
     * La vista previa para el detalle: el mensaje, el widget o el resumen del programa.
     *
     * @return array<string, mixed>|null
     */
    public function preview(StrategyContext $ctx): ?array;
}
