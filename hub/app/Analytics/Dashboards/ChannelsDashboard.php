<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Analytics\Semantic\Filters;
use App\Analytics\Semantic\Period;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * 3. Canales: tráfico, conversión e ingresos por canal (inteligencia.md §2),
 * modelos de atribución, LTV a 90 y 365 días por canal de adquisición, y
 * CAC/ROAS cuando hay gasto importado (ad_spend_daily).
 */
final class ChannelsDashboard extends Dashboard
{
    /** Plataforma de anuncios → canal pago. */
    private const PLATFORM_CHANNEL = ['meta' => 'paid_meta', 'google' => 'paid_google', 'tiktok' => 'paid_tiktok'];

    public function handle(Workspace $workspace, Period $period, Filters $filters): array
    {
        [$fsql, $params] = $filters->sessionsSql();
        $traffic = Ch::forWorkspace($workspace)->select(
            "SELECT channel, count() AS sessions, uniqExact(anonymous_id) AS users, sum(purchased) AS purchases
             FROM sessions FINAL WHERE workspace_id = {workspace_id:UInt32} AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)}{$fsql}
             GROUP BY channel ORDER BY sessions DESC",
            $params + self::chRange($period),
        );

        // Atribución de los pedidos válidos del periodo.
        $orders = $this->paidOrders($workspace, $period, $filters)
            ->selectRaw('contact_id, UNIX_TIMESTAMP(created_at) AS ts, total_ws, channel_last, is_first_order')
            ->get();
        $attribution = (new Attribution)->load($workspace, $period->from, $period->to);
        $models = $attribution->attribute($orders);
        $newByChannel = [];
        foreach ($orders as $o) {
            if ((int) $o->is_first_order === 1) {
                foreach ($attribution->credit($o->contact_id, (int) $o->ts, $o->channel_last, 'last_click') as $c => $w) {
                    $newByChannel[$c] = ($newByChannel[$c] ?? 0) + $w;
                }
            }
        }

        // Gasto en anuncios del periodo → CAC y ROAS (último clic).
        $spend = DB::table('ad_spend_daily')->where('workspace_id', $workspace->id)
            ->where('date', '>=', $period->from->toDateString())->where('date', '<', $period->to->toDateString())
            ->groupBy('platform')->selectRaw('platform, SUM(spend) AS spend, SUM(clicks) AS clicks, SUM(impressions) AS impressions')
            ->get()->keyBy('platform');

        $rows = [];
        $channels = array_unique(array_merge(array_column($traffic, 'channel'), array_keys($models['last_click'] ?? [])));
        $trafficBy = [];
        foreach ($traffic as $t) {
            $trafficBy[(string) $t['channel']] = $t;
        }
        foreach ($channels as $channel) {
            $t = $trafficBy[$channel] ?? ['sessions' => 0, 'users' => 0, 'purchases' => 0];
            $platform = array_search($channel, self::PLATFORM_CHANNEL, true);
            $spendRow = $platform !== false ? $spend->get($platform) : null;
            $revenue = (float) ($models['last_click'][$channel]['revenue'] ?? 0);
            $new = (float) ($newByChannel[$channel] ?? 0);
            $rows[] = [
                'channel' => (string) $channel,
                'sessions' => (int) $t['sessions'],
                'users' => (int) $t['users'],
                'conversion' => self::ratio((int) $t['purchases'], (int) $t['sessions']),
                'orders' => round((float) ($models['last_click'][$channel]['orders'] ?? 0), 2),
                'revenue' => round($revenue, 2),
                'new_customers' => round($new, 2),
                'spend' => $spendRow !== null ? round((float) $spendRow->spend, 2) : null,
                'roas' => $spendRow !== null && (float) $spendRow->spend > 0 ? round($revenue / (float) $spendRow->spend, 2) : null,
                'cac' => $spendRow !== null && $new > 0 ? round((float) $spendRow->spend / $new, 2) : null,
            ];
        }
        usort($rows, fn ($a, $b) => $b['revenue'] <=> $a['revenue'] ?: $b['sessions'] <=> $a['sessions']);

        $modelTable = [];
        foreach (Attribution::MODELS as $model) {
            foreach ($models[$model] ?? [] as $channel => $v) {
                $modelTable[$channel][$model] = round($v['revenue'], 2);
            }
        }

        return [
            'channels' => $rows,
            'models' => array_map(fn ($c, $v) => ['channel' => (string) $c] + array_merge(array_fill_keys(Attribution::MODELS, 0), $v), array_keys($modelTable), $modelTable),
            'ltv' => $this->ltv($workspace),
            'spend_available' => $spend->isNotEmpty(),
            'attribution_window_days' => Attribution::WINDOW_DAYS,
        ];
    }

    /**
     * LTV a 90 y 365 días por canal de adquisición (canal del primer toque
     * antes del primer pedido; si no hay toques, orders.channel_first).
     *
     * @return list<array<string, mixed>>
     */
    private function ltv(Workspace $workspace): array
    {
        $now = CarbonImmutable::now();
        $firsts = DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->where('is_first_order', true)
            ->whereNotNull('contact_id')->where('created_at', '>=', $now->subMonths(25))
            ->selectRaw('contact_id, UNIX_TIMESTAMP(created_at) AS ts, created_at, channel_first, channel_last')->get();
        if ($firsts->isEmpty()) {
            return [];
        }
        $attribution = (new Attribution)->load($workspace, $now->subMonths(25), $now);
        $revenue = DB::table('orders as o')
            ->join('orders as f', function ($j): void {
                $j->on('f.contact_id', '=', 'o.contact_id')->where('f.is_first_order', true);
            })
            ->where('o.workspace_id', $workspace->id)->where('o.is_paid', true)->where('f.workspace_id', $workspace->id)
            ->groupBy('o.contact_id')
            ->selectRaw('o.contact_id,
                SUM(CASE WHEN o.created_at < DATE_ADD(f.created_at, INTERVAL 90 DAY) THEN o.total_ws ELSE 0 END) AS r90,
                SUM(CASE WHEN o.created_at < DATE_ADD(f.created_at, INTERVAL 365 DAY) THEN o.total_ws ELSE 0 END) AS r365')
            ->get()->keyBy('contact_id');

        $agg = [];
        foreach ($firsts as $f) {
            $channel = $attribution->firstTouch((string) $f->contact_id, (int) $f->ts) ?? ($f->channel_first ?: ($f->channel_last ?: 'unknown'));
            $age = $now->getTimestamp() - (int) $f->ts;
            $r = $revenue->get($f->contact_id);
            $agg[$channel]['customers'] = ($agg[$channel]['customers'] ?? 0) + 1;
            if ($age >= 90 * 86400) {
                $agg[$channel]['n90'] = ($agg[$channel]['n90'] ?? 0) + 1;
                $agg[$channel]['s90'] = ($agg[$channel]['s90'] ?? 0) + (float) ($r->r90 ?? 0);
            }
            if ($age >= 365 * 86400) {
                $agg[$channel]['n365'] = ($agg[$channel]['n365'] ?? 0) + 1;
                $agg[$channel]['s365'] = ($agg[$channel]['s365'] ?? 0) + (float) ($r->r365 ?? 0);
            }
        }
        $out = [];
        foreach ($agg as $channel => $a) {
            $out[] = [
                'channel' => (string) $channel,
                'customers' => $a['customers'],
                'ltv_90' => isset($a['n90']) ? round($a['s90'] / $a['n90'], 2) : null,
                'ltv_365' => isset($a['n365']) ? round($a['s365'] / $a['n365'], 2) : null,
                'customers_90' => $a['n90'] ?? 0,
                'customers_365' => $a['n365'] ?? 0,
            ];
        }
        usort($out, fn ($a, $b) => ($b['ltv_365'] ?? $b['ltv_90'] ?? 0) <=> ($a['ltv_365'] ?? $a['ltv_90'] ?? 0));

        return $out;
    }
}
