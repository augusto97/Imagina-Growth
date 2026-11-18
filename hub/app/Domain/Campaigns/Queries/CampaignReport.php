<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Queries;

use App\Analytics\Ch;
use App\Domain\Campaigns\Actions\CampaignSender;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reporte de una campaña (modulos.md M10): entrega, interacción, ingresos
 * atribuidos e incremento frente al grupo de control.
 *
 * - Aperturas: solo las reales (`message.opened` con `is_mpp = false` en
 *   ClickHouse); sin ClickHouse, la columna `opened_at`.
 * - Ingresos: pedidos pagados con `attributed_campaign_id` (los atribuye
 *   AttributeOrder por clic en el mensaje).
 * - Incremento: conversión (pedido pagado en los N días de la ventana de
 *   atribución desde el inicio) de los destinatarios frente al grupo de control,
 *   con intervalo de confianza del 95% (aproximación normal). Es por intención de
 *   tratar: cuentan todos los asignados, aunque la política no les haya enviado.
 */
final class CampaignReport
{
    public function __construct(private readonly CampaignSender $sender) {}

    /** @return array<string, mixed> */
    public function handle(Workspace $workspace, Campaign $campaign): array
    {
        $recipients = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->selectRaw('status, is_holdout, COUNT(*) AS n')->groupBy('status', 'is_holdout')->get();
        $byStatus = [];
        $holdout = 0;
        $total = 0;
        foreach ($recipients as $row) {
            $n = (int) $row->getAttribute('n');
            $total += $n;
            $holdout += $row->getAttribute('is_holdout') ? $n : 0;
            $status = (string) $row->getAttribute('status');
            $byStatus[$status] = ($byStatus[$status] ?? 0) + $n;
        }
        $reasons = CampaignRecipient::query()->where('campaign_id', $campaign->id)->whereIn('status', [CampaignRecipient::DENIED, CampaignRecipient::DEFERRED])
            ->selectRaw('status, reason, COUNT(*) AS n')->groupBy('status', 'reason')->get()
            ->map(fn ($r) => ['status' => (string) $r->getAttribute('status'), 'reason' => (string) $r->getAttribute('reason'), 'count' => (int) $r->getAttribute('n')])->values()->all();

        $messages = DB::table('messages')->where('workspace_id', $workspace->id)->where('campaign_id', $campaign->id)
            ->selectRaw("COUNT(*) AS queued,
                SUM(CASE WHEN sent_at IS NOT NULL OR status IN ('sent','delivered','read') THEN 1 ELSE 0 END) AS sent,
                SUM(CASE WHEN delivered_at IS NOT NULL OR status IN ('delivered','read') THEN 1 ELSE 0 END) AS delivered,
                SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END) AS read_count,
                SUM(CASE WHEN opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened_column,
                SUM(CASE WHEN clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked,
                SUM(CASE WHEN status IN ('failed','bounced') THEN 1 ELSE 0 END) AS failed")
            ->first();
        $events = $this->events($workspace, $campaign);
        $orders = DB::table('orders')->where('workspace_id', $workspace->id)->where('attributed_campaign_id', $campaign->id)->where('is_paid', true)
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS revenue')->first();

        $sent = (int) ($messages->sent ?? 0);
        $clicked = (int) ($messages->clicked ?? 0);
        $opened = $events['opened'] ?? (int) ($messages->opened_column ?? 0);

        return [
            'currency' => $workspace->currency,
            'recipients' => ['total' => $total, 'holdout' => $holdout, 'by_status' => $byStatus, 'reasons' => $reasons],
            'delivery' => [
                'queued' => (int) ($messages->queued ?? 0),
                'sent' => $sent,
                'delivered' => (int) ($messages->delivered ?? 0),
                'read' => (int) ($messages->read_count ?? 0),
                'opened' => $opened,
                'opened_excludes_mpp' => isset($events['opened']),
                'clicked' => $clicked,
                'unsubscribed' => $events['unsubscribed'] ?? 0,
                'failed' => (int) ($messages->failed ?? 0),
                'click_rate' => $sent > 0 ? round($clicked / $sent, 4) : null,
            ],
            'revenue' => ['orders' => (int) ($orders->n ?? 0), 'total' => round((float) ($orders->revenue ?? 0), 2)],
            'variants' => $this->sender->variantScores($campaign),
            'winner_variant' => $campaign->winner_variant,
            'uplift' => $this->uplift($workspace, $campaign),
        ];
    }

    /**
     * Aperturas reales y bajas desde ClickHouse.
     *
     * @return array{opened?: int, unsubscribed?: int}
     */
    private function events(Workspace $workspace, Campaign $campaign): array
    {
        try {
            $rows = Ch::forWorkspace($workspace)->select(
                "SELECT event, uniqExact(toString(properties.message_id)) AS n FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND event IN ('message.opened', 'message.unsubscribed')
                   AND toString(properties.campaign_id) = {campaign:String}
                   AND NOT (event = 'message.opened' AND toString(properties.is_mpp) = 'true')
                 GROUP BY event",
                ['campaign' => $campaign->id],
            );
        } catch (Throwable) {
            return [];
        }
        $out = ['opened' => 0, 'unsubscribed' => 0];
        foreach ($rows as $row) {
            $out[$row['event'] === 'message.opened' ? 'opened' : 'unsubscribed'] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * Incremento frente al grupo de control (null si no hay control o no empezó).
     *
     * @return array<string, mixed>|null
     */
    private function uplift(Workspace $workspace, Campaign $campaign): ?array
    {
        if ($campaign->holdout_pct <= 0 || $campaign->started_at === null) {
            return null;
        }
        $days = max(1, (int) $workspace->setting('attribution.click_days', 5));
        $from = CarbonImmutable::instance($campaign->started_at);
        $to = $from->addDays($days);
        $groups = DB::table('campaign_recipients as r')
            ->leftJoin('orders as o', function ($j) use ($workspace, $from, $to) {
                $j->on('o.contact_id', '=', 'r.contact_id')->where('o.workspace_id', $workspace->id)
                    ->where('o.is_paid', true)->where('o.created_at', '>=', $from)->where('o.created_at', '<', $to);
            })
            ->where('r.campaign_id', $campaign->id)
            ->groupBy('r.is_holdout')
            ->selectRaw('r.is_holdout, COUNT(DISTINCT r.contact_id) AS n, COUNT(DISTINCT o.contact_id) AS converted, COALESCE(SUM(o.total_ws), 0) AS revenue')
            ->get()->keyBy(fn ($r) => (int) $r->is_holdout);
        $t = $groups->get(0);
        $c = $groups->get(1);
        if ($t === null || $c === null || (int) $c->n === 0 || (int) $t->n === 0) {
            return null;
        }

        return self::compare((int) $t->n, (int) $t->converted, (float) $t->revenue, (int) $c->n, (int) $c->converted, (float) $c->revenue) + [
            'window_days' => $days,
            'complete' => $to->lessThanOrEqualTo(CarbonImmutable::now()),
        ];
    }

    /**
     * Diferencia de conversión con IC del 95% (normal) e ingreso por destinatario.
     *
     * @return array<string, mixed>
     */
    public static function compare(int $nT, int $convT, float $revT, int $nC, int $convC, float $revC): array
    {
        $pT = $convT / $nT;
        $pC = $convC / $nC;
        $diff = $pT - $pC;
        $se = sqrt(($pT * (1 - $pT)) / $nT + ($pC * (1 - $pC)) / $nC);
        $low = $diff - 1.96 * $se;
        $high = $diff + 1.96 * $se;

        return [
            'treatment' => ['recipients' => $nT, 'converted' => $convT, 'rate' => round($pT, 4), 'revenue_per_recipient' => round($revT / $nT, 2)],
            'control' => ['recipients' => $nC, 'converted' => $convC, 'rate' => round($pC, 4), 'revenue_per_recipient' => round($revC / $nC, 2)],
            'lift' => round($diff, 4),
            'lift_relative' => $pC > 0 ? round($diff / $pC, 4) : null,
            'ci_low' => round($low, 4),
            'ci_high' => round($high, 4),
            'significant' => $low > 0 || $high < 0,
            'incremental_revenue' => round(($revT / $nT - $revC / $nC) * $nT, 2),
        ];
    }
}
