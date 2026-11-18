<?php

declare(strict_types=1);

namespace App\Domain\Api\Support;

/**
 * Alcances de los tokens de la API pública y del servidor MCP (M19, docs/api-publica.md).
 * Un token solo puede lo que dicen sus alcances; `contacts:pii` es el único que deja ver
 * correos y teléfonos completos.
 */
final class Scopes
{
    public const ALL = [
        'contacts:read', 'contacts:write', 'contacts:pii', 'events:write', 'orders:read',
        'segments:read', 'campaigns:write', 'leads:read', 'leads:write', 'webhooks:manage',
        'analytics:read', 'mcp',
    ];

    /** Lo que se marca por defecto al crear un token (solo lectura sin datos personales). */
    public const DEFAULT = ['contacts:read', 'orders:read', 'segments:read', 'analytics:read'];

    /**
     * @param  array<mixed>  $scopes
     * @return list<string>
     */
    public static function clean(array $scopes): array
    {
        return array_values(array_intersect(self::ALL, array_map('strval', $scopes)));
    }
}
