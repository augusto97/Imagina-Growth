<?php

declare(strict_types=1);

namespace App\Domain\Flows\Console;

use App\Domain\Flows\Jobs\AdvanceFlowRun;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cada minuto (hub/CLAUDE.md §7, «Ejecución»): toma los runs vivos de flujos
 * activos con `next_run_at` vencido, por lotes con SKIP LOCKED, les pone un
 * plazo de 5 minutos (si el job se cae, vuelven a salir) y despacha AdvanceFlowRun.
 */
final class TickFlowsCommand extends Command
{
    protected $signature = 'igr:flows:tick {--limit=2000 : Runs por ejecución}';

    protected $description = 'Avanza los runs de flujos que tienen un paso vencido';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $total = 0;
        while ($total < $limit) {
            $rows = DB::transaction(function () {
                $rows = DB::table('flow_runs')
                    ->whereIn('status', FlowRun::LIVE)
                    ->whereNotNull('next_run_at')
                    ->where('next_run_at', '<=', now())
                    ->whereIn('flow_id', DB::table('flows')->select('id')->where('status', Flow::ACTIVE))
                    ->orderBy('next_run_at')
                    ->limit(500)
                    ->lock('for update skip locked')
                    ->get(['id', 'workspace_id']);
                if ($rows->isNotEmpty()) {
                    DB::table('flow_runs')->whereIn('id', $rows->pluck('id')->all())->update(['next_run_at' => now()->addMinutes(5)]);
                }

                return $rows;
            });
            if ($rows->isEmpty()) {
                break;
            }
            foreach ($rows as $row) {
                AdvanceFlowRun::dispatch((string) $row->workspace_id, (string) $row->id);
            }
            $total += $rows->count();
        }
        if ($total > 0) {
            $this->line("Runs despachados: {$total}");
        }

        return self::SUCCESS;
    }
}
