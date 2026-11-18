<?php

declare(strict_types=1);

namespace App\Analytics\Console;

use App\Analytics\ClickHouseMigrator;
use Illuminate\Console\Command;

final class ClickHouseMigrateCommand extends Command
{
    protected $signature = 'igr:clickhouse:migrate {--fresh : Borra y recrea la base (no en producción)}';

    protected $description = 'Aplica las migraciones de ClickHouse (database/clickhouse/*.sql)';

    public function handle(ClickHouseMigrator $migrator): int
    {
        if ($this->option('fresh')) {
            if (app()->isProduction()) {
                $this->error('No se permite --fresh en producción.');

                return self::FAILURE;
            }
            $ran = $migrator->fresh();
        } else {
            $ran = $migrator->migrate();
        }
        $this->info($ran === [] ? 'ClickHouse ya está al día.' : 'Aplicadas: '.implode(', ', $ran));

        return self::SUCCESS;
    }
}
