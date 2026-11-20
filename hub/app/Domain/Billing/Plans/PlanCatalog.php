<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Models\Plan;

/** Los planes de `config/igr-plans.php` en la tabla `plans` (idempotente). */
final class PlanCatalog
{
    /** @return list<string> */
    public static function modules(): array
    {
        return array_values(array_map('strval', (array) config('igr-plans.modules', [])));
    }

    /**
     * Carga los planes de config/igr-plans.php. Con $overwrite = false solo crea los que faltan: los planes se
     * editan en el panel de la plataforma y la configuración no debe pisar esos cambios.
     *
     * @return int planes sincronizados
     */
    public function sync(bool $overwrite = true): int
    {
        $count = 0;
        foreach ((array) config('igr-plans.plans', []) as $id => $def) {
            $def = (array) $def;
            if (! $overwrite && Plan::query()->whereKey((string) $id)->exists()) {
                continue;
            }
            Plan::query()->updateOrCreate(['id' => (string) $id], [
                'name' => (string) $def['name'],
                'metric' => in_array($def['metric'] ?? 'orders', ['orders', 'contacts'], true) ? (string) $def['metric'] : 'orders',
                'included' => (int) $def['included'],
                'modules' => array_values(array_intersect((array) ($def['modules'] ?? []), self::modules())),
                'limits' => (array) ($def['limits'] ?? []),
                'price_usd' => (string) ($def['price_usd'] ?? 0),
                'price_cop' => isset($def['price_cop']) ? (string) $def['price_cop'] : null,
                'public' => (bool) ($def['public'] ?? true),
            ]);
            $count++;
        }

        return $count;
    }
}
