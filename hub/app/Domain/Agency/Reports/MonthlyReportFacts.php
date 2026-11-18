<?php

declare(strict_types=1);

namespace App\Domain\Agency\Reports;

use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Intelligence\Insights\Queries\TopInsights;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los hechos del reporte mensual (M22): el dinero generado por módulo, los KPI
 * del mes contra el anterior, los hallazgos principales y lo que dicen las
 * predicciones. Son los únicos números que puede citar el resumen con IA.
 * Sin datos personales: todo son agregados.
 */
final class MonthlyReportFacts
{
    public function __construct(private readonly MoneyGenerated $money) {}

    /** @return array<string, string> */
    public static function moduleLabels(): array
    {
        return [
            'affiliates' => __('Afiliados'),
            'referrals' => __('Referidos'),
            'flows' => __('Flujos automáticos'),
            'campaigns' => __('Campañas'),
            'ai_agent' => __('Agente de ventas con IA'),
            'inbox' => __('Bandeja de conversaciones'),
            'widgets' => __('Captación en el sitio'),
            'recovered_carts' => __('Carritos recuperados'),
            'messages' => __('Otros mensajes'),
        ];
    }

    /**
     * @param  CarbonImmutable  $period  cualquier día del mes del reporte (en la zona del espacio de trabajo)
     * @return array<string, mixed>
     */
    public function build(Workspace $workspace, CarbonImmutable $period): array
    {
        [$from, $to] = MoneyGenerated::month($workspace, $period);
        [$prevFrom, $prevTo] = [$from->subMonth(), $from];
        $money = $this->money->forWorkspace($workspace, $from, $to);
        $previous = $this->money->forWorkspace($workspace, $prevFrom, $prevTo);
        $labels = self::moduleLabels();

        $kpis = [];
        $now = $this->kpis($workspace, $from, $to);
        $before = $this->kpis($workspace, $prevFrom, $prevTo);
        foreach ($now as $key => $kpi) {
            if ($key === 'leads_nuevos' || $key === 'leads_ganados') {
                if ((float) $kpi['valor'] === 0.0 && (float) $before[$key]['valor'] === 0.0) {
                    continue; // no usa leads
                }
            }
            $kpis[] = $kpi + ['clave' => $key, 'anterior' => $before[$key]['valor'], 'cambio_pct' => self::change((float) $kpi['valor'], (float) $before[$key]['valor'])];
        }

        $modules = [];
        foreach ($money['modules'] as $m) {
            if ((float) $m['revenue'] <= 0 && $m['orders'] === 0) {
                continue;
            }
            $modules[] = ['clave' => $m['key'], 'modulo' => $labels[$m['key']] ?? $m['key'], 'ventas' => self::round($m['revenue']), 'pedidos' => $m['orders'],
                'participacion_pct' => (float) $money['total'] > 0 ? round((float) $m['revenue'] / (float) $money['total'] * 100, 1) : 0.0];
        }

        $insights = array_map(fn (array $i): array => [
            'titulo' => (string) $i['title'],
            'severidad' => (string) $i['severity'],
            'impacto' => $i['impact'] === null ? null : self::round((string) $i['impact']),
        ], TopInsights::facts($workspace, 3));

        return [
            'negocio' => $workspace->name,
            'mes' => self::monthName($from),
            'periodo' => ['desde' => $from->toDateString(), 'hasta' => $to->subDay()->toDateString(), 'mes' => $from->format('Y-m')],
            'moneda' => $money['currency'],
            'dinero_generado' => [
                'total' => self::round($money['total']),
                'pedidos' => $money['orders'],
                'ventas_tienda' => self::round($money['store_total']),
                'participacion_pct' => $money['share_pct'],
                'mes_anterior' => self::round($previous['total']),
                'cambio_vs_anterior_pct' => self::change((float) $money['total'], (float) $previous['total']),
                'por_modulo' => $modules,
            ],
            'kpis' => $kpis,
            'hallazgos' => $insights,
            'predicciones' => $this->predictions($workspace),
        ];
    }

    /** @return array<string, array{nombre: string, valor: float|int, unidad: string}> */
    private function kpis(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $orders = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)
            ->whereNotIn('status', ['cancelled', 'failed', 'refunded'])
            ->where('paid_at', '>=', $from->utc())->where('paid_at', '<', $to->utc())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS revenue, COUNT(DISTINCT contact_id) AS customers')->first();
        $n = (int) ($orders->n ?? 0);
        $revenue = (float) ($orders->revenue ?? 0);
        $new = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id')
            ->where('first_order_at', '>=', $from->utc())->where('first_order_at', '<', $to->utc())->count();
        $leads = ['new' => 0, 'won' => 0];
        if (Schema::hasTable('leads')) {
            $leads['new'] = DB::table('leads')->where('workspace_id', $workspace->id)->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->count();
            $leads['won'] = DB::table('leads')->where('workspace_id', $workspace->id)->where('won_at', '>=', $from->utc())->where('won_at', '<', $to->utc())->count();
        }

        return [
            'ventas' => ['nombre' => __('Ventas'), 'valor' => round($revenue, 0), 'unidad' => 'money'],
            'pedidos' => ['nombre' => __('Pedidos'), 'valor' => $n, 'unidad' => 'count'],
            'ticket_promedio' => ['nombre' => __('Ticket promedio'), 'valor' => $n > 0 ? round($revenue / $n, 0) : 0, 'unidad' => 'money'],
            'clientes' => ['nombre' => __('Clientes que compraron'), 'valor' => (int) ($orders->customers ?? 0), 'unidad' => 'count'],
            'clientes_nuevos' => ['nombre' => __('Clientes nuevos'), 'valor' => $new, 'unidad' => 'count'],
            'leads_nuevos' => ['nombre' => __('Leads nuevos'), 'valor' => $leads['new'], 'unidad' => 'count'],
            'leads_ganados' => ['nombre' => __('Leads ganados'), 'valor' => $leads['won'], 'unidad' => 'count'],
        ];
    }

    /** @return array{clientes_en_riesgo_alto: int, valor_futuro_12m: float, compras_esperadas_30_dias: int} */
    private function predictions(Workspace $workspace): array
    {
        $base = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id');

        return [
            'clientes_en_riesgo_alto' => (clone $base)->where('churn_band', 'high')->count(),
            'valor_futuro_12m' => round((float) (clone $base)->sum('predicted_clv_12m_ws'), 0),
            'compras_esperadas_30_dias' => (clone $base)->whereBetween('expected_next_order_at', [now(), now()->addDays(30)])->count(),
        ];
    }

    private static function monthName(CarbonImmutable $from): string
    {
        $local = $from->locale('es');

        return $local instanceof CarbonImmutable ? $local->translatedFormat('F \\d\\e Y') : $from->format('Y-m');
    }

    private static function round(string $amount): float
    {
        return round((float) $amount, 0);
    }

    private static function change(float $value, float $base): ?float
    {
        return $base > 0 ? round(($value - $base) / $base * 100, 1) : null;
    }
}
