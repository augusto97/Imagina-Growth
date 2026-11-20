<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\PlanResolver;
use Illuminate\Console\Command;

final class PlansSyncCommand extends Command
{
    protected $signature = 'igr:plans:sync {--missing : Solo crea los planes que faltan; no pisa los editados en el panel}
        {--refresh : Olvida los módulos guardados de todos los espacios (después de cambiar IGR_DEFAULT_PLAN)}';

    protected $description = 'Sincroniza la tabla de planes con config/igr-plans.php';

    public function handle(PlanCatalog $catalog, PlanResolver $plans): int
    {
        $count = $catalog->sync(! (bool) $this->option('missing'));
        $this->info(__(':count planes sincronizados.', ['count' => $count]));
        if ($this->option('refresh')) {
            $plans->refreshAll();
            $this->info(__('Módulos de todos los espacios de trabajo recalculados.'));
        }

        return self::SUCCESS;
    }
}
