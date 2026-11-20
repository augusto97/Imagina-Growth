<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Usage\ComputeUsage;
use App\Domain\Billing\Usage\UsageActivity;
use App\Domain\Billing\Usage\UsageCalculator;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Uso del mes por espacio de trabajo, avisos al 80/100 % y estado de cobro (M21). Desde R13:
 *
 * - `--changed` (cada 5 minutos): solo los espacios con actividad desde su último cálculo (UsageActivity).
 * - sin opciones (cada hora): todos. Además, durante los primeros días de cada mes recalcula el mes anterior ya
 *   cerrado, así el número que se factura incluye el final del mes y los pedidos que llegaron tarde (el plugin
 *   reintenta su outbox), sin volver a mandar avisos.
 *
 * El cálculo es siempre completo desde las tablas de origen: hacerlo menos veces no le quita exactitud.
 */
final class BillingUsageCommand extends Command
{
    /** Días del mes nuevo en los que se vuelve a calcular el mes anterior. */
    public const FINALIZE_DAYS = 3;

    protected $signature = 'igr:billing:usage
        {--workspace= : ID público o interno de un espacio}
        {--period= : AAAA-MM (por defecto el mes en curso)}
        {--changed : Solo los espacios con actividad desde su último cálculo}';

    protected $description = 'Calcula los contadores de uso de los planes (M21)';

    public function handle(ComputeUsage $usage): int
    {
        $option = $this->option('workspace');
        $query = Workspace::query()->orderBy('id');
        $single = is_string($option) && $option !== '';
        if ($single) {
            $found = Workspace::findByPublicId($option) ?? Workspace::query()->find($option);
            if ($found === null) {
                $this->error(__('No existe ese espacio de trabajo.'));

                return self::FAILURE;
            }
            $query->whereKey($found->id);
        } elseif ($this->option('changed')) {
            $ids = array_values(array_unique([...UsageActivity::pull(), ...UsageActivity::ingestedSinceLastComputation()]));
            if ($ids === []) {
                $this->info(__('Uso calculado para :count espacios de trabajo.', ['count' => 0]));

                return self::SUCCESS;
            }
            $query->whereIn('id', $ids);
        }
        $period = $this->option('period');
        $period = is_string($period) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1 ? $period : null;
        $finalize = $period === null && ! $single && ! $this->option('changed');
        $done = 0;
        foreach ($query->cursor() as $workspace) {
            try {
                $usage->handle($workspace, $period);
                if ($finalize) {
                    $this->finalizePrevious($usage, $workspace);
                }
                $done++;
            } catch (Throwable $e) {
                report($e);
            }
        }
        $this->info(__('Uso calculado para :count espacios de trabajo.', ['count' => $done]));

        return self::SUCCESS;
    }

    /** En los primeros días del mes, el mes anterior otra vez (sin avisos ni cambio de estado de cobro). */
    private function finalizePrevious(ComputeUsage $usage, Workspace $workspace): void
    {
        $now = CarbonImmutable::now()->setTimezone($workspace->timezone ?: 'America/Bogota');
        if ($now->day > self::FINALIZE_DAYS) {
            return;
        }
        $previous = $now->startOfMonth()->subMonth()->format('Y-m');
        $exists = DB::table('usage_counters')->where('workspace_id', $workspace->id)->where('period', $previous)->exists();
        $hasData = $exists || DB::table('orders')->where('workspace_id', $workspace->id)->where('created_at', '<', UsageCalculator::bounds($workspace, $previous)[1])->exists();
        if ($hasData) {
            $usage->handle($workspace, $previous, evaluate: false);
        }
    }
}
