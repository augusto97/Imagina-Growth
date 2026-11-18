<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Workspaces\Models\Workspace;
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
        $period ??= UsageCalculator::currentPeriod($workspace);
        $row = DB::table('usage_counters')->where('workspace_id', $workspace->id)->where('period', $period)->first();
        $plan = $this->plans->planFor($workspace);
        $metrics = [$plan !== null ? $plan->metric : 'orders', $plan !== null && $plan->metric === 'orders' ? 'contacts' : 'orders', 'emails', 'wa_marketing', 'wa_utility', 'wa_service', 'sms', 'events'];
        $meters = [];
        foreach ($metrics as $metric) {
            $meters[] = self::meter($metric, (int) ($row->{$metric} ?? 0), $this->plans->limit($workspace, $metric));
        }
        $meters[] = self::meter('ai_cost_usd', round($this->aiCostUsd($workspace, $period), 2), $this->plans->limit($workspace, 'ai_budget_usd'));

        return ['period' => $period, 'computed_at' => $row !== null && $row->computed_at !== null ? (string) $row->computed_at : null, 'meters' => $meters];
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
