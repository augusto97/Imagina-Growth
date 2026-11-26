<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Support;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El resultado del mes de cada estrategia: el dinero que generó según MoneyGenerated (la única consulta del dinero
 * generado: cada pedido una sola vez, en el primer módulo que lo explica), por sus flujos, sus widgets o su módulo;
 * cuántas conversiones tuvo (metas de sus flujos, capturas, reseñas, respuestas, referidos, canjes, citas…) y cuántos
 * mensajes envió. También arma el desglose del dinero generado por estrategia del Inicio. En caché con la versión de
 * la ingesta (MetricQueryRunner::remember) y la de las estrategias.
 */
final class StrategyResults
{
    /** Módulo de MoneyGenerated → estrategia (si no hay, va a «otros»). */
    private const MODULE_OWNERS = [
        'referrals' => 'referrals', 'inbox' => 'inbox_assignment', 'recovered_carts' => 'abandoned_cart',
        'leads' => 'service_lead', 'bookings' => 'appointment_reminders', 'quotes' => 'quote_calculator', 'ai_agent' => 'ai_sales_agent',
    ];

    public function __construct(private readonly MoneyGenerated $money) {}

    /**
     * @return array{month: array{from: string, to: string}, strategies: array<string, array{revenue: string, orders: int, conversions: array{kind: string, count: int}|null, messages: int|null}>,
     *     breakdown: list<array{strategy: string|null, bucket: string, revenue: string, orders: int}>}
     */
    public function forMonth(Workspace $workspace, ?CarbonImmutable $anyDay = null, bool $afterHoursOwnsAgent = false): array
    {
        [$from, $to] = MoneyGenerated::month($workspace, $anyDay);
        $key = 'strategies:results:'.$from->toDateString().':'.($afterHoursOwnsAgent ? 'ah' : 'ag').':'.StrategyCache::version($workspace->id);

        return MetricQueryRunner::remember($workspace, $key, fn () => $this->compute($workspace, $from, $to, $afterHoursOwnsAgent));
    }

    /**
     * Lo mismo para un periodo cualquiera (`from` incluido, `to` excluido, en la zona del espacio de trabajo): lo usa el
     * Resumen de Resultados con el periodo y la comparación de los tableros. `month` lleva el periodo pedido.
     *
     * @return array{month: array{from: string, to: string}, strategies: array<string, array{revenue: string, orders: int, conversions: array{kind: string, count: int}|null, messages: int|null}>,
     *     breakdown: list<array{strategy: string|null, bucket: string, revenue: string, orders: int}>}
     */
    public function forPeriod(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, bool $afterHoursOwnsAgent = false): array
    {
        $key = 'strategies:results:'.$from->toDateString().':'.$to->toDateString().':'.($afterHoursOwnsAgent ? 'ah' : 'ag').':'.StrategyCache::version($workspace->id);

        return MetricQueryRunner::remember($workspace, $key, fn () => $this->compute($workspace, $from, $to, $afterHoursOwnsAgent));
    }

    /**
     * @return array{month: array{from: string, to: string}, strategies: array<string, array{revenue: string, orders: int, conversions: array{kind: string, count: int}|null, messages: int|null}>,
     *     breakdown: list<array{strategy: string|null, bucket: string, revenue: string, orders: int}>}
     */
    private function compute(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to, bool $afterHoursOwnsAgent): array
    {
        $ws = $workspace->id;
        $by = $this->money->bySource($workspace, $from, $to);
        $owners = self::MODULE_OWNERS;
        if ($afterHoursOwnsAgent) {
            $owners['ai_agent'] = 'after_hours';
        }
        $flowKeys = DB::table('flows')->where('workspace_id', $ws)->whereNotNull('template_key')->pluck('template_key', 'id')->map(fn ($k) => (string) $k)->all();
        $widgetKinds = DB::table('onsite_widgets')->where('workspace_id', $ws)->pluck('kind', 'id')->map(fn ($k) => (string) $k)->all();
        $range = [$from->utc(), $to->utc()];
        $messages = DB::table('messages')->where('workspace_id', $ws)->where('direction', 'out')->whereNotNull('flow_id')
            ->whereBetween('created_at', $range)->whereNotIn('status', ['denied', 'skipped', 'deferred'])
            ->groupBy('flow_id')->selectRaw('flow_id, COUNT(*) AS n')->pluck('n', 'flow_id')->all();
        $goals = DB::table('flow_runs')->where('workspace_id', $ws)->where('exit_reason', 'goal_reached')->where('is_holdout', false)
            ->whereBetween('ended_at', $range)->groupBy('flow_id')->selectRaw('flow_id, COUNT(*) AS n')->pluck('n', 'flow_id')->all();
        $captures = DB::table('widget_captures')->where('workspace_id', $ws)->whereBetween('captured_at', $range)
            ->groupBy('widget_id')->selectRaw('widget_id, COUNT(*) AS n')->pluck('n', 'widget_id')->all();

        // Dinero por estrategia (y por fuente sin estrategia: campañas, afiliados, otras automatizaciones…).
        $sources = [];
        foreach ($by['flows'] as $flowId => $sum) {
            $sources[] = [isset($flowKeys[$flowId]) ? StrategyRegistry::forFlow($flowKeys[$flowId]) : null, 'other_flows', $sum];
        }
        foreach ($by['widgets'] as $widgetId => $sum) {
            $sources[] = [isset($widgetKinds[$widgetId]) ? StrategyRegistry::forWidget($widgetKinds[$widgetId]) : null, 'other_widgets', $sum];
        }
        foreach ($by['modules'] as $module => $sum) {
            if (! in_array($module, ['flows', 'widgets'], true)) {
                $sources[] = [$owners[$module] ?? null, $module, $sum];
            }
        }
        $money = [];
        $breakdown = [];
        foreach ($sources as [$strategy, $bucket, $sum]) {
            if ($strategy !== null) {
                $money[$strategy] = self::plus($money[$strategy] ?? null, $sum);
            }
            $k = $strategy ?? $bucket;
            $breakdown[$k] = ['strategy' => $strategy, 'bucket' => $bucket] + self::plus(isset($breakdown[$k]) ? ['revenue' => $breakdown[$k]['revenue'], 'orders' => $breakdown[$k]['orders']] : null, $sum);
        }

        // Conversiones y mensajes: los de sus flujos y widgets, o los de su módulo.
        $conversions = [];
        $sent = [];
        foreach (StrategyRegistry::all() as $definition) {
            $ids = array_keys(array_filter($flowKeys, fn (string $k) => in_array($k, $definition->flowKeys(), true)));
            if ($ids !== []) {
                $sent[$definition->key] = array_sum(array_map(fn ($id) => (int) ($messages[$id] ?? 0), $ids));
                $conversions[$definition->key] = ['kind' => 'goals', 'count' => array_sum(array_map(fn ($id) => (int) ($goals[$id] ?? 0), $ids))];
            }
            $widgets = array_keys(array_filter($widgetKinds, fn (string $k) => in_array($k, $definition->widgetKinds(), true)));
            if ($widgets !== []) {
                $conversions[$definition->key] = ['kind' => 'captures', 'count' => array_sum(array_map(fn ($id) => (int) ($captures[$id] ?? 0), $widgets))];
            }
        }
        foreach ($this->moduleCounts($ws, $range, $owners['ai_agent']) as $strategy => $extra) {
            if (isset($extra['messages'])) {
                $sent[$strategy] = $extra['messages'];
            }
            if (isset($extra['conversions'])) {
                $conversions[$strategy] = $extra['conversions'];
            }
        }

        $results = [];
        foreach (StrategyRegistry::all() as $definition) {
            $key = $definition->key;
            $results[$key] = [
                'revenue' => $money[$key]['revenue'] ?? '0.00',
                'orders' => $money[$key]['orders'] ?? 0,
                'conversions' => $conversions[$key] ?? null,
                'messages' => $sent[$key] ?? null,
            ];
        }
        $list = array_values($breakdown);
        usort($list, fn (array $a, array $b) => (float) $b['revenue'] <=> (float) $a['revenue']);

        return ['month' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()], 'strategies' => $results, 'breakdown' => $list];
    }

    /**
     * @param  array{revenue: string, orders: int}|null  $sum
     * @param  array{revenue: string, orders: int}  $add
     * @return array{revenue: string, orders: int}
     */
    private static function plus(?array $sum, array $add): array
    {
        return $sum === null ? $add : ['revenue' => Decimal::add($sum['revenue'], $add['revenue']), 'orders' => $sum['orders'] + $add['orders']];
    }

    /**
     * Conversiones y mensajes de las estrategias que hace su propio módulo (sin flujo del constructor).
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $range
     * @return array<string, array{messages?: int, conversions?: array{kind: string, count: int}}>
     */
    private function moduleCounts(string $ws, array $range, string $agentOwner): array
    {
        $count = fn (string $table, string $column, ?callable $where = null) => (int) ($where !== null ? $where(DB::table($table)) : DB::table($table))
            ->where($table.'.workspace_id', $ws)->whereBetween($table.'.'.$column, $range)->count();
        $nps = DB::table('survey_definitions')->where('workspace_id', $ws)->where('kind', 'nps')->pluck('id')->all();

        return [
            'review_request' => ['messages' => $count('review_requests', 'sent_at'), 'conversions' => ['kind' => 'reviews', 'count' => $count('reviews', 'created_at', fn ($q) => $q->where('source', 'request'))]],
            'nps' => ['messages' => $count('survey_requests', 'sent_at', fn ($q) => $q->whereIn('survey_id', $nps)), 'conversions' => ['kind' => 'responses', 'count' => $count('survey_responses', 'created_at', fn ($q) => $q->whereIn('survey_id', $nps))]],
            'back_in_stock' => ['messages' => $count('product_watches', 'notified_at')],
            'referrals' => ['conversions' => ['kind' => 'referrals', 'count' => $count('referrals', 'created_at')]],
            'loyalty_points' => ['conversions' => ['kind' => 'redemptions', 'count' => $count('loyalty_ledger', 'created_at', fn ($q) => $q->where('reason', 'redeem'))]],
            $agentOwner => ['messages' => $count('ai_agent_turns', 'created_at', fn ($q) => $q->where('is_test', false))],
            'appointment_reminders' => ['conversions' => ['kind' => 'appointments', 'count' => $count('appointments', 'created_at')]],
            'quote_calculator' => ['conversions' => ['kind' => 'quotes', 'count' => $count('quotes', 'created_at')]],
            'service_lead' => ['conversions' => ['kind' => 'leads', 'count' => $count('leads', 'created_at', fn ($q) => $q->whereIn('origin', ['form', 'whatsapp']))]],
            'chat_qualification' => ['conversions' => ['kind' => 'leads', 'count' => $count('leads', 'created_at', fn ($q) => $q->whereIn('origin', ['whatsapp', 'messenger', 'instagram']))]],
        ];
    }
}
