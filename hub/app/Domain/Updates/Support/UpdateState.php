<?php

declare(strict_types=1);

namespace App\Domain\Updates\Support;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Los archivos que comparten el panel y growth-update en `shared/state` (docs/despliegue.md §5):
 *
 * - `latest-release.json`: la última versión publicada que vio `growth:updates:check`.
 * - `update-request.json`: el pedido que deja el panel (lo toma growth-update-dispatch).
 * - `update-request.processing.json`: el pedido ya aceptado, mientras corre la actualización. Si quedó de una
 *   actualización que ya terminó (o el servidor nunca tomó el pedido), no cuenta: ver `pending()`.
 * - `update-status.json` y `update-history.jsonl`: lo que escribe growth-update en cada paso.
 *
 * El panel solo escribe el pedido y la última versión vista; todo de forma atómica (temporal + rename).
 */
final class UpdateState
{
    public const LATEST = 'latest-release.json';

    public const REQUEST = 'update-request.json';

    public const PROCESSING = 'update-request.processing.json';

    public const STATUS = 'update-status.json';

    public const HISTORY = 'update-history.jsonl';

    public function path(string $file = ''): string
    {
        $dir = rtrim((string) config('growth.state_path'), '/');
        if ($dir !== '' && ! str_starts_with($dir, '/')) {
            $dir = base_path($dir); // relativa (desarrollo): desde la raíz del hub
        }

        return $file === '' ? $dir : $dir.'/'.$file;
    }

    /** @return array<string, mixed>|null */
    public function read(string $file): ?array
    {
        $path = $this->path($file);
        if (! is_file($path) || is_link($path) || ! is_readable($path) || filesize($path) > 1_000_000) {
            return null; // lo escribe root: si no se puede leer, el panel sigue funcionando
        }
        $raw = @file_get_contents($path);

        $data = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($data) ? $data : null;
    }

    /** @param array<string, mixed> $data */
    public function write(string $file, array $data): void
    {
        $dir = $this->path();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }
        $tmp = tempnam($dir, '.tmp.');
        if ($tmp === false || file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n") === false) {
            throw new RuntimeException("No se pudo escribir en {$dir}");
        }
        chmod($tmp, 0644);
        if (! rename($tmp, $this->path($file))) {
            @unlink($tmp);
            throw new RuntimeException("No se pudo guardar {$file}");
        }
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return $this->read(self::STATUS);
    }

    /** @return array<string, mixed>|null */
    public function latest(): ?array
    {
        return $this->read(self::LATEST);
    }

    /**
     * El pedido en espera o en proceso, si de verdad sigue vivo. Un pedido que ya terminó (el estado final es de ese
     * mismo pedido o es posterior a cuando se aceptó) o que el servidor nunca tomó (más de `request_stale_minutes`)
     * no cuenta: si no, el panel quedaba «en curso» para siempre y bloqueaba la siguiente actualización.
     *
     * @return array<string, mixed>|null
     */
    public function pending(): ?array
    {
        clearstatcache();
        $processing = $this->read(self::PROCESSING);
        if ($processing !== null && ! $this->finishedFor($processing) && ! $this->olderThan(self::PROCESSING, (int) config('growth.update.stale_after_minutes', 120))) {
            return $processing;
        }
        $request = $this->read(self::REQUEST);
        if ($request !== null && ! $this->olderThan(self::REQUEST, (int) config('growth.update.request_stale_minutes', 15))) {
            return $request;
        }

        return null;
    }

    /** ¿Hay un pedido en espera que el servidor nunca tomó? (El panel deja volver a pedir.) */
    public function staleRequest(): bool
    {
        clearstatcache();

        return is_file($this->path(self::REQUEST)) && $this->olderThan(self::REQUEST, (int) config('growth.update.request_stale_minutes', 15));
    }

    /** @param array<string, mixed> $pending */
    private function finishedFor(array $pending): bool
    {
        $status = $this->status();
        if (! in_array($status['state'] ?? null, ['succeeded', 'failed', 'rolled_back'], true)) {
            return false;
        }
        if (is_string($status['id'] ?? null) && ($status['id'] ?? null) === ($pending['id'] ?? null)) {
            return true;
        }
        $since = $pending['accepted_at'] ?? $pending['requested_at'] ?? null;
        $finished = $status['finished_at'] ?? null;

        return is_string($since) && is_string($finished) && CarbonImmutable::parse($finished)->greaterThanOrEqualTo(CarbonImmutable::parse($since));
    }

    private function olderThan(string $file, int $minutes): bool
    {
        $mtime = @filemtime($this->path($file));

        return $mtime !== false && CarbonImmutable::createFromTimestamp($mtime)->lessThan(now()->subMinutes($minutes));
    }

    /**
     * Las últimas actualizaciones, de la más nueva a la más vieja.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 10): array
    {
        $path = $this->path(self::HISTORY);
        $raw = is_file($path) && ! is_link($path) && is_readable($path) ? @file_get_contents($path) : false;
        if (! is_string($raw)) {
            return [];
        }
        $lines = array_slice(array_filter(explode("\n", $raw)), -$limit);
        $out = [];
        foreach (array_reverse($lines) as $line) {
            $row = json_decode($line, true);
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** ¿Hay un pedido o una actualización en curso? Una «en curso» sin cambios por horas se da por abandonada. */
    public function busy(): bool
    {
        if ($this->pending() !== null) {
            return true;
        }
        // Un pedido en proceso que no se puede leer (lo escribe root) se da por ocupado mientras sea reciente.
        if (is_file($this->path(self::PROCESSING)) && $this->read(self::PROCESSING) === null
            && ! $this->olderThan(self::PROCESSING, (int) config('growth.update.stale_after_minutes', 120))) {
            return true;
        }
        $status = $this->status();
        if (! in_array($status['state'] ?? null, ['queued', 'running'], true)) {
            return false;
        }
        $mtime = @filemtime($this->path(self::STATUS));

        return $mtime !== false && CarbonImmutable::createFromTimestamp($mtime)->greaterThan(now()->subMinutes((int) config('growth.update.stale_after_minutes', 120)));
    }
}
