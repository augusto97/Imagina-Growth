<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        try {
            // Pennant en una sola consulta para todos; después `allows()` lee de su memoria.
            Feature::for($workspaces->all())->loadMissing(array_map(fn (string $m) => PlanResolver::feature($m), $modules));
        } catch (Throwable) {
            // Sin Pennant (otro Entitlements): `allows()` responde igual, uno por uno.
        }

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

            $out[$ws->id] = [
                'modules' => array_values(array_filter($modules, fn (string $m) => $this->entitlements->allows($ws, $m))),
                'capabilities' => [
                    'store' => $store,
                    'services' => $servicesSignal ? true : ($store === true ? false : null),
                ],
            ];
        }

        return $out;
    }
}
