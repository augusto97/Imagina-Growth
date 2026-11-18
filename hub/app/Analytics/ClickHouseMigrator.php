<?php

declare(strict_types=1);

namespace App\Analytics;

use Illuminate\Support\Facades\File;

/**
 * Aplica database/clickhouse/*.sql en orden y registra cada archivo en
 * _migrations. Nunca edites un archivo ya aplicado: crea uno nuevo.
 */
final class ClickHouseMigrator
{
    public function __construct(private readonly Ch $ch) {}

    /** @return list<string> archivos aplicados en esta ejecución */
    public function migrate(): array
    {
        $db = $this->ch->database();
        $this->ch->statement('CREATE DATABASE IF NOT EXISTS '.$db, withDatabase: false);
        $this->ch->statement('CREATE TABLE IF NOT EXISTS _migrations (name String, applied_at DateTime DEFAULT now()) ENGINE = MergeTree ORDER BY name');

        $applied = array_column($this->ch->selectWithParams('SELECT name FROM _migrations', []), 'name');
        $ran = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            if (in_array($name, $applied, true)) {
                continue;
            }
            foreach ($this->statements((string) File::get($file)) as $sql) {
                $this->ch->statement($sql);
            }
            $this->ch->insert('_migrations', [['name' => $name]]);
            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * Borra y recrea la base (solo pruebas y entorno local).
     *
     * @return list<string>
     */
    public function fresh(): array
    {
        $this->ch->statement('DROP DATABASE IF EXISTS '.$this->ch->database(), withDatabase: false);

        return $this->migrate();
    }

    /** Vacía las tablas de datos (pruebas). */
    public function truncateAll(): void
    {
        foreach (['events', 'identity_map', 'sessions', 'kpi_daily', 'conversion_deliveries'] as $table) {
            $this->ch->statement('TRUNCATE TABLE IF EXISTS '.$table);
        }
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = glob(database_path('clickhouse/*.sql')) ?: [];
        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        $sql = (string) preg_replace('/^\s*--.*$/m', '', $sql);

        return array_values(array_filter(array_map('trim', explode(';', $sql)), fn ($s) => $s !== ''));
    }
}
