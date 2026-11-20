<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Actions;

use App\Domain\Messaging\Queries\MessageCosts;
use App\Support\Money\ExchangeRates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Retención de mensajes (R14, cumplimiento.md §10: 24 meses y después archivo agregado). La corre
 * `igr:retention:purge` cada noche, espacio de trabajo por espacio de trabajo y por lotes:
 *
 * 1. suma el lote en `message_archive_daily` (conteos por día, canal, dirección, categoría, origen, flujo y campaña, y
 *    el costo en USD; sin contacto, destino ni contenido);
 * 2. borra lo que depende de esos mensajes y lleva datos personales: `tracked_links` (URL y contacto; el enlace /c/ deja
 *    de redirigir) y `message_attachments` (descripción cifrada);
 * 3. borra los mensajes (destino, asunto y texto cifrados).
 *
 * Todo en una transacción por lote. `orders.attributed_message_id` y `campaign_recipients.message_id` se quedan (solo
 * son IDs): el pedido sigue con su flujo o campaña atribuidos y el dinero generado no cambia.
 *
 * **Atribución intacta:** un pedido se atribuye al mensaje con clic, lectura o apertura dentro de la ventana del espacio
 * de trabajo (`attribution.click_days`/`open_days`). Por eso el plazo nunca baja de 90 días ni de la ventana más larga
 * más 60 (los pagos contraentrega llegan semanas después del pedido), y un mensaje con actividad dentro de ese margen
 * (un clic tardío en un enlace viejo) no se borra aunque sea más viejo que el plazo.
 */
final class ArchiveOldMessages
{
    public const BATCH = 2000;

    /** Nunca salieron: no se archivan (sí se borran). */
    private const NOT_SENT = ['denied', 'skipped', 'deferred'];

    public function __construct(private readonly ExchangeRates $fx) {}

    /** Días que se protegen por la atribución: la ventana más larga de los espacios de trabajo más 60, mínimo 90. */
    public static function protectedDays(): int
    {
        $window = 5;
        foreach (DB::table('workspaces')->pluck('settings') as $settings) {
            $s = is_string($settings) ? json_decode($settings, true) : null;
            $a = is_array($s) && is_array($s['attribution'] ?? null) ? $s['attribution'] : [];
            $window = max($window, (int) ($a['click_days'] ?? 5), (int) ($a['open_days'] ?? 1));
        }

        return max(90, $window + 60);
    }

    /** Plazo efectivo en días (`igr.retention.messages`, 730 por defecto), nunca menor que el que protege la atribución. */
    public static function retentionDays(): int
    {
        return max((int) config('igr.retention.messages', 730), self::protectedDays());
    }

    /**
     * @return array{messages: int, tracked_links: int, message_attachments: int}
     */
    public function handle(bool $dryRun = false, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $cutoff = $now->subDays(self::retentionDays());
        $activeCutoff = $now->subDays(self::protectedDays());
        $totals = ['messages' => 0, 'tracked_links' => 0, 'message_attachments' => 0];
        foreach (DB::table('workspaces')->pluck('id') as $wsId) {
            $wsId = (string) $wsId;
            foreach (['out', 'in'] as $direction) {
                // Índice (workspace_id, direction, created_at).
                $old = fn () => DB::table('messages')->where('workspace_id', $wsId)->where('direction', $direction)->where('created_at', '<', $cutoff)
                    ->where(fn ($q) => $q->whereNull('updated_at')->orWhere('updated_at', '<', $activeCutoff));
                if ($dryRun) {
                    $totals['messages'] += $old()->count();

                    continue;
                }
                do {
                    $ids = array_values(array_map('strval', $old()->orderBy('created_at')->limit(self::BATCH)->pluck('id')->all()));
                    if ($ids === []) {
                        break;
                    }
                    $done = DB::transaction(fn () => $this->archive($wsId, $ids));
                    foreach ($done as $k => $n) {
                        $totals[$k] += $n;
                    }
                } while (count($ids) === self::BATCH);
            }
        }

        return $totals;
    }

    /**
     * @param  list<string>  $ids
     * @return array{messages: int, tracked_links: int, message_attachments: int}
     */
    private function archive(string $wsId, array $ids): array
    {
        $billable = MessageCosts::BILLABLE;
        $rows = DB::table('messages as m')->where('m.workspace_id', $wsId)->whereIn('m.id', $ids)->whereNotIn('m.status', self::NOT_SENT)
            ->selectRaw("DATE(m.created_at) AS d, m.channel AS ch, m.direction AS dir, m.kind AS k, m.source_type AS st,
                COALESCE(m.flow_id, '') AS f_id, COALESCE(m.campaign_id, '') AS c_id, COALESCE(m.cost_currency, 'USD') AS cur,
                COUNT(*) AS total,
                SUM(CASE WHEN m.sent_at IS NOT NULL OR m.status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN m.delivered_at IS NOT NULL OR m.status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN m.read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN m.opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                SUM(CASE WHEN m.clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN m.replied_at IS NOT NULL THEN 1 ELSE 0 END) AS replied,
                SUM(CASE WHEN m.status = 'bounced' THEN 1 ELSE 0 END) AS bounced,
                SUM(CASE WHEN m.status = 'failed' THEN 1 ELSE 0 END) AS failed,
                SUM({$billable}) AS cost")
            ->groupByRaw('d, ch, dir, k, st, f_id, c_id, cur')
            ->get();
        $now = now();
        foreach ($rows as $r) {
            $cost = (float) $r->cost;
            $usd = $cost > 0.0 ? ($this->fx->convert($cost, (string) $r->cur, ExchangeRates::BASE, (string) $r->d) ?? 0.0) : 0.0;
            // Suma (no reemplaza): el mismo día puede llegar en varios lotes.
            DB::statement(
                'INSERT INTO message_archive_daily (workspace_id, date, channel, direction, kind, source_type, flow_id, campaign_id,
                    total, sent, delivered, read_count, opened, clicked, replied, bounced, failed, cost_usd, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE total = total + VALUES(total), sent = sent + VALUES(sent), delivered = delivered + VALUES(delivered),
                    read_count = read_count + VALUES(read_count), opened = opened + VALUES(opened), clicked = clicked + VALUES(clicked),
                    replied = replied + VALUES(replied), bounced = bounced + VALUES(bounced), failed = failed + VALUES(failed),
                    cost_usd = cost_usd + VALUES(cost_usd), updated_at = VALUES(updated_at)',
                [$wsId, (string) $r->d, (string) $r->ch, (string) $r->dir, (string) $r->k, (string) $r->st, (string) $r->f_id, (string) $r->c_id,
                    (int) $r->total, (int) $r->sent, (int) $r->delivered, (int) $r->read_count, (int) $r->opened, (int) $r->clicked, (int) $r->replied,
                    (int) $r->bounced, (int) $r->failed, round($usd, 5), $now, $now],
            );
        }

        return [
            'tracked_links' => DB::table('tracked_links')->where('workspace_id', $wsId)->whereIn('message_id', $ids)->delete(),
            'message_attachments' => DB::table('message_attachments')->where('workspace_id', $wsId)->whereIn('message_id', $ids)->delete(),
            'messages' => DB::table('messages')->where('workspace_id', $wsId)->whereIn('id', $ids)->delete(),
        ];
    }
}
