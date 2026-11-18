<?php

declare(strict_types=1);

namespace App\Domain\Billing\Usage;

use App\Domain\Api\Support\ApiMeter;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Calcula y guarda el uso del periodo de un espacio de trabajo (`usage_counters`), y
 * después revisa los avisos al 80 % y al 100 % y el estado de cobro de la cuenta.
 * Corre cada 5 minutos (`igr:billing:usage`) y bajo demanda desde el panel.
 */
final class ComputeUsage
{
    public function __construct(
        private readonly UsageCalculator $calculator,
        private readonly UsageAlerts $alerts,
        private readonly EvaluateBillingStatus $status,
    ) {}

    /** @return array<string, int> */
    public function handle(Workspace $workspace, ?string $period = null, bool $evaluate = true): array
    {
        $period ??= UsageCalculator::currentPeriod($workspace);
        // `api_requests` sale del medidor de la API pública y MCP (contador por mes en Redis).
        $values = $this->calculator->compute($workspace, $period) + ['api_requests' => ApiMeter::count($workspace, $period)];
        DB::table('usage_counters')->upsert(
            [['workspace_id' => $workspace->id, 'period' => $period, 'computed_at' => now()] + $values],
            ['workspace_id', 'period'],
            [...UsageCalculator::METRICS, 'api_requests', 'computed_at'],
        );
        if ($evaluate && $period === UsageCalculator::currentPeriod($workspace)) {
            $this->alerts->check($workspace, $period);
            $account = $workspace->account()->first();
            if ($account !== null) {
                $this->status->handle($account);
            }
        }

        return $values;
    }
}
