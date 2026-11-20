<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Domain\Reports\Jobs\SendWeeklyReportJob;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Reports\WeeklyReport;
use Illuminate\Console\Command;
use Throwable;

/**
 * Resumen semanal al dueño. Con `--due` (el programador, cada hora) encola un SendWeeklyReportJob por cada espacio de
 * trabajo al que le toca y no lo tiene (R10: en paralelo, con reintentos y una sola vez por semana). Sin `--due` (a
 * mano), lo arma en el momento y muestra el resultado; `--queue` lo encola igual que el programador.
 */
final class WeeklyReportCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:report:weekly {--workspace=} {--due : Solo a los que les toca (lunes desde las 07:00 local, una vez por semana), en la cola} {--queue : Encola en vez de enviar en el momento} {--force : Reenvía aunque ya se haya enviado}';

    protected $description = 'Envía el resumen semanal al dueño';

    public function handle(SendWeeklyReport $send): int
    {
        $failed = false;
        foreach ($this->workspaces() as $ws) {
            if ($this->option('due') && (! SendWeeklyReport::isDue($ws) || SendWeeklyReport::attempted($ws))) {
                continue;
            }
            if ($this->option('due') || $this->option('queue')) {
                SendWeeklyReportJob::dispatch($ws->id, WeeklyReport::lastWeekStart($ws)->toDateString(), (bool) $this->option('force'));
                $this->line(sprintf('%s: %s', $ws->name, __('en la cola')));

                continue;
            }
            try {
                $result = $send->handle($ws, null, (bool) $this->option('force'));
                $this->line(sprintf('%s: %s %s', $ws->name, $result['status'], implode(', ', $result['recipients'])));
            } catch (Throwable $e) {
                // Uno que falla no deja sin informe a los demás.
                report($e);
                $this->error(sprintf('%s: %s', $ws->name, __('falló; revisa el registro de errores')));
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
