<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

/**
 * Qué modelo y qué esfuerzo usa cada función de IA (inteligencia.md §6.1). Manda, en este orden:
 *
 * 1. lo elegido para esa función en Plataforma → Ajustes → IA → Avanzado (`igr-ai.overrides.<función>`);
 * 2. el modelo y el esfuerzo de su grupo, «principal» o «rápido» (`igr-ai.tiers`, del panel o del .env);
 * 3. lo de la función en `igr-ai.features` (los valores de fábrica).
 *
 * Un esfuerzo que el modelo elegido no admite no se manda (el modelo usa el suyo por defecto), así cambiar de modelo
 * nunca rompe una función. Todo se lee de `config()` en cada llamada: un cambio del panel rige sin reiniciar.
 */
final class AiModels
{
    public const TIERS = ['main', 'fast'];

    public const EFFORTS = ['low', 'medium', 'high', 'xhigh', 'max'];

    /**
     * @return array{model: string, effort: string|null, max_tokens: int, tier: string, source: 'override'|'tier'|'default'}
     */
    public static function resolve(string $feature): array
    {
        $defaults = (array) config("igr-ai.features.{$feature}", []);
        $tier = in_array($defaults['tier'] ?? null, self::TIERS, true) ? (string) $defaults['tier'] : 'main';
        $override = (array) config("igr-ai.overrides.{$feature}", []);
        $group = (array) config("igr-ai.tiers.{$tier}", []);

        $model = match (true) {
            self::filled($override['model'] ?? null) => (string) $override['model'],
            self::filled($group['model'] ?? null) => (string) $group['model'],
            default => (string) ($defaults['model'] ?? 'claude-sonnet-5-5'),
        };
        $effort = match (true) {
            self::filled($override['effort'] ?? null) => (string) $override['effort'],
            self::filled($group['effort'] ?? null) => (string) $group['effort'],
            self::filled($defaults['effort'] ?? null) => (string) $defaults['effort'],
            default => null,
        };
        if ($effort !== null && ! self::supportsEffort($model, $effort)) {
            $effort = null;
        }
        // De dónde sale lo que rige (para mostrarlo en el panel): de la función, de su grupo o de fábrica.
        $origin = match (true) {
            self::filled($override['model'] ?? null) || self::filled($override['effort'] ?? null) => 'override',
            self::filled($group['model'] ?? null) || self::filled($group['effort'] ?? null) => 'tier',
            default => 'default',
        };

        return ['model' => $model, 'effort' => $effort, 'max_tokens' => (int) ($defaults['max_tokens'] ?? 4000), 'tier' => $tier, 'source' => $origin];
    }

    /** @return array<string, array{efforts: list<string>, default_effort: string|null, fallbacks: bool}> */
    public static function catalog(): array
    {
        $out = [];
        foreach ((array) config('igr-ai.catalog', []) as $id => $entry) {
            $entry = (array) $entry;
            $out[(string) $id] = [
                'efforts' => array_values(array_intersect(self::EFFORTS, (array) ($entry['efforts'] ?? []))),
                'default_effort' => isset($entry['default_effort']) ? (string) $entry['default_effort'] : null,
                'fallbacks' => (bool) ($entry['fallbacks'] ?? true),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_keys(self::catalog());
    }

    /**
     * Si el modelo admite ese esfuerzo. Un modelo fuera del catálogo (puesto en el .env) se deja pasar: el proveedor
     * dirá si no.
     */
    public static function supportsEffort(string $model, string $effort): bool
    {
        $entry = self::catalog()[$model] ?? null;

        return $entry === null ? in_array($effort, self::EFFORTS, true) : in_array($effort, $entry['efforts'], true);
    }

    /** Si al modelo se le pide el respaldo del lado del servidor cuando se niega por política. */
    public static function serverFallbacks(string $model): bool
    {
        return (bool) config('igr-ai.server_fallbacks', true) && (self::catalog()[$model]['fallbacks'] ?? true);
    }

    /**
     * Las funciones con su grupo, en el orden de `igr-ai.features`.
     *
     * @return array<string, string> función => grupo
     */
    public static function features(): array
    {
        $out = [];
        foreach ((array) config('igr-ai.features', []) as $feature => $defaults) {
            $tier = ((array) $defaults)['tier'] ?? 'main';
            $out[(string) $feature] = in_array($tier, self::TIERS, true) ? (string) $tier : 'main';
        }

        return $out;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }
}
