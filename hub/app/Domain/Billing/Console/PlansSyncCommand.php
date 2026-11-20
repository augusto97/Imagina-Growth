<?php

declare(strict_types=1);

namespace App\Domain\Billing\Console;

use App\Domain\Billing\Plans\PlanCatalog;
use Illuminate\Console\Command;

final class PlansSyncCommand extends Command
{
    protected $signature = 'igr:plans:sync {--missing : Solo crea los planes que faltan; no pisa los editados en el panel}';

    protected $description = 'Sincroniza la tabla de planes con config/igr-plans.php';

    public function handle(PlanCatalog $catalog): int
    {
        $count = $catalog->sync(! (bool) $this->option('missing'));
        $this->info(__(':count planes sincronizados.', ['count' => $count]));

        return self::SUCCESS;
    }
}
