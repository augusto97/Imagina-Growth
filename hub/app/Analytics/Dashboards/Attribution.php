<?php

declare(strict_types=1);

namespace App\Analytics\Dashboards;

use App\Analytics\Ch;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Modelos de atribución de M06 sobre los toques (sesiones) de cada cliente en
 * los 30 días antes del pedido: último clic (último toque no directo), primer
 * clic, lineal y por posición (40 % / 20 % / 40 %). Sin toques del tracker
 * (pedidos importados) se usa orders.channel_last o «unknown».
 */
final class Attribution
{
    public const MODELS = ['last_click', 'first_click', 'linear', 'position'];

    public const WINDOW_DAYS = 30;

    /** @var array<string, list<array{0: int, 1: string}>> contacto => [[ts, canal], …] */
    private array $touches = [];

    public function load(Workspace $workspace, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $rows = Ch::forWorkspace($workspace)->select(
            'SELECT contact_id, toUnixTimestamp(started_at) AS ts, channel FROM sessions FINAL
             WHERE workspace_id = {workspace_id:UInt32} AND contact_id != \'\' AND started_at >= {from:DateTime64(3)} AND started_at < {to:DateTime64(3)}
             ORDER BY contact_id, started_at LIMIT 1000000',
            ['from' => $from->subDays(self::WINDOW_DAYS)->utc()->format('Y-m-d H:i:s'), 'to' => $to->utc()->format('Y-m-d H:i:s')],
        );
        foreach ($rows as $r) {
            $this->touches[(string) $r['contact_id']][] = [(int) $r['ts'], (string) $r['channel']];
        }

        return $this;
    }

    /**
     * @return array<string, float> canal => peso (suma 1)
     */
    public function credit(?string $contactId, int $orderTs, ?string $fallback, string $model): array
    {
        $touches = $contactId === null ? [] : array_values(array_filter(
            $this->touches[$contactId] ?? [],
            fn ($t) => $t[0] <= $orderTs + 60 && $t[0] >= $orderTs - self::WINDOW_DAYS * 86400,
        ));
        if ($touches === []) {
            return [($fallback ?: 'unknown') => 1.0];
        }
        $channels = array_map(fn ($t) => $t[1], $touches);
        $n = count($channels);

        switch ($model) {
            case 'first_click':
                return [$channels[0] => 1.0];
            case 'linear':
                $out = [];
                foreach ($channels as $c) {
                    $out[$c] = ($out[$c] ?? 0) + 1 / $n;
                }

                return $out;
            case 'position':
                if ($n === 1) {
                    return [$channels[0] => 1.0];
                }
                if ($n === 2) {
                    return $channels[0] === $channels[1] ? [$channels[0] => 1.0] : [$channels[0] => 0.5, $channels[1] => 0.5];
                }
                $out = [$channels[0] => 0.4];
                $out[$channels[$n - 1]] = ($out[$channels[$n - 1]] ?? 0) + 0.4;
                foreach (array_slice($channels, 1, $n - 2) as $c) {
                    $out[$c] = ($out[$c] ?? 0) + 0.2 / ($n - 2);
                }

                return $out;
            default: // last_click: último toque que no sea directo
                $nonDirect = array_values(array_filter($channels, fn ($c) => $c !== 'direct'));

                return [($nonDirect === [] ? $channels[$n - 1] : $nonDirect[count($nonDirect) - 1]) => 1.0];
        }
    }

    /** Canal de adquisición del cliente: primer toque antes de su primer pedido. */
    public function firstTouch(string $contactId, int $orderTs): ?string
    {
        foreach ($this->touches[$contactId] ?? [] as $t) {
            if ($t[0] <= $orderTs + 60) {
                return $t[1];
            }
        }

        return null;
    }

    /**
     * Suma por canal y modelo.
     *
     * @param  Collection<int, \stdClass>  $orders  filas con contact_id, ts, total_ws, channel_last
     * @return array<string, array<string, array{revenue: float, orders: float}>> modelo => canal => totales
     */
    public function attribute(Collection $orders): array
    {
        $out = [];
        foreach ($orders as $o) {
            foreach (self::MODELS as $model) {
                foreach ($this->credit($o->contact_id ?? null, (int) $o->ts, $o->channel_last ?? null, $model) as $channel => $w) {
                    $out[$model][$channel]['revenue'] = ($out[$model][$channel]['revenue'] ?? 0) + $w * (float) $o->total_ws;
                    $out[$model][$channel]['orders'] = ($out[$model][$channel]['orders'] ?? 0) + $w;
                }
            }
        }

        return $out;
    }
}
