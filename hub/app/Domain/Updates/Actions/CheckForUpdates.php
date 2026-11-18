<?php

declare(strict_types=1);

namespace App\Domain\Updates\Actions;

use App\Domain\Updates\Support\ReleaseFeed;
use App\Domain\Updates\Support\UpdateState;
use App\Support\AppVersion;
use Throwable;

/**
 * Consulta la última versión publicada en GitHub Releases y la deja en `latest-release.json`
 * para el panel (docs/despliegue.md §5). La usan el comando programado y el botón «Buscar ahora»:
 * el botón no llama a Artisan, porque en la web los comandos de consola no están registrados.
 */
final class CheckForUpdates
{
    public function __construct(private readonly ReleaseFeed $feed, private readonly UpdateState $state) {}

    /** @return array{ok: bool, latest: string|null, current: string, newer: bool, error: string|null} */
    public function handle(): array
    {
        $current = AppVersion::current();
        try {
            $latest = $this->feed->latest();
        } catch (Throwable $e) {
            $previous = $this->state->latest() ?? [];
            $error = mb_substr($e->getMessage(), 0, 300);
            $this->state->write(UpdateState::LATEST, ['checked_at' => now()->toIso8601String(), 'error' => $error]
                + array_intersect_key($previous, array_flip(['version', 'tag', 'name', 'notes', 'published_at', 'html_url'])));

            return ['ok' => false, 'latest' => isset($previous['version']) ? (string) $previous['version'] : null, 'current' => $current, 'newer' => false, 'error' => $error];
        }
        $this->state->write(UpdateState::LATEST, $latest + ['checked_at' => now()->toIso8601String(), 'error' => null]);

        return ['ok' => true, 'latest' => $latest['version'], 'current' => $current, 'newer' => ReleaseFeed::isNewer($latest['version'], $current), 'error' => null];
    }
}
