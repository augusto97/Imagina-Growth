<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Data;

use Closure;

/**
 * Herramienta que el modelo puede llamar. El `handler` recibe la entrada ya
 * validada contra el esquema (`strict`) y devuelve los hechos como arreglo: es
 * lo único que el modelo sabe del mundo. Una excepción vuelve al modelo como
 * error de herramienta, con su mensaje.
 */
final class AiTool
{
    /**
     * @param  array<string, mixed>  $inputSchema
     * @param  Closure(array<string, mixed>): array<string, mixed>  $handler
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
        public readonly Closure $handler,
    ) {}

    /** @return array<string, mixed> definición para la API (claves camelCase del SDK) */
    public function definition(): array
    {
        return ['name' => $this->name, 'description' => $this->description, 'inputSchema' => $this->inputSchema, 'strict' => true];
    }
}
