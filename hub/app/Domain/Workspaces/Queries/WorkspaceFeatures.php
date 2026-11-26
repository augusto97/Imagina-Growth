<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Queries;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanEntitlements;
use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Support\Verticals;
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
 * - `connections.site`: estado de la web para los «bloqueos que enseñan» del panel (docs/diseno/rediseno-v2.md §3):
 *   `connected` (algún sitio conectado o con fallas), `disconnected` (se conectó alguna vez y hoy no: sus datos
 *   siguen), `pending` (conexión empezada sin terminar) o `none`.
 * - `connections.channels`: los canales con una cuenta en uso (activa, en pausa o con error), sin repetir. Un canal de
 *   conversación (WhatsApp, Messenger, Instagram) desconectado que ya tiene conversaciones también cuenta: como la web
 *   desconectada, su historial sigue y la bandeja se abre con el aviso para reconectarlo.
 * - `upgrade_plans`: para cada módulo fuera del plan, el nombre del plan público más barato que lo incluye (del
 *   mismo tipo, tienda o servicios, cuando lo hay), para decir «disponible en el plan X».
 * - `business_type`: `store`, `services` o `both` para que el menú oculte lo que no aplica (rediseño v2 §11.1): lo
 *   elegido en el asistente; si no, lo que implica la vertical guardada; si no, lo que dicen los sitios y el uso
 *   (`capabilities`); null si todavía no hay ninguna señal (el menú muestra todo).
 *
 * Calcula todo en pocas consultas para cualquier cantidad de espacios de trabajo (una agencia ve decenas en /api/me).
 *
 * @phpstan-type Features array{modules: list<string>, capabilities: array{store: bool|null, services: bool|null}, connections: array{site: string, channels: list<string>}, upgrade_plans: array<string, string>, business_type: string|null}
 */
final class WorkspaceFeatures
{
    /** Industrias de `workspace.industries` que venden servicios, no productos. */
    public const SERVICE_INDUSTRIES = ['servicios_profesionales', 'salud_bienestar', 'educacion'];

    /** Canales que conversan en la bandeja (el correo y el SMS no). */
    public const CONVERSATION_CHANNELS = ['whatsapp', 'messenger', 'instagram'];

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
        $siteState = $this->siteStates($ids);
        $channels = [];
        foreach (DB::table('channel_accounts')->whereIn('workspace_id', $ids)
            ->where(fn ($q) => $q->whereIn('status', ['active', 'paused', 'error'])
                // Un canal de conversación que se desconectó conserva su historial: la bandeja se puede abrir (y avisa).
                ->orWhere(fn ($q) => $q->where('status', 'disconnected')->whereIn('channel', self::CONVERSATION_CHANNELS)
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('conversations')->whereColumn('conversations.channel_account_id', 'channel_accounts.id'))))
            ->select('workspace_id', 'channel')->distinct()->orderBy('channel')->get() as $row) {
            $channels[(string) $row->workspace_id][] = (string) $row->channel;
        }
        $catalog = null;
        $declared = $this->declaredBusinessTypes($ids);

        $out = [];
        foreach ($workspaces as $ws) {
            $sites = $platforms[$ws->id] ?? [];
            $store = $sites === [] ? null : ($sites['woocommerce'] ?? 0) > 0;
            $servicesSignal = ($planMetric[(string) $ws->account_id] ?? null) === 'contacts'
                || in_array($ws->getAttribute('industry'), self::SERVICE_INDUSTRIES, true)
                || ($sites['wordpress'] ?? 0) > 0
                || isset($usesServices[$ws->id]);

            $scope = $stored === null ? null : Feature::serializeScope($ws);
            $allowed = array_values(array_filter($modules, fn (string $m) => $scope !== null && isset($stored[$scope][$m])
                ? $stored[$scope][$m]
                : $this->entitlements->allows($ws, $m)));
            $missing = array_values(array_diff($modules, $allowed));
            if ($missing !== [] && $catalog === null) {
                $catalog = $this->publicPlans();
            }
            $services = $servicesSignal ? true : ($store === true ? false : null);
            $vertical = $ws->getAttribute('vertical');
            $out[$ws->id] = [
                'modules' => $allowed,
                'capabilities' => [
                    'store' => $store,
                    'services' => $services,
                ],
                'business_type' => $declared[$ws->id]
                    ?? (is_string($vertical) && Verticals::valid($vertical) ? Verticals::businessType($vertical) : null)
                    ?? match (true) {
                        $store === true && $services === true => 'both',
                        $services === true => 'services',
                        $store === true => 'store',
                        default => null,
                    },
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
     * El tipo de negocio elegido en el asistente (`workspace_onboarding.business_type`), en una sola consulta.
     *
     * @param  array<int, string>  $ids
     * @return array<string, string>
     */
    private function declaredBusinessTypes(array $ids): array
    {
        $rows = DB::table('workspace_onboarding')->whereIn('workspace_id', $ids)->whereNotNull('business_type')->pluck('business_type', 'workspace_id');
        $out = [];
        foreach ($rows as $workspaceId => $type) {
            if (in_array($type, WorkspaceOnboarding::BUSINESS_TYPES, true)) {
                $out[(string) $workspaceId] = (string) $type;
            }
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
