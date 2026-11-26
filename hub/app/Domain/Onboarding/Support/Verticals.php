<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Support;

/**
 * Las verticales de config/igr-verticals.php (docs/diseno/rediseno-v2.md §11.1): a qué se dedica el negocio. Se
 * guarda en `workspaces.vertical`; cambia las estrategias recomendadas, los textos y los KPI del Inicio.
 */
final class Verticals
{
    public const STORE = 'store';

    public const OTHER = 'other';

    /** @return list<string> */
    public static function keys(): array
    {
        return array_values(array_map('strval', array_keys((array) config('igr-verticals.verticals', []))));
    }

    public static function valid(?string $vertical): bool
    {
        return $vertical !== null && in_array($vertical, self::keys(), true);
    }

    /** El tipo de negocio que implica la vertical: `store` o `services`. */
    public static function businessType(string $vertical): string
    {
        return (string) config("igr-verticals.verticals.{$vertical}.business_type", 'services') === 'store' ? 'store' : 'services';
    }

    /** La vertical que sugiere la industria del espacio de trabajo, o null. */
    public static function fromIndustry(?string $industry): ?string
    {
        if ($industry === null || $industry === '') {
            return null;
        }
        foreach ((array) config('igr-verticals.verticals', []) as $key => $def) {
            if (in_array($industry, (array) ($def['industries'] ?? []), true)) {
                return (string) $key;
            }
        }

        return null;
    }

    /** @return list<array{key: string, name: string, examples: string, business_type: string}> las opciones del asistente */
    public static function options(): array
    {
        return array_map(fn (string $key) => [
            'key' => $key,
            'name' => (string) __("onboarding.verticals.{$key}.name"),
            'examples' => (string) __("onboarding.verticals.{$key}.examples"),
            'business_type' => self::businessType($key),
        ], self::keys());
    }
}
