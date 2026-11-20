<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Console;

use App\Analytics\Builders\KpiDailyBuilder;
use App\Analytics\Builders\SessionsBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Arma las sesiones cerradas (cada 10 minutos) y, F12, deja `kpi_daily` de ayer y hoy al día con ellas: los tableros
 * leen el tráfico de ahí, y antes solo se recalculaba de noche. Solo rehace los días que cambiaron
 * (KpiDailyBuilder::refreshRecent); con `--no-kpi` solo arma las sesiones.
 */
final class BuildSessionsCommand extends Command
{
    use SelectsWorkspaces;

    protected $signature = 'igr:sessions:build {--workspace=} {--minutes=180 : Ventana hacia atrás} {--full : Reconstruye 25 meses} {--no-kpi : No actualiza kpi_daily}';

    protected $description = 'Arma las sesiones cerradas desde los eventos (cada 10 minutos) y pone al día los KPI de ayer y hoy';

    public function handle(SessionsBuilder $builder, KpiDailyBuilder $kpi): int
    {
        $since = $this->option('full') ? CarbonImmutable::now()->subMonths(25) : CarbonImmutable::now()->subMinutes((int) $this->option('minutes'));
        foreach ($this->workspaces() as $ws) {
            $builder->build($ws, $since);
            if ($this->option('no-kpi')) {
                continue;
            }
            try {
                $this->option('full') ? $kpi->build($ws, (int) $since->diffInDays(CarbonImmutable::now()) + 2) : $kpi->refreshRecent($ws);
            } catch (Throwable $e) {
                // Los KPI de un espacio de trabajo no frenan las sesiones de los demás; el nocturno vuelve a intentarlo.
                report($e);
            }
        }

        return self::SUCCESS;
    }
}
