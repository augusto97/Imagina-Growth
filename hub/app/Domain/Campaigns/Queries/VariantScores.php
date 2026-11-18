<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Queries;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use Illuminate\Support\Facades\DB;

/** Resultado por variante de A/B: lo usan la elección del ganador y el reporte. */
final class VariantScores
{
    /**
     * Clics y ingresos por variante entre los destinatarios con mensaje enviado.
     *
     * @return array<string, array{sent: int, clicks: int, click_rate: float, revenue: float, revenue_per_sent: float}>
     */
    public static function for(Campaign $campaign): array
    {
        $rows = DB::table('campaign_recipients as r')
            ->leftJoin('messages as m', 'm.id', '=', 'r.message_id')
            ->leftJoin('orders as o', function ($j) {
                $j->on('o.attributed_message_id', '=', 'r.message_id')->where('o.is_paid', true);
            })
            ->where('r.campaign_id', $campaign->id)->where('r.status', CampaignRecipient::SENT)->whereNotNull('r.variant')
            ->groupBy('r.variant')
            ->selectRaw('r.variant, COUNT(DISTINCT r.id) AS sent, COUNT(DISTINCT CASE WHEN m.clicked_at IS NOT NULL THEN r.id END) AS clicks, COALESCE(SUM(o.total_ws), 0) AS revenue')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $sent = (int) $row->sent;
            $out[(string) $row->variant] = [
                'sent' => $sent,
                'clicks' => (int) $row->clicks,
                'click_rate' => $sent > 0 ? round((int) $row->clicks / $sent, 4) : 0.0,
                'revenue' => round((float) $row->revenue, 2),
                'revenue_per_sent' => $sent > 0 ? round((float) $row->revenue / $sent, 2) : 0.0,
            ];
        }

        return $out;
    }
}
