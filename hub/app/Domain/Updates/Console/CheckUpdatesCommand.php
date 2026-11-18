<?php

declare(strict_types=1);

namespace App\Domain\Updates\Console;

use App\Domain\Updates\Support\ReleaseFeed;
use App\Domain\Updates\Support\UpdateState;
use App\Support\AppVersion;
use Illuminate\Console\Command;
use Throwable;

/** Busca en GitHub Releases si hay una versión nueva y la deja para el panel (docs/despliegue.md §5). */
final class CheckUpdatesCommand extends Command
{
    protected $signature = 'growth:updates:check';

    protected $description = 'Consulta la última versión publicada en GitHub Releases y la guarda para el panel';

    public function handle(ReleaseFeed $feed, UpdateState $state): int
    {
        $current = AppVersion::current();
        try {
            $latest = $feed->latest();
        } catch (Throwable $e) {
            $previous = $state->latest() ?? [];
            $state->write(UpdateState::LATEST, ['checked_at' => now()->toIso8601String(), 'error' => mb_substr($e->getMessage(), 0, 300)]
                + array_intersect_key($previous, array_flip(['version', 'tag', 'name', 'notes', 'published_at', 'html_url'])));
            $this->warn('No se pudo consultar la última versión: '.$e->getMessage());

            return self::FAILURE;
        }
        $state->write(UpdateState::LATEST, $latest + ['checked_at' => now()->toIso8601String(), 'error' => null]);
        $this->info(ReleaseFeed::isNewer($latest['version'], $current)
            ? "Hay una versión nueva: {$latest['version']} (instalada: {$current})."
            : "Al día: la última publicada es {$latest['version']} (instalada: {$current}).");

        return self::SUCCESS;
    }
}
