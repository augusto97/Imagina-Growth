<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Models\Plan;
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
 * - `connections.site`: estado de la web para los «bloqueos que enseñan» del panel (docs/diseno/rediseno-v2.md §3):
 *   `connected` (algún sitio conectado o con fallas), `disconnected` (se conectó alguna vez y hoy no: sus datos
 *   siguen), `pending` (conexión empezada sin terminar) o `none`.
 * - `connections.channels`: los canales con una cuenta en uso (activa, en pausa o con error), sin repetir.
 * - `upgrade_plans`: para cada módulo fuera del plan, el nombre del plan público más barato que lo incluye (del
 *   mismo tipo, tienda o servicios, cuando lo hay), para decir «disponible en el plan X».
 *
 * Calcula todo en pocas consultas para cualquier cantidad de espacios de trabajo (una agencia ve decenas en /api/me).
 *
 * @phpstan-type Features array{modules: list<string>, capabilities: array{store: bool|null, services: bool|null}, connections: array{site: string, channels: list<string>}, upgrade_plans: array<string, string>}
 */
final class WorkspaceFeatures
{
    /** Industrias de `workspace.industries` que venden servicios, no productos. */
    public const SERVICE_INDUSTRIES = ['servicios_profesionales', 'salud_bienestar', 'educacion'];

    public function __construct(private readonly Entitlements $entitlements) {}

    /** @return Features */
    public function for(Workspace $workspace): array
    {
        return $this->forMany(collect([$workspace]))[$workspace->id];
    }

    /**
     * @param  Collection<int, Workspace>  $workspaces
     * @return array<string, Features>
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
        $siteState = $this->siteStates($ids);
        $channels = [];
        foreach (DB::table('channel_accounts')->whereIn('workspace_id', $ids)->whereIn('status', ['active', 'paused', 'error'])
            ->select('workspace_id', 'channel')->distinct()->orderBy('channel')->get() as $row) {
            $channels[(string) $row->workspace_id][] = (string) $row->channel;
        }
        $catalog = null;

        $out = [];
        foreach ($workspaces as $ws) {
            $sites = $platforms[$ws->id] ?? [];
            $store = $sites === [] ? null : ($sites['woocommerce'] ?? 0) > 0;
            $servicesSignal = ($planMetric[(string) $ws->account_id] ?? null) === 'contacts'
                || in_array($ws->getAttribute('industry'), self::SERVICE_INDUSTRIES, true)
                || ($sites['wordpress'] ?? 0) > 0
                || isset($usesServices[$ws->id]);

            $allowed = array_values(array_filter($modules, fn (string $m) => $this->entitlements->allows($ws, $m)));
            $missing = array_values(array_diff($modules, $allowed));
            if ($missing !== [] && $catalog === null) {
                $catalog = $this->publicPlans();
            }
            $out[$ws->id] = [
                'modules' => $allowed,
                'capabilities' => [
                    'store' => $store,
                    'services' => $servicesSignal ? true : ($store === true ? false : null),
                ],
                'connections' => [
                    'site' => $siteState[$ws->id] ?? 'none',
                    'channels' => $channels[$ws->id] ?? [],
                ],
                'upgrade_plans' => $missing === [] ? [] : $this->upgradePlans($missing, $catalog ?? [], $planMetric[(string) $ws->account_id] ?? null),
            ];
        }

        return $out;
    }

    /**
     * Estado de la web de cada espacio de trabajo en una sola consulta.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function siteStates(array $ids): array
    {
        $out = [];
        foreach (DB::table('sites')->whereIn('workspace_id', $ids)
            ->selectRaw("workspace_id, SUM(status IN ('connected', 'degraded')) AS live, SUM(connected_at IS NOT NULL) AS ever, SUM(status = 'pending') AS pending")
            ->groupBy('workspace_id')->get() as $row) {
            $out[(string) $row->workspace_id] = match (true) {
                (int) $row->live > 0 => 'connected',
                (int) $row->ever > 0 => 'disconnected',
                (int) $row->pending > 0 => 'pending',
                default => 'none',
            };
        }

        return $out;
    }

    /**
     * Planes públicos y vigentes, del más barato al más caro (los editados en el panel; sin tabla, los de la configuración).
     *
     * @return list<array{name: string, metric: string, modules: list<string>}>
     */
    private function publicPlans(): array
    {
        $rows = Plan::query()->where('public', true)->whereNull('archived_at')->orderBy('price_usd')->get(['name', 'metric', 'modules']);
        if ($rows->isNotEmpty()) {
            return array_values($rows->map(fn (Plan $p) => [
                'name' => (string) $p->name,
                'metric' => (string) $p->metric,
                'modules' => array_values(array_map('strval', (array) $p->modules)),
            ])->all());
        }
        $plans = [];
        foreach ((array) config('igr-plans.plans', []) as $def) {
            if (! is_array($def) || ! ($def['public'] ?? true)) {
                continue;
            }
            $plans[] = ['price' => (float) ($def['price_usd'] ?? 0), 'name' => (string) ($def['name'] ?? ''), 'metric' => (string) ($def['metric'] ?? 'orders'), 'modules' => array_values(array_map('strval', (array) ($def['modules'] ?? [])))];
        }
        usort($plans, fn (array $a, array $b) => $a['price'] <=> $b['price']);

        return array_values(array_map(fn (array $p) => ['name' => $p['name'], 'metric' => $p['metric'], 'modules' => $p['modules']], $plans));
    }

    /**
     * @param  list<string>  $missing
     * @param  list<array{name: string, metric: string, modules: list<string>}>  $plans
     * @return array<string, string>
     */
    private function upgradePlans(array $missing, array $plans, ?string $metric): array
    {
        $out = [];
        foreach ($missing as $module) {
            $with = array_values(array_filter($plans, fn (array $p) => in_array($module, $p['modules'], true)));
            $same = array_values(array_filter($with, fn (array $p) => $metric === null || $p['metric'] === $metric));
            $plan = $same[0] ?? $with[0] ?? null;
            if ($plan !== null) {
                $out[$module] = $plan['name'];
            }
        }

        return $out;
    }
}
