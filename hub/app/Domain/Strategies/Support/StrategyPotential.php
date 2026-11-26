<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Analytics\Semantic\MetricQuery;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Insights\Detectors\FirstPurchaseStalledDetector;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Assumptions;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * El potencial de cada estrategia con los datos reales del negocio («este mes hubo 37 carritos abandonados por
 * $4,2 M»): lo que hay en juego (`count`, `value`) y, cuando hay una tasa de referencia, cuánto se recuperaría
 * (`estimated` = casos × tasa × ticket, con las tasas conservadoras y editables de los hallazgos, Assumptions;
 * inteligencia.md §5.2: siempre con el supuesto a la vista y nunca como promesa). Todo en una pasada por espacio de
 * trabajo, en caché 10 minutos.
 */
final class StrategyPotential
{
    public const CACHE_SECONDS = 600;

    public const WINDOW_DAYS = 30;

    public function __construct(private readonly Assumptions $assumptions, private readonly MetricQueryRunner $metrics) {}

    /**
     * @return array<string, array{kind: string, count: int, value: string|null, estimated: string|null, rate: float|null, rate_key: string|null, window_days: int|null, summary: string}|null>
     */
    public function forWorkspace(Workspace $workspace): array
    {
        // No depende de lo que esté activo: solo de los datos, así que no lleva la versión de las estrategias.
        return Cache::remember('ws:'.$workspace->id.':strategies:potential', self::CACHE_SECONDS, fn () => $this->compute($workspace));
    }

    /** @return array<string, array{kind: string, count: int, value: string|null, estimated: string|null, rate: float|null, rate_key: string|null, window_days: int|null, summary: string}|null> */
    private function compute(Workspace $workspace): array
    {
        $ws = $workspace->id;
        $now = CarbonImmutable::now();
        $since = $now->subDays(self::WINDOW_DAYS)->utc();
        $currency = (string) ($workspace->currency ?: 'COP');
        $out = [];

        $carts = fn (bool $checkout) => self::sum(DB::table('carts')->where('workspace_id', $ws)->whereNotNull('abandoned_at')->where('abandoned_at', '>=', $since)
            ->when($checkout, fn ($q) => $q->whereNotNull('checkout_started_at')), 'subtotal_ws');
        $row = $carts(false);
        $out['abandoned_carts'] = $this->make('abandoned_carts', $currency, $row['n'], $row['v'], $row['t'], 'cart_recovery_rate');
        $row = $carts(true);
        $out['abandoned_checkouts'] = $this->make('abandoned_checkouts', $currency, $row['n'], $row['v'], $row['t'], 'cart_recovery_rate');

        $unpaid = fn (bool $cod) => self::sum(DB::table('orders')->where('workspace_id', $ws)->where('is_paid', false)->where('is_cod', $cod)
            ->where('created_at', '>=', $since)
            ->when($cod, fn ($q) => $q->whereNotIn('status', ['cancelled', 'failed', 'refunded', 'completed']), fn ($q) => $q->whereIn('status', ['pending', 'on-hold'])), 'total_ws');
        $row = $unpaid(false);
        $out['pending_payments'] = $this->make('pending_payments', $currency, $row['n'], $row['v'], $row['t'], 'payment_recovery_rate');
        $row = $unpaid(true);
        $out['cod_pending'] = $this->make('cod_pending', $currency, $row['n'], $row['v']);
        $out['browsers'] = null;

        $contacts = fn () => DB::table('contacts')->where('workspace_id', $ws)->whereNull('deleted_at')->whereNull('merged_into_id');
        [$median] = FirstPurchaseStalledDetector::medianDaysToSecond($workspace);
        $row = self::sum($contacts()->where('orders_count', 1)->whereNotNull('first_order_at')
            ->where('first_order_at', '<', $now->subDays($median)->utc())->where('first_order_at', '>=', $now->subYear()->utc()), 'aov_ws');
        $out['one_time_buyers'] = $this->make('one_time_buyers', $currency, $row['n'], null, $row['t'], 'second_purchase_rate', ['days' => $median]);
        $out['due_soon'] = $this->make('due_soon', $currency, $contacts()->whereBetween('expected_next_order_at', [$now->utc(), $now->addDays(self::WINDOW_DAYS)->utc()])->count());
        $value = self::sum($contacts()->whereIn('rfm_segment', ['at_risk', 'cant_lose']), 'revenue_total_ws');
        $ticket = self::sum($contacts()->whereIn('rfm_segment', ['at_risk', 'cant_lose']), 'aov_ws');
        $out['at_risk'] = $this->make('at_risk', $currency, $value['n'], $value['v'], $ticket['t'], 'reactivation_rate');
        $out['dormant'] = $this->make('dormant', $currency, $contacts()->whereIn('rfm_segment', ['hibernating', 'about_to_sleep'])->count());
        $out['repeat_customers'] = $this->make('repeat_customers', $currency, $contacts()->where('orders_count', '>=', 2)->count());
        $out['happy_customers'] = $this->make('happy_customers', $currency, $contacts()->whereIn('rfm_segment', ['champions', 'loyal'])->count());
        $out['birthdays'] = $this->make('birthdays', $currency, $contacts()->whereNotNull('birthday')->whereRaw('MONTH(birthday) = ?', [(int) $now->setTimezone($workspace->timezone ?: 'America/Bogota')->month])->count());
        $out['customers'] = $this->make('customers', $currency, $contacts()->where('orders_count', '>', 0)->count());
        $out['recent_customers'] = $this->make('recent_customers', $currency, $contacts()->where('last_order_at', '>=', $since)->count());
        $out['watchers'] = $this->make('watchers', $currency, DB::table('product_watches')->where('workspace_id', $ws)->where('status', 'active')->count());

        $out['unreviewed_orders'] = $this->make('unreviewed_orders', $currency, DB::table('orders as o')->where('o.workspace_id', $ws)->where('o.status', 'completed')
            ->where('o.created_at', '>=', $now->subDays(60)->utc())
            ->whereNotExists(fn ($q) => $q->from('reviews as r')->whereColumn('r.order_id', 'o.id')->where('r.workspace_id', $ws))->count(), null, null, null, ['days' => 60]);
        $row = self::sum(DB::table('orders')->where('workspace_id', $ws)->where('is_paid', true)->where('paid_at', '>=', $since), 'total_ws');
        $out['paid_orders'] = $this->make('paid_orders', $currency, $row['n'], $row['v']);

        $inbound = DB::table('messages')->where('workspace_id', $ws)->where('direction', 'in')->where('created_at', '>=', $since);
        $out['inbound'] = $this->make('inbound', $currency, (clone $inbound)->count());
        // Fuera del horario del equipo por defecto (lunes a viernes de 8:00 a 18:00 en la zona del negocio).
        $offset = (int) $now->setTimezone($workspace->timezone ?: 'America/Bogota')->getOffset();
        $out['after_hours_inbound'] = $this->make('after_hours_inbound', $currency, (clone $inbound)
            ->whereRaw('(WEEKDAY(DATE_ADD(created_at, INTERVAL ? SECOND)) >= 5 OR HOUR(DATE_ADD(created_at, INTERVAL ? SECOND)) < 8 OR HOUR(DATE_ADD(created_at, INTERVAL ? SECOND)) >= 18)', [$offset, $offset, $offset])->count());
        $out['unassigned'] = $this->make('unassigned', $currency, DB::table('conversations')->where('workspace_id', $ws)->where('status', '!=', 'closed')->whereNull('assignee_id')->count());

        $leads = fn () => DB::table('leads')->where('workspace_id', $ws)->where('created_at', '>=', $since);
        $row = self::sum($leads()->whereNull('won_at')->whereNull('lost_at')->whereNull('first_response_at'), 'value_ws');
        $out['unanswered_leads'] = $this->make('unanswered_leads', $currency, $row['n'], $row['v'] > 0 ? $row['v'] : null);
        $out['chat_leads'] = $this->make('chat_leads', $currency, $leads()->whereIn('origin', ['whatsapp', 'messenger', 'instagram'])->count());
        $out['upcoming_appointments'] = $this->make('upcoming_appointments', $currency, DB::table('appointments')->where('workspace_id', $ws)
            ->whereBetween('starts_at', [$now->utc(), $now->addDays(self::WINDOW_DAYS)->utc()])->whereNotIn('status', ['cancelled', 'no_show', 'completed'])->count());
        $out['renewals'] = $this->make('renewals', $currency, $contacts()->whereBetween('renewal_at', [$now->utc(), $now->addDays(60)->utc()])->count(), null, null, null, ['days' => 60]);
        $out['visitors'] = $this->make('visitors', $currency, $this->visitors($workspace));

        return $out;
    }

    /**
     * Cuántas filas, la suma y el promedio de una columna, en una consulta.
     *
     * @return array{n: int, v: float, t: float}
     */
    private static function sum(Builder $query, string $column): array
    {
        $n = (clone $query)->count();

        return $n === 0 ? ['n' => 0, 'v' => 0.0, 't' => 0.0] : ['n' => $n, 'v' => (float) (clone $query)->sum($column), 't' => (float) $query->avg($column)];
    }

    /** Visitantes de los últimos 30 días (ClickHouse, con la caché de la capa semántica). 0 si no hay datos. */
    private function visitors(Workspace $workspace): int
    {
        try {
            $period = Period::make($workspace->timezone ?: 'America/Bogota', null, null, 'none');
            $totals = $this->metrics->run($workspace, new MetricQuery(['users'], [], $period))['totals'];

            return (int) ($totals['users'] ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string, int|string>  $extra  marcadores adicionales del texto
     * @return array{kind: string, count: int, value: string|null, estimated: string|null, rate: float|null, rate_key: string|null, window_days: int|null, summary: string}|null
     */
    private function make(string $kind, string $currency, int $count, ?float $value = null, ?float $ticket = null, ?string $rateKey = null, array $extra = []): ?array
    {
        if ($count <= 0) {
            return null;
        }
        $rate = $rateKey !== null ? $this->assumptions->value($rateKey) : null;
        $estimated = $rate !== null && $ticket !== null && $ticket > 0 ? round($count * $rate * $ticket, 2) : null;
        $days = (int) ($extra['days'] ?? self::WINDOW_DAYS);
        $params = ['count' => Money::number($count), 'value' => $value !== null ? Money::format($value, $currency) : '', 'estimated' => $estimated !== null ? Money::format($estimated, $currency) : '',
            'rate' => $rate !== null ? Money::percent($rate, 0) : '', 'days' => $days] + $extra;
        $summary = trans_choice('strategies.potential.'.$kind, $count, $params);
        if ($estimated !== null) {
            $summary .= ' '.__('strategies.potential.estimate', $params);
        }

        return [
            'kind' => $kind,
            'count' => $count,
            'value' => $value !== null ? number_format($value, 2, '.', '') : null,
            'estimated' => $estimated !== null ? number_format($estimated, 2, '.', '') : null,
            'rate' => $rate,
            'rate_key' => $rateKey,
            'window_days' => $days,
            'summary' => $summary,
        ];
    }
}
