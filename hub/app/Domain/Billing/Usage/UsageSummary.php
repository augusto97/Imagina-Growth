<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El uso del mes para mostrar (panel del cliente y de la plataforma): cada medidor
 * con su límite y su estado (`ok` < 80 %, `warning` ≥ 80 %, `critical` ≥ 100 %).
 */
final class UsageSummary
{
    public function __construct(private readonly PlanResolver $plans) {}

    /**
     * @return array{period: string, computed_at: string|null, meters: list<array{metric: string, value: int|float, limit: int|float|null, pct: int|null, state: string}>}
     */
    public function for(Workspace $workspace, ?string $period = null): array
    {
        return $this->forMany(collect([$workspace]), $period)[$workspace->id];
    }

    /**
     * El uso de varios espacios con dos consultas (contadores y costo de IA), no dos por espacio: la plataforma lista
     * todos los clientes (docs/rendimiento.md §2).
     *
     * @param  Collection<int, Workspace>  $workspaces
     * @return array<string, array{period: string, computed_at: string|null, meters: list<array{metric: string, value: int|float, limit: int|float|null, pct: int|null, state: string}>}>
     */
    public function forMany(Collection $workspaces, ?string $period = null): array
    {
        if ($workspaces->isEmpty()) {
            return [];
        }
        $periods = [];
        $ranges = [];
        $first = null;
        $last = null;
        foreach ($workspaces as $ws) {
            $periods[$ws->id] = $period ?? UsageCalculator::currentPeriod($ws);
            [$from, $to] = UsageCalculator::bounds($ws, $periods[$ws->id]);
            $tz = $ws->timezone ?: 'America/Bogota';
            $ranges[$ws->id] = [$from->setTimezone($tz)->toDateString(), $to->setTimezone($tz)->toDateString()];
            $first = $first === null || $ranges[$ws->id][0] < $first ? $ranges[$ws->id][0] : $first;
            $last = $last === null || $ranges[$ws->id][1] > $last ? $ranges[$ws->id][1] : $last;
        }
        $ids = array_keys($periods);
        $counters = DB::table('usage_counters')->whereIn('workspace_id', $ids)->whereIn('period', array_values(array_unique($periods)))->get()
            ->filter(fn (object $r) => ($periods[(string) $r->workspace_id] ?? null) === (string) $r->period)->keyBy('workspace_id');
        $ai = [];
        foreach (DB::table('ai_usage')->whereIn('workspace_id', $ids)->where('date', '>=', $first)->where('date', '<', $last)
            ->groupBy('workspace_id', 'date')->selectRaw('workspace_id, date, SUM(cost_usd) AS cost')->get() as $r) {
            [$from, $to] = $ranges[(string) $r->workspace_id];
            $day = substr((string) $r->date, 0, 10);
            if ($day >= $from && $day < $to) {
                $ai[(string) $r->workspace_id] = ($ai[(string) $r->workspace_id] ?? 0.0) + (float) $r->cost;
            }
        }

        $out = [];
        foreach ($workspaces as $workspace) {
            $row = $counters->get($workspace->id);
            $plan = $this->plans->planFor($workspace);
            $metrics = [$plan !== null ? $plan->metric : 'orders', $plan !== null && $plan->metric === 'orders' ? 'contacts' : 'orders', 'emails', 'wa_marketing', 'wa_utility', 'wa_service', 'sms', 'events'];
            $meters = [];
            foreach ($metrics as $metric) {
                $meters[] = self::meter($metric, (int) ($row->{$metric} ?? 0), $this->plans->limit($workspace, $metric));
            }
            $meters[] = self::meter('ai_cost_usd', round($ai[$workspace->id] ?? 0.0, 2), $this->plans->limit($workspace, 'ai_budget_usd'));
            $out[$workspace->id] = ['period' => $periods[$workspace->id], 'computed_at' => $row !== null && $row->computed_at !== null ? (string) $row->computed_at : null, 'meters' => $meters];
        }

        return $out;
    }

    public function aiCostUsd(Workspace $workspace, string $period): float
    {
        [$from, $to] = UsageCalculator::bounds($workspace, $period);
        $tz = $workspace->timezone ?: 'America/Bogota';

        return (float) DB::table('ai_usage')->where('workspace_id', $workspace->id)
            ->where('date', '>=', $from->setTimezone($tz)->toDateString())->where('date', '<', $to->setTimezone($tz)->toDateString())
            ->sum('cost_usd');
    }

    /** @return array{metric: string, value: int|float, limit: int|float|null, pct: int|null, state: string} */
    public static function meter(string $metric, int|float $value, int|float|null $limit): array
    {
        $pct = $limit !== null && $limit > 0 ? (int) floor($value * 100 / $limit) : null;

        return ['metric' => $metric, 'value' => $value, 'limit' => $limit, 'pct' => $pct, 'state' => self::state($pct)];
    }

    public static function state(?int $pct): string
    {
        return match (true) {
            $pct === null => 'ok',
            $pct >= 100 => 'critical',
            $pct >= 80 => 'warning',
            default => 'ok',
        };
    }
}
