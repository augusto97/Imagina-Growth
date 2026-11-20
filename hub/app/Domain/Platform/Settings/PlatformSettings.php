<?php

declare(strict_types=1);

namespace App\Domain\Platform\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Dónde viven los ajustes de la plataforma (F17): la tabla `platform_settings`, con los secretos cifrados con la llave
 * de la app, y una copia en la caché (todavía cifrada) con su versión. Cada proceso compara esa versión en cada
 * petición y antes de cada trabajo (`PlatformSettingsRuntime`), así un cambio llega a todos sin reiniciar nada.
 *
 * Si la tabla todavía no existe (instalación, migraciones) o la base no responde, no hay ajustes: rige el .env.
 */
final class PlatformSettings
{
    public const VERSION_KEY = 'igr:platform-settings:version';

    public const ROWS_KEY = 'igr:platform-settings:rows';

    /** Fila sin ajuste: cambia cuando cambia algo que los procesos tienen en memoria (festivos). */
    public const GENERATION = '_generation';

    /** La copia en la caché se renueva sola cada tanto, por si dos procesos escribieron a la vez. */
    private const TTL_SECONDS = 300;

    /** Versión de los ajustes guardados (cambia con cualquier fila), o null si no se pudo leer. */
    public function version(): ?string
    {
        try {
            $version = Cache::get(self::VERSION_KEY);
            if (is_string($version) && $version !== '') {
                return $version;
            }
        } catch (Throwable) {
            // Sin caché: se lee de la base.
        }

        return $this->rebuild()['version'] ?? null;
    }

    /**
     * Las filas guardadas tal cual (los secretos siguen cifrados), con su versión.
     *
     * @return array{version: string, rows: array<string, array{value: string|null, encrypted: bool}>}|null
     */
    public function snapshot(): ?array
    {
        try {
            // Una sola ida a Redis (MGET): esto corre al arrancar cada petición.
            $cached = Cache::many([self::ROWS_KEY, self::VERSION_KEY]);
            $rows = $cached[self::ROWS_KEY] ?? null;
            $version = $cached[self::VERSION_KEY] ?? null;
            if (is_array($rows) && is_string($version) && isset($rows['version'], $rows['rows']) && $rows['version'] === $version) {
                /** @var array{version: string, rows: array<string, array{value: string|null, encrypted: bool}>} $rows */
                return $rows;
            }
        } catch (Throwable) {
            // Sin caché: se lee de la base.
        }

        return $this->rebuild();
    }

    /**
     * Los valores del panel ya descifrados, solo de las claves del registro.
     *
     * @param  array{version: string, rows: array<string, array{value: string|null, encrypted: bool}>}|null  $snapshot
     * @return array<string, mixed>
     */
    public function values(?array $snapshot = null): array
    {
        $snapshot ??= $this->snapshot();
        $out = [];
        foreach ($snapshot['rows'] ?? [] as $key => $row) {
            if (PlatformSettingsRegistry::find($key) === null) {
                continue;
            }
            $value = $this->decode($key, $row);
            if (SettingDefinition::filled($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * Guarda los valores (null = vacío: vuelve al .env). Devuelve qué claves cambiaron, nunca sus valores.
     *
     * @param  array<string, mixed>  $values  clave del registro => valor ya validado y convertido
     * @return array{set: list<string>, cleared: list<string>}
     */
    public function save(array $values, ?string $userId = null): array
    {
        $current = $this->values($this->rebuild() ?? ['version' => '', 'rows' => []]);
        $set = [];
        $cleared = [];
        DB::transaction(function () use ($values, $userId, $current, &$set, &$cleared): void {
            foreach ($values as $key => $value) {
                $definition = PlatformSettingsRegistry::find($key);
                if ($definition === null) {
                    continue;
                }
                if (! SettingDefinition::filled($value)) {
                    if (array_key_exists($key, $current)) {
                        DB::table('platform_settings')->where('key', $key)->delete();
                        $cleared[] = $key;
                    }

                    continue;
                }
                if (array_key_exists($key, $current) && $current[$key] === $value) {
                    continue;
                }
                $json = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $exists = array_key_exists($key, $current) || DB::table('platform_settings')->where('key', $key)->exists();
                DB::table('platform_settings')->updateOrInsert(['key' => $key], [
                    'value' => $definition->secret() ? Crypt::encryptString($json) : $json,
                    'encrypted' => $definition->secret(),
                    'updated_by' => $userId,
                    'updated_at' => now(),
                ] + ($exists ? [] : ['created_at' => now()]));
                $set[] = $key;
            }
        });
        if ($set !== [] || $cleared !== []) {
            $this->rebuild();
        }

        return ['set' => $set, 'cleared' => $cleared];
    }

    /** Avisa a todos los procesos que algo que tienen en memoria cambió (p. ej. los festivos), sin tocar ningún ajuste. */
    public function touch(): void
    {
        try {
            DB::table('platform_settings')->updateOrInsert(['key' => self::GENERATION], [
                'value' => json_encode(bin2hex(random_bytes(8))), 'encrypted' => false, 'updated_at' => now(), 'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('igr.platform_settings.touch_failed', ['error' => mb_substr($e->getMessage(), 0, 200)]);

            return;
        }
        $this->rebuild();
    }

    /**
     * Lee la tabla y deja la copia en la caché. null si la tabla no existe todavía o la base no responde.
     *
     * @return array{version: string, rows: array<string, array{value: string|null, encrypted: bool}>}|null
     */
    public function rebuild(): ?array
    {
        try {
            $rows = [];
            foreach (DB::table('platform_settings')->orderBy('key')->get(['key', 'value', 'encrypted']) as $row) {
                $rows[(string) $row->key] = ['value' => $row->value === null ? null : (string) $row->value, 'encrypted' => (bool) $row->encrypted];
            }
        } catch (Throwable) {
            return null;
        }
        $snapshot = ['version' => sha1((string) json_encode($rows)), 'rows' => $rows];
        try {
            Cache::put(self::ROWS_KEY, $snapshot, self::TTL_SECONDS);
            Cache::put(self::VERSION_KEY, $snapshot['version'], self::TTL_SECONDS);
        } catch (Throwable) {
            // Sin caché funciona igual, leyendo la base.
        }

        return $snapshot;
    }

    /** @param array{value: string|null, encrypted: bool} $row */
    private function decode(string $key, array $row): mixed
    {
        if ($row['value'] === null) {
            return null;
        }
        try {
            $json = $row['encrypted'] ? Crypt::decryptString($row['value']) : $row['value'];
        } catch (DecryptException) {
            // Otra llave de la app (APP_KEY cambió): el valor no sirve; nunca se registra.
            Log::warning('igr.platform_settings.decrypt_failed', ['key' => $key]);

            return null;
        }

        return json_decode($json, true);
    }
}
