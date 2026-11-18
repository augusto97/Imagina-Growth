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
 * - `update-request.processing.json`: el pedido ya aceptado, mientras corre la actualización.
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
        if (! is_file($path) || is_link($path) || filesize($path) > 1_000_000) {
            return null;
        }
        $data = json_decode((string) file_get_contents($path), true);

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

    /** @return array<string, mixed>|null el pedido en espera o en proceso */
    public function pending(): ?array
    {
        return $this->read(self::PROCESSING) ?? $this->read(self::REQUEST);
    }

    /**
     * Las últimas actualizaciones, de la más nueva a la más vieja.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 10): array
    {
        $path = $this->path(self::HISTORY);
        if (! is_file($path) || is_link($path)) {
            return [];
        }
        $lines = array_slice(array_filter(explode("\n", (string) file_get_contents($path))), -$limit);
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
        clearstatcache();
        if (is_file($this->path(self::REQUEST)) || is_file($this->path(self::PROCESSING))) {
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
