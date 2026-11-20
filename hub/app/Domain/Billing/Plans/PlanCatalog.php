<?php

declare(strict_types=1);

namespace App\Domain\Billing\Plans;

use App\Domain\Billing\Models\Plan;

/** Los planes de `config/igr-plans.php` en la tabla `plans` (idempotente). */
final class PlanCatalog
{
    /** Plan de una cuenta sin plan cuando la configuración no dice otro (S3). */
    public const DEFAULT_PLAN = 'diagnostico';

    /** Valor de `igr-plans.default_plan` que deja «sin plan = todo permitido» (solo desarrollo y pruebas). */
    public const UNRESTRICTED = 'none';

    /** @return list<string> */
    public static function modules(): array
    {
        return array_values(array_map('strval', (array) config('igr-plans.modules', [])));
    }

    /** Clave del plan por defecto (`igr-plans.default_plan`), o null si está en `none`. */
    public static function defaultPlanId(): ?string
    {
        $id = trim((string) config('igr-plans.default_plan', self::DEFAULT_PLAN));
        if ($id === self::UNRESTRICTED) {
            return null;
        }

        return $id !== '' ? $id : self::DEFAULT_PLAN;
    }

    public static function platformPlanId(): string
    {
        $id = trim((string) config('igr-plans.platform_plan', 'plataforma'));

        return $id !== '' ? $id : 'plataforma';
    }

    /**
     * El plan guardado o, si falta en la tabla, el de la configuración sin guardar. Si tampoco está en la
     * configuración, el mínimo absoluto: ningún módulo, sin correo de la plataforma y sin IA.
     */
    public static function findOrDefinition(string $id): Plan
    {
        $plan = Plan::query()->find($id);
        if ($plan !== null) {
            return $plan;
        }
        $def = config("igr-plans.plans.{$id}");
        $plan = new Plan;
        $plan->forceFill(['id' => $id] + (is_array($def) ? self::attributes($def) : [
            'name' => 'Diagnóstico', 'metric' => 'orders', 'included' => 0, 'modules' => [],
            'limits' => ['emails' => 0, 'ai_budget_usd' => 0], 'price_usd' => '0', 'price_cop' => null, 'public' => false,
        ]));

        return $plan;
    }

    /** Crea el plan desde la configuración si falta en la tabla (no pisa uno editado). */
    public static function ensure(string $id): bool
    {
        if (Plan::query()->whereKey($id)->exists()) {
            return true;
        }
        $def = config("igr-plans.plans.{$id}");
        if (! is_array($def)) {
            return false;
        }
        Plan::query()->create(['id' => $id] + self::attributes($def));

        return true;
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
            if (! $overwrite && Plan::query()->whereKey((string) $id)->exists()) {
                continue;
            }
            Plan::query()->updateOrCreate(['id' => (string) $id], self::attributes((array) $def));
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<mixed>  $def
     * @return array<string, mixed>
     */
    private static function attributes(array $def): array
    {
        return [
            'name' => (string) $def['name'],
            'metric' => in_array($def['metric'] ?? 'orders', ['orders', 'contacts'], true) ? (string) $def['metric'] : 'orders',
            'included' => (int) $def['included'],
            'modules' => array_values(array_intersect((array) ($def['modules'] ?? []), self::modules())),
            'limits' => (array) ($def['limits'] ?? []),
            'price_usd' => (string) ($def['price_usd'] ?? 0),
            'price_cop' => isset($def['price_cop']) ? (string) $def['price_cop'] : null,
            'public' => (bool) ($def['public'] ?? true),
        ];
    }
}
