<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Queries;

use App\Support\Money\ExchangeRates;
use Illuminate\Database\Query\Builder;

/**
 * Costo estimado de los mensajes y su retorno (F11), en la moneda del espacio de trabajo. Lo usan el tablero de
 * Mensajería, el informe de cada campaña y la analítica de cada flujo.
 *
 * - **Costo:** `messages.cost_estimated` (en `cost_currency`, hoy siempre USD) que se guarda al encolar el mensaje
 *   (tarifa de Meta por país y categoría, SES o Twilio). Cuenta lo que el proveedor cobra: en WhatsApp los mensajes
 *   entregados (Meta cobra por mensaje entregado); en correo, SMS y redes, los enviados. Un mensaje sin costo estimado
 *   (SMTP propio) no suma.
 * - **Conversión:** con la tasa del día de cada mensaje (ExchangeRates: la manual, la automática o la última conocida).
 *   Sin tasa para la moneda, el costo local queda en null y se muestra en USD.
 * - **Retorno** = ventas atribuidas ÷ costo; **costo por venta** = costo ÷ pedidos atribuidos. Todo es estimado.
 */
final class MessageCosts
{
    /** Costo que el proveedor cobra del mensaje (sobre `messages as m`). */
    public const BILLABLE = "CASE WHEN m.cost_estimated IS NULL THEN 0
        WHEN m.channel = 'whatsapp' THEN CASE WHEN m.delivered_at IS NOT NULL OR m.status IN ('delivered', 'read') THEN m.cost_estimated ELSE 0 END
        WHEN m.sent_at IS NOT NULL OR m.status IN ('sent', 'delivered', 'read') THEN m.cost_estimated ELSE 0 END";

    public function __construct(private readonly ExchangeRates $fx) {}

    /**
     * Suma el costo por clave (o el total con `$groupExpr` null), convertido día por día.
     *
     * @param  Builder  $messages  consulta sobre `messages as m` (o sobre `message_archive_daily as m` con `$archived`),
     *                             ya filtrada por espacio de trabajo
     * @param  literal-string|null  $groupExpr  expresión SQL de la clave sobre el alias `m`
     * @return array<string, array{usd: float, local: float|null}> clave → costo ('' es el total)
     */
    public function sum(Builder $messages, string $currency, ?string $groupExpr = null, bool $archived = false): array
    {
        $select = $archived
            ? "m.date AS cost_day, 'USD' AS cost_cur, SUM(m.cost_usd) AS cost"
            : "DATE(m.created_at) AS cost_day, COALESCE(m.cost_currency, 'USD') AS cost_cur, SUM(".self::BILLABLE.') AS cost';
        $rows = (clone $messages)
            ->selectRaw(($groupExpr ?? "''")." AS cost_key, {$select}")
            ->groupByRaw('cost_key, cost_day, cost_cur')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $key = (string) $row->cost_key;
            $amount = (float) $row->cost;
            $out[$key] ??= ['usd' => 0.0, 'local' => 0.0];
            if ($amount <= 0.0) {
                continue;
            }
            $day = (string) $row->cost_day;
            $cur = strtoupper((string) $row->cost_cur);
            $usd = $this->fx->convert($amount, $cur, ExchangeRates::BASE, $day);
            $local = $this->fx->convert($amount, $cur, $currency, $day);
            $out[$key]['usd'] += $usd ?? 0.0;
            $out[$key]['local'] = $local === null || $out[$key]['local'] === null ? null : $out[$key]['local'] + $local;
        }

        return $out;
    }

    /**
     * Suma dos resultados de `sum()` (lo vivo y lo archivado).
     *
     * @param  array<string, array{usd: float, local: float|null}>  $a
     * @param  array<string, array{usd: float, local: float|null}>  $b
     * @return array<string, array{usd: float, local: float|null}>
     */
    public static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $cost) {
            $prev = $a[$key] ?? ['usd' => 0.0, 'local' => 0.0];
            $a[$key] = ['usd' => $prev['usd'] + $cost['usd'], 'local' => $prev['local'] === null || $cost['local'] === null ? null : $prev['local'] + $cost['local']];
        }

        return $a;
    }

    /**
     * Costo, retorno y costo por venta de una fila.
     *
     * @param  array{usd: float, local: float|null}|null  $cost
     * @return array{cost: float|null, cost_usd: float, roi: float|null, cost_per_sale: float|null}
     */
    public static function returns(?array $cost, float $revenue, int $orders): array
    {
        $cost ??= ['usd' => 0.0, 'local' => 0.0];
        $local = $cost['local'];

        return [
            'cost' => $local === null ? null : round($local, 2),
            'cost_usd' => round($cost['usd'], 4),
            'roi' => $local !== null && $local > 0.0 ? round($revenue / $local, 2) : null,
            'cost_per_sale' => $local !== null && $local > 0.0 && $orders > 0 ? round($local / $orders, 2) : null,
        ];
    }

    /**
     * Para mostrar junto al costo: la moneda, que es estimado y la tasa que rige hoy (con la fuente que hay que citar).
     *
     * @return array{currency: string, estimated: true, fx: array{rate: float|null, date: string|null, source: string|null, manual: bool, stale: bool}, attribution_url: string|null}
     */
    public function meta(string $currency): array
    {
        $d = $this->fx->describe($currency);

        return [
            'currency' => strtoupper($currency),
            'estimated' => true,
            'fx' => ['rate' => $d['rate'], 'date' => $d['date'], 'source' => $d['source'], 'manual' => $d['manual'], 'stale' => $d['stale']],
            'attribution_url' => $d['source'] !== null && ! $d['manual'] ? (string) config('igr.fx.attribution_url') : null,
        ];
    }
}
