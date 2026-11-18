<?php

declare(strict_types=1);

namespace App\Analytics;

use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Cliente HTTP mínimo de ClickHouse (interfaz HTTP nativa).
 *
 * Regla (hub/CLAUDE.md §4 y modelo-de-datos.md §10): las consultas de datos
 * solo existen a través de forWorkspace(), que exige el parámetro
 * {workspace_id:UInt32} en el SQL y lo enlaza siempre. Las operaciones de
 * plataforma (migraciones, inserciones del worker) usan métodos explícitos.
 */
final class Ch
{
    public function __construct(
        private readonly string $url,
        private readonly string $database,
        private readonly string $username,
        private readonly string $password,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        /** @var array{url: string, database: string, username: string, password: string, timeout: int} $c */
        $c = config('igr.clickhouse');

        return new self($c['url'], $c['database'], $c['username'], (string) $c['password'], (int) $c['timeout']);
    }

    public static function forWorkspace(Workspace|int $workspace): ChWorkspaceQuery
    {
        $chId = $workspace instanceof Workspace ? $workspace->ch_id : $workspace;

        return new ChWorkspaceQuery(app(self::class), $chId);
    }

    public function database(): string
    {
        return $this->database;
    }

    /**
     * Inserta filas con JSONEachRow. Solo para el worker de ingesta y procesos de plataforma.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function insert(string $table, array $rows, bool $async = false): void
    {
        if ($rows === []) {
            return;
        }
        $body = '';
        foreach ($rows as $row) {
            $body .= json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        }
        $settings = ['input_format_skip_unknown_fields' => 1, 'date_time_input_format' => 'best_effort'];
        if ($async) {
            $settings += ['async_insert' => 1, 'wait_for_async_insert' => 1];
        }
        $this->request(
            'INSERT INTO '.$this->ident($table).' FORMAT JSONEachRow',
            $settings,
            $body,
        );
    }

    /**
     * Ejecuta DDL o mantenimiento (migraciones, OPTIMIZE). Nunca para leer datos de clientes.
     */
    public function statement(string $sql, bool $withDatabase = true): void
    {
        $this->request($sql, [], null, $withDatabase);
    }

    /**
     * Uso interno: lo llama ChWorkspaceQuery con el parámetro de workspace ya enlazado.
     *
     * @param  array<string, scalar|null>  $params
     * @return list<array<string, mixed>>
     */
    public function selectWithParams(string $sql, array $params): array
    {
        $settings = [];
        foreach ($params as $name => $value) {
            $settings['param_'.$name] = $value === null ? '\N' : (is_bool($value) ? (int) $value : $value);
        }
        $raw = $this->request($sql.' FORMAT JSONEachRow', $settings + ['output_format_json_quote_64bit_integers' => 0, 'output_format_json_quote_decimals' => 1]);
        $rows = [];
        foreach (explode("\n", trim($raw)) as $line) {
            if ($line !== '') {
                $rows[] = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $rows;
    }

    public function ping(): bool
    {
        try {
            return trim($this->http()->get($this->url.'/ping')->body()) === 'Ok.';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, scalar>  $settings
     */
    private function request(string $query, array $settings = [], ?string $body = null, bool $withDatabase = true): string
    {
        $qs = $settings;
        if ($withDatabase) {
            $qs['database'] = $this->database;
        }
        $qs['query'] = $query;
        $url = $this->url.'/?'.http_build_query($qs);

        $response = $body === null
            ? $this->http()->withBody('', 'text/plain')->post($url)
            : $this->http()->withBody($body, 'application/x-ndjson')->post($url);

        if (! $response->successful()) {
            throw new RuntimeException('ClickHouse: '.mb_substr(trim($response->body()), 0, 1000));
        }

        return $response->body();
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->withHeaders(['X-ClickHouse-User' => $this->username, 'X-ClickHouse-Key' => $this->password]);
    }

    private function ident(string $name): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/', $name)) {
            throw new RuntimeException('Nombre de tabla inválido: '.$name);
        }

        return $name;
    }
}
