<?php

declare(strict_types=1);

namespace App\Domain\Home\Queries;

use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Onboarding\Queries\OnboardingState;
use App\Domain\Services\Leads\Assistant\TodayList;
use App\Domain\Sites\Health\SiteHealth;
use App\Domain\Sites\Models\Site;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Strategies\Support\StrategyPotential;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El Inicio, el tablero del dueño (docs/diseno/rediseno-v2.md §5), en una sola llamada:
 *
 * 1. `money`: el dinero generado del mes y del anterior (MoneyGenerated) con el desglose por estrategia.
 * 2. `potential`: con el dinero en cero o bajo, el potencial más grande de una estrategia sin prender.
 * 3. `today`: hasta 5 acciones priorizadas (configuración pendiente, estrategias con problemas, conversaciones sin
 *    responder, contactos por atender, recomendaciones y la estrategia que más podría generar).
 * 4. `strategies`: las activas con su resultado del mes y las 2 o 3 siguientes sugeridas.
 * 5. `kpis`: ventas, pedidos, clientes nuevos y recurrentes del mes (o contactos y clientes ganados en servicios) con
 *    una serie de 30 días para la minigráfica.
 * 6. `health`: los sitios que no están al día (solo si hay alguno).
 *
 * Lo caro (dinero, KPI, potencial, resultados) va en caché por espacio de trabajo con la versión de la ingesta y la
 * de las estrategias; lo que cambia a cada rato (conversaciones, contactos, recomendaciones, estados) se lee en vivo.
 */
final class HomeOverview
{
    public const TODAY_LIMIT = 5;

    /** Por debajo de esta parte de las ventas, el dinero generado se considera bajo y se muestra el potencial. */
    public const LOW_SHARE_PCT = 2.0;

    private const SEVERITY_PRIORITY = ['critical' => 88, 'warning' => 70, 'opportunity' => 55, 'info' => 30];

    public function __construct(
        private readonly MoneyGenerated $money,
        private readonly StrategyCatalog $catalog,
        private readonly StrategyPotential $potential,
        private readonly MetricQueryRunner $metrics,
        private readonly OnboardingState $onboarding,
        private readonly SiteHealth $health,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, ?User $user): array
    {
        $ctx = StrategyContext::for($workspace);
        $tz = (string) ($workspace->timezone ?: 'America/Bogota');
        $currency = (string) ($workspace->currency ?: 'COP');
        $results = $this->catalog->results($ctx);
        $money = $this->moneyBlock($workspace, $ctx, $results);
        $potentials = $this->potential->forWorkspace($workspace);
        $setup = $this->onboarding->summary($workspace, $ctx);
        $first = trim(explode(' ', trim((string) $user?->name))[0] ?? '');

        return [
            'workspace' => ['name' => $workspace->name, 'currency' => $currency, 'timezone' => $tz],
            'greeting' => ['name' => $first !== '' ? $first : null],
            'period' => ['key' => 'month', 'from' => $results['month']['from'], 'to' => $results['month']['to']],
            'business_type' => $ctx->businessType(),
            'setup' => $setup,
            'money' => $money,
            'potential' => $money['low'] ? $this->topPotential($ctx, $potentials) : null,
            'today' => $this->today($workspace, $ctx, $setup, $potentials, $currency, $money['low']),
            'strategies' => $this->strategies($ctx, $potentials, $results['strategies']),
            'kpis' => $this->kpis($workspace, $ctx, $tz),
            'health' => $this->healthNotices($workspace),
        ];
    }

    /**
     * @param  array{month: array{from: string, to: string}, strategies: array<string, array<string, mixed>>, breakdown: list<array<string, mixed>>}  $results
     * @return array<string, mixed>
     */
    private function moneyBlock(Workspace $workspace, StrategyContext $ctx, array $results): array
    {
        [$from, $to] = MoneyGenerated::month($workspace);
        [$prevFrom, $prevTo] = MoneyGenerated::month($workspace, $from->subDay());
        $month = MetricQueryRunner::remember($workspace, 'home:money:'.$from->toDateString(), fn () => $this->money->forWorkspace($workspace, $from, $to));
        $previous = MetricQueryRunner::remember($workspace, 'home:money:'.$prevFrom->toDateString(), fn () => $this->money->forWorkspace($workspace, $prevFrom, $prevTo));
        $total = (float) $month['total'];
        $prev = (float) $previous['total'];
        $breakdown = [];
        foreach ($results['breakdown'] as $row) {
            if ((float) $row['revenue'] <= 0) {
                continue;
            }
            $key = $row['strategy'];
            $breakdown[] = $row + ['name' => $key !== null ? $this->catalog->texts($key, $ctx)['name'] : __('home.money.buckets.'.$row['bucket'])];
        }

        return [
            'total' => $month['total'],
            'orders' => $month['orders'],
            'store_total' => $month['store_total'],
            'share_pct' => $month['share_pct'],
            'previous' => ['total' => $previous['total'], 'orders' => $previous['orders'], 'share_pct' => $previous['share_pct'], 'from' => $previous['from'], 'to' => $previous['to']],
            'change_pct' => $prev > 0 ? round(($total - $prev) / $prev * 100, 1) : null,
            'breakdown' => $breakdown,
            'low' => $total <= 0 || $month['share_pct'] < self::LOW_SHARE_PCT,
        ];
    }

    /**
     * La estrategia sin prender con más en juego (por el dinero que recuperaría, lo que hay en juego y los casos).
     *
     * @param  array<string, array<string, mixed>|null>  $potentials
     * @return array<string, mixed>|null
     */
    private function topPotential(StrategyContext $ctx, array $potentials): ?array
    {
        $best = null;
        $bestScore = null;
        foreach (StrategyRegistry::all() as $definition) {
            $p = $definition->potential !== null ? ($potentials[$definition->potential] ?? null) : null;
            if ($p === null || ! $ctx->serves($definition)) {
                continue;
            }
            $status = $this->catalog->evaluate($definition, $ctx)['status'];
            if (! in_array($status, ['available', 'needs_setup', 'paused'], true)) {
                continue;
            }
            $score = [(float) ($p['estimated'] ?? 0), (float) ($p['value'] ?? 0), (int) $p['count']];
            if ($bestScore === null || $score > $bestScore) {
                $bestScore = $score;
                $best = ['strategy' => $definition->key, 'name' => $this->catalog->texts($definition->key, $ctx)['name'], 'status' => $status] + $p;
            }
        }

        return $best;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, array<string, mixed>|null>  $potentials
     * @return list<array<string, mixed>>
     */
    private function today(Workspace $workspace, StrategyContext $ctx, array $setup, array $potentials, string $currency, bool $lowMoney): array
    {
        $items = [];
        // Configuración pendiente: lo que bloquea todo lo demás va primero.
        if (! $ctx->hasSite()) {
            $items[] = $this->item('connect_site', 'setup', 100, ($pending = $ctx->pendingSiteId()) !== null ? 'sitios/st_'.$pending : 'sitios?conectar=1');
        } else {
            $import = $ctx->import();
            if ($import['status'] === 'failed' || $import['status'] === 'paused') {
                $items[] = $this->item('import_failed', 'setup', 90, 'sitios/st_'.$import['site_id']);
            } elseif (in_array($import['status'], ['queued', 'running'], true)) {
                $items[] = $this->item('import_running', 'setup', 40, 'sitios/st_'.$import['site_id'], ['progress' => (int) round(((float) ($import['progress'] ?? 0)) * 100)]);
            }
        }
        if ($ctx->hasSite() && ! $ctx->hasChannel('whatsapp', 'email')) {
            $items[] = $this->item('connect_channel', 'setup', 80, 'canales');
        }
        // Estrategias prendidas con un problema.
        foreach (StrategyRegistry::all() as $definition) {
            $eval = $this->catalog->evaluate($definition, $ctx);
            if ($eval['status'] === 'attention') {
                $problem = $eval['state']->problems[0] ?? 'channel_down';
                $items[] = $this->item('strategy_attention', 'strategy', 95, 'estrategias/'.$definition->key,
                    ['name' => $this->catalog->texts($definition->key, $ctx)['name']], ['strategy' => $definition->key, 'why' => __('strategies.problems.'.$problem)]);
            }
        }
        // Conversaciones que esperan respuesta.
        $waiting = DB::table('conversations')->where('workspace_id', $workspace->id)->whereNotNull('awaiting_since')->where('status', '!=', 'closed');
        $count = (clone $waiting)->count();
        if ($count > 0) {
            $oldest = $waiting->min('awaiting_since');
            $items[] = $this->item('reply_conversations', 'conversations', 85, 'bandeja', ['count' => $count], ['count' => $count,
                'since' => $oldest !== null ? CarbonImmutable::parse((string) $oldest, 'UTC')->toIso8601String() : null]);
        }
        // Contactos de servicios por atender hoy (la lista «Hoy» del vendedor).
        if ($ctx->module('services') && DB::table('leads')->where('workspace_id', $workspace->id)->whereNull('won_at')->whereNull('lost_at')->exists()) {
            $counts = app(TodayList::class)->handle($workspace, null)['counts'];
            $n = (int) ($counts['reply'] ?? 0) + (int) ($counts['first_contact'] ?? 0) + (int) ($counts['task'] ?? 0) + (int) ($counts['next_step'] ?? 0);
            if ($n > 0) {
                $items[] = $this->item('contact_leads', 'leads', 82, 'hoy', ['count' => $n], ['count' => $n]);
            }
        }
        // Recomendaciones (hallazgos) abiertas, de la más grave y con más impacto.
        $insights = Insight::query()->whereIn('status', ['new', 'seen'])->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws]);
        $shown = 0;
        foreach ($insights as $insight) {
            $action = (array) ($insight->recommended_action ?? []);
            $strategy = ($action['kind'] ?? null) === 'activate_flow' && is_string($action['payload']['library_key'] ?? null) ? StrategyRegistry::forFlow($action['payload']['library_key']) : null;
            // Un hallazgo que pide prender una estrategia que ya está prendida no es para hoy (se resuelve solo en la noche).
            $definition = $strategy !== null ? StrategyRegistry::find($strategy) : null;
            if ($shown >= 3 || ($definition !== null && in_array($this->catalog->evaluate($definition, $ctx)['status'], ['active', 'attention'], true))) {
                continue;
            }
            $shown++;
            $impact = $insight->estimated_impact_ws !== null ? (float) $insight->estimated_impact_ws : null;
            $items[] = [
                'kind' => 'insight', 'key' => 'insight:'.$insight->publicId(), 'group' => 'insight',
                'title' => (string) $insight->title,
                'why' => $impact !== null && $impact > 0 ? __('home.today.insight.why', ['amount' => Money::format($impact, $currency)]) : __('home.today.insight.why_plain'),
                'action' => is_string($action['label'] ?? null) ? $action['label'] : __('home.today.insight.action'),
                'to' => $strategy !== null ? 'estrategias/'.$strategy : (is_string($action['to'] ?? null) ? $action['to'] : 'hallazgos'),
                'priority' => (self::SEVERITY_PRIORITY[$insight->severity] ?? 30) + ($impact !== null && $impact > 0 ? 5 : 0),
                'strategy' => $strategy, 'insight_id' => $insight->publicId(), 'severity' => $insight->severity, 'amount' => $impact !== null ? number_format($impact, 2, '.', '') : null,
            ];
        }
        // La estrategia que más podría generar, si todavía no hay dinero generado.
        if ($lowMoney && ($top = $this->topPotential($ctx, $potentials)) !== null && $ctx->hasSite()) {
            $items[] = $this->item('activate_strategy', 'strategy', 65, 'estrategias/'.$top['strategy'], ['name' => $top['name']], ['strategy' => $top['strategy'], 'why' => $top['summary']]);
        }

        // Una sola entrada por estrategia (la de mayor prioridad), en orden de prioridad.
        usort($items, fn (array $a, array $b) => $b['priority'] <=> $a['priority']);
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $strategy = $item['strategy'] ?? null;
            if ($strategy !== null && isset($seen[$strategy])) {
                continue;
            }
            if ($strategy !== null) {
                $seen[$strategy] = true;
            }
            $out[] = $item;
            if (count($out) >= self::TODAY_LIMIT) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, int|string>  $params  marcadores de los textos de `home.today.<kind>`
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function item(string $kind, string $group, int $priority, string $to, array $params = [], array $extra = []): array
    {
        $count = (int) ($params['count'] ?? 1);
        $why = $extra['why'] ?? __('home.today.'.$kind.'.why', $params);
        unset($extra['why']);

        return array_replace([
            'kind' => $kind, 'key' => $kind.(isset($extra['strategy']) ? ':'.$extra['strategy'] : ''), 'group' => $group,
            'title' => trans_choice('home.today.'.$kind.'.title', $count, $params),
            'why' => $why,
            'action' => __('home.today.'.$kind.'.action'),
            'to' => $to,
            'priority' => $priority,
            'strategy' => null,
        ], $extra);
    }

    /**
     * @param  array<string, array<string, mixed>|null>  $potentials
     * @param  array<string, array<string, mixed>>  $results
     * @return array{active: list<array<string, mixed>>, suggested: list<array<string, mixed>>}
     */
    private function strategies(StrategyContext $ctx, array $potentials, array $results): array
    {
        $active = [];
        foreach (StrategyRegistry::all() as $definition) {
            if (in_array($this->catalog->evaluate($definition, $ctx)['status'], ['active', 'attention'], true)) {
                $active[] = $this->catalog->present($definition, $ctx, $potentials, $results);
            }
        }
        usort($active, fn (array $a, array $b) => (float) ($b['result']['revenue'] ?? 0) <=> (float) ($a['result']['revenue'] ?? 0));
        $suggested = [];
        foreach ($this->catalog->suggestions($ctx, 3) as $key) {
            $definition = StrategyRegistry::find($key);
            if ($definition !== null) {
                $suggested[] = $this->catalog->present($definition, $ctx, $potentials, $results, true);
            }
        }

        return ['active' => $active, 'suggested' => $suggested];
    }

    /**
     * «Tu negocio»: los KPI que aplican según el tipo de negocio (`applicable`, en orden, con su unidad y su ayuda en
     * `definitions`): una tienda ve ventas, pedidos y clientes; un negocio sin tienda, contactos, primera respuesta,
     * citas, tasa de cierre y valor ganado (nada de pedidos ni carritos, §11.2); «tienda + servicios», los dos grupos.
     *
     * @return array<string, mixed>
     */
    private function kpis(Workspace $workspace, StrategyContext $ctx, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $month = Period::make($tz, $now->startOfMonth()->toDateString(), $now->toDateString(), 'previous', 'day');
        $series = Period::make($tz, $now->subDays(29)->toDateString(), $now->toDateString(), 'none', 'day');
        $type = $ctx->businessType();
        $parts = [];
        if ($type === 'store' || $type === 'both') {
            $parts[] = $this->storeKpis($workspace, $month, $series);
        }
        if ($type === 'services' || $type === 'both') {
            $parts[] = MetricQueryRunner::remember($workspace, 'home:kpis:services:'.$now->toDateString(), fn () => $this->servicesKpis($workspace, $month, $series));
        }
        $applicable = [];
        $metrics = [];
        $seriesKeys = [];
        $points = [];
        $sources = [];
        $available = true;
        foreach ($parts as $part) {
            $available = $available && $part['available'];
            $applicable = [...$applicable, ...array_keys($part['units'])];
            $metrics += $part['metrics'];
            $seriesKeys = [...$seriesKeys, ...$part['series_keys']];
            foreach ($part['series'] as $i => $point) {
                $points[$i] = ($points[$i] ?? []) + $point;
            }
            $sources = [...$sources, ...$part['lead_sources']];
        }
        $definitions = [];
        foreach ($parts as $part) {
            foreach ($part['units'] as $key => $unit) {
                $definitions[$key] = ['unit' => $unit, 'label' => __('home.kpis.'.$key.'.label'), 'help' => __('home.kpis.'.$key.'.help')];
            }
        }

        return [
            'kind' => $type, 'available' => $available, 'period' => $month->toArray(),
            'applicable' => $applicable, 'definitions' => $definitions, 'metrics' => $metrics,
            'series_keys' => $seriesKeys, 'series' => array_values($points), 'lead_sources' => $sources,
        ];
    }

    /**
     * Ventas, pedidos y clientes nuevos y recurrentes de la capa semántica (en caché por la ingesta).
     *
     * @return array{available: bool, units: array<string, string>, metrics: array<string, array{value: float|null, previous: float|null, change_pct: float|null}>, series_keys: list<string>, series: list<array<string, float|string>>, lead_sources: list<array<string, mixed>>}
     */
    private function storeKpis(Workspace $workspace, Period $month, Period $series): array
    {
        $units = ['revenue' => 'money', 'orders' => 'count', 'new_customers' => 'count', 'returning_customers' => 'count'];
        $names = array_keys($units);
        try {
            $totals = $this->metrics->run($workspace, new MetricQuery($names, [], $month));
            $rows = $this->metrics->run($workspace, new MetricQuery($names, ['date'], $series))['rows'];
        } catch (Throwable $e) {
            report($e);

            return ['available' => false, 'units' => $units, 'metrics' => [], 'series_keys' => ['revenue', 'orders'], 'series' => self::fill($series, [], ['revenue', 'orders']), 'lead_sources' => []];
        }
        $metrics = [];
        foreach ($names as $name) {
            $metrics[$name] = self::kpi((float) ($totals['totals'][$name] ?? 0), $totals['compare_totals'] !== null ? (float) ($totals['compare_totals'][$name] ?? 0) : null);
        }

        return ['available' => true, 'units' => $units, 'metrics' => $metrics, 'series_keys' => $names, 'series' => self::fill($series, $rows, $names), 'lead_sources' => []];
    }

    /**
     * Negocio sin tienda: contactos nuevos y de dónde vienen, tiempo de primera respuesta (mediana en minutos), citas
     * agendadas y cumplidas, tasa de cierre (ganados ÷ cerrados del periodo) y valor ganado.
     *
     * @return array{available: bool, units: array<string, string>, metrics: array<string, array{value: float|null, previous: float|null, change_pct: float|null}>, series_keys: list<string>, series: list<array<string, float|string>>, lead_sources: list<array<string, mixed>>}
     */
    private function servicesKpis(Workspace $workspace, Period $month, Period $series): array
    {
        $ws = $workspace->id;
        $units = ['leads' => 'count', 'first_response_minutes' => 'minutes', 'appointments_booked' => 'count', 'appointments_attended' => 'count', 'close_rate' => 'percent', 'won_value' => 'money'];
        $measure = function (Period $p) use ($ws): array {
            $range = [$p->from->utc(), $p->to->utc()];
            $leads = DB::table('leads')->where('workspace_id', $ws)->whereBetween('created_at', $range);
            $seconds = array_values(array_map('floatval', (clone $leads)->whereNotNull('first_response_at')
                ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, first_response_at) AS s')->limit(5000)->pluck('s')->all()));
            $won = DB::table('leads')->where('workspace_id', $ws)->whereBetween('won_at', $range);
            $wonCount = (clone $won)->count();
            $closed = $wonCount + DB::table('leads')->where('workspace_id', $ws)->whereBetween('lost_at', $range)->count();
            $appointments = DB::table('appointments')->where('workspace_id', $ws);

            return [
                'leads' => (float) (clone $leads)->count(),
                'first_response_minutes' => $seconds === [] ? null : round(max(0.0, Data::percentile($seconds, 0.5)) / 60, 1),
                'appointments_booked' => (float) (clone $appointments)->whereBetween('created_at', $range)->count(),
                'appointments_attended' => (float) (clone $appointments)->where('status', 'completed')->whereBetween('starts_at', $range)->count(),
                'close_rate' => $closed > 0 ? round($wonCount / $closed * 100, 1) : null,
                'won_value' => (float) $won->sum('value_ws'),
            ];
        };
        $now = $measure($month);
        $before = $month->compare !== null ? $measure($month->compare) : [];
        $metrics = [];
        foreach ($now as $name => $value) {
            $metrics[$name] = self::kpi($value, $before[$name] ?? null);
        }
        // Día local con el desfase de la zona (LATAM no usa horario de verano).
        $offset = [(int) $series->offsetSeconds()];
        $range = [$series->from->utc(), $series->to->utc()];
        $rows = [];
        foreach (DB::table('leads')->where('workspace_id', $ws)->whereBetween('created_at', $range)
            ->selectRaw('DATE(DATE_ADD(created_at, INTERVAL ? SECOND)) AS d, COUNT(*) AS n', $offset)->groupBy('d')->get() as $r) {
            $rows[(string) $r->d]['leads'] = (float) $r->n;
        }
        foreach (DB::table('leads')->where('workspace_id', $ws)->whereBetween('won_at', $range)
            ->selectRaw('DATE(DATE_ADD(won_at, INTERVAL ? SECOND)) AS d, COALESCE(SUM(value_ws), 0) AS v', $offset)->groupBy('d')->get() as $r) {
            $rows[(string) $r->d]['won_value'] = (float) $r->v;
        }
        $list = [];
        foreach ($rows as $date => $values) {
            $list[] = ['date' => $date] + $values;
        }
        $sources = [];
        foreach (DB::table('leads')->where('workspace_id', $ws)->whereBetween('created_at', [$month->from->utc(), $month->to->utc()])
            ->selectRaw('origin, COUNT(*) AS n')->groupBy('origin')->orderByDesc('n')->get() as $r) {
            $origin = (string) ($r->origin ?? 'other');
            $label = __('home.lead_origins.'.$origin);
            $sources[] = ['origin' => $origin, 'label' => $label !== 'home.lead_origins.'.$origin ? $label : __('home.lead_origins.other'), 'count' => (int) $r->n];
        }

        return ['available' => true, 'units' => $units, 'metrics' => $metrics, 'series_keys' => ['leads', 'won_value'], 'series' => self::fill($series, $list, ['leads', 'won_value']), 'lead_sources' => $sources];
    }

    /** @return array{value: float|null, previous: float|null, change_pct: float|null} */
    private static function kpi(?float $value, ?float $previous): array
    {
        return ['value' => $value, 'previous' => $previous, 'change_pct' => $value !== null && $previous !== null && $previous > 0 ? round(($value - $previous) / $previous * 100, 1) : null];
    }

    /**
     * La serie diaria completa (los días sin datos en 0) con solo las métricas pedidas.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $names
     * @return list<array<string, float|string>>
     */
    private static function fill(Period $period, array $rows, array $names): array
    {
        $byDate = [];
        foreach ($rows as $row) {
            $byDate[substr((string) ($row['date'] ?? ''), 0, 10)] = $row;
        }
        $out = [];
        foreach ($period->buckets() as $date) {
            $point = ['date' => $date];
            foreach ($names as $name) {
                $point[$name] = (float) ($byDate[$date][$name] ?? 0);
            }
            $out[] = $point;
        }

        return $out;
    }

    /** @return list<array{site_id: string, host: string, state: string, causes: list<array<string, mixed>>}> los sitios que no están al día */
    private function healthNotices(Workspace $workspace): array
    {
        $sites = Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->get();
        if ($sites->isEmpty()) {
            return [];
        }
        try {
            $health = $this->health->forSites($workspace, $sites);
        } catch (Throwable $e) {
            report($e);

            return [];
        }
        $out = [];
        foreach ($sites as $site) {
            $h = $health[$site->id] ?? null;
            if ($h !== null && $h['state'] !== 'ok') {
                $out[] = ['site_id' => $site->publicId(), 'host' => (string) $site->host, 'state' => $h['state'], 'causes' => $h['causes']];
            }
        }

        return $out;
    }
}
