<?php

declare(strict_types=1);

namespace App\Analytics\Console;

use App\Analytics\Ch;
use App\Analytics\ClickHouseMigrator;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use Throwable;

final class ClickHouseMigrateCommand extends Command
{
    protected $signature = 'igr:clickhouse:migrate
        {--fresh : Borra y recrea la base (no en producción)}
        {--wait=120 : Segundos que espera a que ClickHouse responda antes de migrar}';

    protected $description = 'Aplica las migraciones de ClickHouse (database/clickhouse/*.sql)';

    public function handle(ClickHouseMigrator $migrator, Ch $ch): int
    {
        // En un servidor pequeño, durante una actualización el kernel puede reiniciar ClickHouse por falta de
        // memoria (docs/despliegue.md §7): se espera a que vuelva en vez de hacer fallar la actualización.
        $deadline = now()->addSeconds(max(0, (int) $this->option('wait')));
        while (true) {
            try {
                $ch->statement('SELECT 1', withDatabase: false);
                break;
            } catch (Throwable $e) {
                if (now()->greaterThanOrEqualTo($deadline)) {
                    $this->error('ClickHouse no responde: '.mb_substr($e->getMessage(), 0, 200));

                    return self::FAILURE;
                }
                $this->line('Esperando a ClickHouse…');
                Sleep::for(2)->seconds();
            }
        }

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
