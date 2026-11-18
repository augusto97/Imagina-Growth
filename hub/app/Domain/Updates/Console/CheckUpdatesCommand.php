<?php

declare(strict_types=1);

namespace App\Domain\Updates\Console;

use App\Domain\Updates\Actions\CheckForUpdates;
use Illuminate\Console\Command;

/** Busca en GitHub Releases si hay una versión nueva y la deja para el panel (docs/despliegue.md §5). */
final class CheckUpdatesCommand extends Command
{
    protected $signature = 'growth:updates:check';

    protected $description = 'Consulta la última versión publicada en GitHub Releases y la guarda para el panel';

    public function handle(CheckForUpdates $check): int
    {
        $result = $check->handle();
        if (! $result['ok']) {
            $this->warn('No se pudo consultar la última versión: '.$result['error']);

            return self::FAILURE;
        }
        $this->info($result['newer']
            ? "Hay una versión nueva: {$result['latest']} (instalada: {$result['current']})."
            : "Al día: la última publicada es {$result['latest']} (instalada: {$result['current']}).");

        return self::SUCCESS;
    }
}
