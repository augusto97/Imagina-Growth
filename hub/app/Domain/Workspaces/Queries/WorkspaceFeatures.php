<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanEntitlements;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Drivers\DatabaseDriver;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * Lo que el menú del panel necesita saber de cada espacio de trabajo (docs/auditoria-2026-11.md U2):
 *
 * - `modules`: los módulos del plan que están activos (M21, `Entitlements::allows`).
 * - `capabilities.store`: tiene una tienda WooCommerce conectada (true), solo sitios WordPress (false) o
 *   todavía ningún sitio (null).
 * - `capabilities.services`: vende servicios (true) si el plan se mide por contactos, la industria es de
 *   servicios, tiene un sitio sin WooCommerce o ya usa leads, agenda o cotizador; solo tienda (false) si tiene
 *   WooCommerce y nada de lo anterior; sin datos (null) si todavía no hay sitios.
 *
 * Calcula todo en pocas consultas para cualquier cantidad de espacios de trabajo (una agencia ve decenas en /api/me).
 */
final class WorkspaceFeatures
{
    /** Industrias de `workspace.industries` que venden servicios, no productos. */
    public const SERVICE_INDUSTRIES = ['servicios_profesionales', 'salud_bienestar', 'educacion'];

    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return array{modules: list<string>, capabilities: array{store: bool|null, services: bool|null}} */
    public function for(Workspace $workspace): array
    {
        return $this->forMany(collect([$workspace]))[$workspace->id];
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return array<string, array{modules: list<string>, capabilities: array{store: bool|null, services: bool|null}}>
     */
    public function forMany(Collection $workspaces): array
    {
        if ($workspaces->isEmpty()) {
            return [];
        }
        $ids = $workspaces->map(fn (Workspace $w) => $w->id)->values()->all();
        $modules = PlanCatalog::modules();
        $stored = $this->storedModules($workspaces, $modules);

        $platforms = [];
        foreach (DB::table('sites')->whereIn('workspace_id', $ids)->where('status', '!=', 'disconnected')
            ->selectRaw('workspace_id, platform, COUNT(*) AS n')->groupBy('workspace_id', 'platform')->get() as $row) {
            $platforms[(string) $row->workspace_id][(string) $row->platform] = (int) $row->n;
        }
        $usesServices = array_flip(array_map('strval', array_merge(
            DB::table('leads')->whereIn('workspace_id', $ids)->distinct()->pluck('workspace_id')->all(),
            DB::table('booking_services')->whereIn('workspace_id', $ids)->distinct()->pluck('workspace_id')->all(),
            DB::table('quote_calculators')->whereIn('workspace_id', $ids)->distinct()->pluck('workspace_id')->all(),
        )));
        $planMetric = DB::table('accounts')->join('plans', 'plans.id', '=', 'accounts.plan_id')
            ->whereIn('accounts.id', $workspaces->pluck('account_id')->unique()->all())
            ->pluck('plans.metric', 'accounts.id')->map(fn ($m) => (string) $m)->all();

        $out = [];
        foreach ($workspaces as $ws) {
            $sites = $platforms[$ws->id] ?? [];
            $store = $sites === [] ? null : ($sites['woocommerce'] ?? 0) > 0;
            $servicesSignal = ($planMetric[(string) $ws->account_id] ?? null) === 'contacts'
                || in_array($ws->getAttribute('industry'), self::SERVICE_INDUSTRIES, true)
                || ($sites['wordpress'] ?? 0) > 0
                || isset($usesServices[$ws->id]);

            $scope = $stored === null ? null : Feature::serializeScope($ws);
            $out[$ws->id] = [
                'modules' => array_values(array_filter($modules, fn (string $m) => $scope !== null && isset($stored[$scope][$m])
                    ? $stored[$scope][$m]
                    : $this->entitlements->allows($ws, $m))),
                'capabilities' => [
                    'store' => $store,
                    'services' => $servicesSignal ? true : ($store === true ? false : null),
                ],
            ];
        }

        return $out;
    }

    /**
     * Los módulos ya guardados por Pennant para todos los espacios, con una consulta a su tabla. Pennant guarda en
     * memoria cada par (módulo, espacio) en una lista que recorre entera en cada lectura: con 40 espacios × 14 módulos
     * eso eran ~500 ms de CPU en cada /api/me (docs/rendimiento.md §2). Lo que falta (un espacio nuevo, un módulo
     * olvidado tras un cambio de plan) sigue por `Entitlements::allows`, que lo resuelve y lo guarda como siempre.
     * Sin el almacén de base de datos de Pennant o con otro Entitlements devuelve null y todo va por `allows`.
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @param  list<string>  $modules
     * @return array<string, array<string, bool>>|null scope serializado → módulo → activo
     */
    private function storedModules(Collection $workspaces, array $modules): ?array
    {
        $store = (string) config('pennant.default');
        try {
            if (! $this->entitlements instanceof PlanEntitlements || ! Feature::store($store)->getDriver() instanceof DatabaseDriver) {
                return null;
            }
            $names = [];
            foreach ($modules as $module) {
                $names[PlanResolver::feature($module)] = $module;
            }
            $rows = DB::connection(config("pennant.stores.{$store}.connection"))->table((string) (config("pennant.stores.{$store}.table") ?? 'features'))
                ->whereIn('name', array_keys($names))
                ->whereIn('scope', $workspaces->map(fn (Workspace $w) => Feature::serializeScope($w))->values()->all())
                ->get(['name', 'scope', 'value']);
        } catch (Throwable) {
            return null;
        }
        $stored = [];
        foreach ($rows as $row) {
            // Como Pennant: activo es cualquier valor distinto de false.
            $stored[(string) $row->scope][$names[(string) $row->name]] = json_decode((string) $row->value, true) !== false;
        }
        // Los espacios con módulos sin guardar se resuelven juntos (una consulta y una inserción), no uno por uno.
        $missing = $workspaces->filter(fn (Workspace $w) => count($stored[Feature::serializeScope($w)] ?? []) < count($modules))->values();
        if ($missing->isNotEmpty()) {
            try {
                Feature::for($missing->all())->loadMissing(array_keys($names));
            } catch (Throwable) {
                // `allows()` los resuelve uno por uno.
            }
        }

        return $stored;
    }
}
