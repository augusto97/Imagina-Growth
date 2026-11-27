<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Domain\Strategies\StrategyRegistry;
use RuntimeException;

/**
 * Las pantallas a las que «Pregúntale a la IA» puede llevar (inteligencia.md §6.3): `resources/assistant/pages.json`,
 * clave → ruta dentro de `/w/{espacio}/`. La misma lista la usa la SPA para el texto del botón, y una prueba revisa que
 * cada ruta exista en el router: el modelo elige claves de esta lista (enum del esquema) y nunca escribe una URL.
 * `strategy` lleva `ref` = la clave de una estrategia que existe.
 */
final class AssistantPages
{
    /** @var array<string, string>|null */
    private static ?array $pages = null;

    /** @return array<string, string> clave → ruta (sin la barra del principio; puede llevar `?…` y `{ref}`) */
    public static function all(): array
    {
        if (self::$pages === null) {
            $raw = file_get_contents(resource_path('assistant/pages.json'));
            $pages = is_string($raw) ? json_decode($raw, true) : null;
            if (! is_array($pages)) {
                throw new RuntimeException('resources/assistant/pages.json no es un JSON válido.');
            }
            self::$pages = array_map(fn ($p) => (string) $p, array_filter($pages, fn ($p, $k) => is_string($k) && is_string($p), ARRAY_FILTER_USE_BOTH));
        }

        return self::$pages;
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * La ruta de una pantalla, o null si la clave no existe o le falta (o sobra) la referencia.
     */
    public static function path(string $key, ?string $ref = null): ?string
    {
        $path = self::all()[$key] ?? null;
        if ($path === null) {
            return null;
        }
        if (! str_contains($path, '{ref}')) {
            return $path;
        }
        $ref = trim((string) $ref);
        if ($key === 'strategy' && ($ref === '' || StrategyRegistry::find($ref) === null)) {
            return null;
        }

        return str_replace('{ref}', rawurlencode($ref), $path);
    }

    /**
     * Un paso o una pantalla validada: `{page, ref, path}` o null.
     *
     * @return array{page: string, ref: string|null, path: string}|null
     */
    public static function link(mixed $page, mixed $ref = null): ?array
    {
        if (! is_string($page) || $page === '' || $page === 'none') {
            return null;
        }
        $ref = is_string($ref) && trim($ref) !== '' ? trim($ref) : null;
        $path = self::path($page, $ref);
        if ($path === null) {
            return null;
        }

        return ['page' => $page, 'ref' => str_contains(self::all()[$page], '{ref}') ? $ref : null, 'path' => $path];
    }
}
