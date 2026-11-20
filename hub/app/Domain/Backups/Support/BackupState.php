<?php

declare(strict_types=1);

namespace App\Domain\Backups\Support;

use App\Domain\Updates\Support\UpdateState;
use RuntimeException;

/**
 * Los archivos de las copias en `shared/state` (docs/despliegue.md §6), los mismos que comparte el actualizador:
 *
 * - `backup-request.json`: el pedido que deja el panel (0600, del usuario growth; puede traer de paso la contraseña
 *   del Storage Box o la frase de cifrado). Lo toma `growth-backup-dispatch` (root) en cuanto aparece y lo borra.
 * - `backup-status.json`: lo que publica el despachador (destino, llave pública, último pedido y última prueba).
 *   Nunca trae secretos: de cada uno solo dice si está.
 * - `backup-job-{mariadb,clickhouse,verify}.json`: el resultado de cada copia y de la prueba de restauración.
 *
 * El panel solo escribe el pedido, de forma atómica (temporal + rename); lo demás lo escribe root.
 */
final class BackupState
{
    public const REQUEST = 'backup-request.json';

    public const STATUS = 'backup-status.json';

    public const JOBS = ['mariadb', 'clickhouse', 'verify'];

    public function __construct(private readonly UpdateState $files) {}

    public function path(string $file = ''): string
    {
        return $this->files->path($file);
    }

    /** @return array<string, mixed>|null */
    public function status(): ?array
    {
        return $this->files->read(self::STATUS);
    }

    /** @return array<string, mixed>|null */
    public function job(string $job): ?array
    {
        return in_array($job, self::JOBS, true) ? $this->files->read("backup-job-{$job}.json") : null;
    }

    /**
     * El pedido que todavía no tomó root, sin nada secreto. `stale`: lleva más de unos minutos sin que nadie lo
     * tome (el servidor no tiene el despachador, o está apagado), así que no bloquea un pedido nuevo.
     *
     * @return array{id: string|null, action: string|null, requested_at: string|null, stale: bool}|null
     */
    public function pending(): ?array
    {
        $path = $this->path(self::REQUEST);
        clearstatcache(true, $path);
        if (! is_file($path) || is_link($path)) {
            return null;
        }
        $mtime = @filemtime($path);
        $raw = filesize($path) <= 65536 ? @file_get_contents($path) : false;
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'id' => is_string($data['id'] ?? null) ? $data['id'] : null,
            'action' => is_string($data['action'] ?? null) ? $data['action'] : null,
            'requested_at' => is_string($data['requested_at'] ?? null) ? $data['requested_at'] : null,
            'stale' => $mtime === false || $mtime < now()->subMinutes($this->staleMinutes())->getTimestamp(),
        ];
    }

    /** ¿Hay un pedido esperando o uno que root está atendiendo? */
    public function busy(): bool
    {
        $pending = $this->pending();
        if ($pending !== null) {
            return ! $pending['stale'];
        }
        $status = $this->status();
        $last = is_array($status['last_request'] ?? null) ? $status['last_request'] : [];
        if (($last['state'] ?? null) !== 'running') {
            return false;
        }
        $mtime = @filemtime($this->path(self::STATUS));

        return $mtime !== false && $mtime > now()->subMinutes($this->staleMinutes())->getTimestamp();
    }

    /**
     * Deja el pedido para root: 0600 (solo growth lo puede leer mientras espera) y con un temporal + rename, así el
     * despachador nunca ve uno a medias. Un pedido viejo que nadie tomó se reemplaza.
     *
     * @param  array<string, mixed>  $request
     */
    public function writeRequest(array $request): void
    {
        $dir = $this->path();
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("No se pudo crear {$dir}");
        }
        $tmp = tempnam($dir, '.tmp.');
        if ($tmp === false) {
            throw new RuntimeException("No se pudo escribir en {$dir}");
        }
        chmod($tmp, 0600);
        $json = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json."\n") === false || ! rename($tmp, $this->path(self::REQUEST))) {
            @unlink($tmp);
            throw new RuntimeException('No se pudo guardar el pedido de las copias');
        }
    }

    private function staleMinutes(): int
    {
        return max(1, (int) config('growth.backups.stale_after_minutes', 10));
    }
}
