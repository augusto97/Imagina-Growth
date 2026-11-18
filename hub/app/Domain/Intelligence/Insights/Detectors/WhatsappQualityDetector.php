<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * #16 Calidad del número de WhatsApp bajando (Meta la marca en amarillo o rojo)
 * o una campaña con bloqueos y bajas altos (≥ 2 % de lo enviado, con al menos 50
 * envíos en 14 días). Un número de baja calidad pierde límite de envío.
 */
final class WhatsappQualityDetector implements Detector
{
    private const LOW = ['YELLOW' => 'warning', 'MEDIUM' => 'warning', 'RED' => 'critical', 'LOW' => 'critical', 'FLAGGED' => 'critical'];

    public function key(): string
    {
        return 'whatsapp_quality';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $out = [];
        $accounts = DB::table('channel_accounts')->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->where('status', '!=', 'disconnected')
            ->get(['id', 'display_name', 'quality_rating', 'messaging_limit_tier']);
        foreach ($accounts as $a) {
            $rating = strtoupper((string) $a->quality_rating);
            if (! isset(self::LOW[$rating])) {
                continue;
            }
            $out[] = new InsightCandidate(
                fingerprint: 'account:'.$a->id,
                severity: self::LOW[$rating],
                title: __('La calidad de tu número de WhatsApp :name bajó a :rating', ['name' => (string) $a->display_name, 'rating' => __('insight.quality.'.$rating)]),
                facts: ['account' => (string) $a->display_name, 'quality_rating' => $rating, 'messaging_limit_tier' => (string) $a->messaging_limit_tier],
                assumptions: [__('Baja los envíos de marketing, revisa los textos y envía solo a quien lo pidió hasta que vuelva a verde.')],
                action: ['label' => __('Revisa el canal de WhatsApp'), 'to' => 'canales'],
                magnitude: self::LOW[$rating] === 'critical' ? 2.0 : 1.0,
            );
        }

        $since = $now->subDays(14);
        $sent = DB::table('messages')->where('workspace_id', $workspace->id)->where('channel', 'whatsapp')->where('direction', 'out')
            ->whereNotNull('campaign_id')->where('created_at', '>=', $since->utc())
            ->groupBy('campaign_id')->selectRaw("campaign_id AS c, COUNT(*) AS sent, SUM(error_code = '131050') AS blocked")->get()->keyBy('c');
        if ($sent->isEmpty()) {
            return $out;
        }
        $unsubs = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT toString(properties.campaign_id) AS c, count() AS n FROM events
            WHERE workspace_id = {workspace_id:UInt32} AND event = 'message.unsubscribed' AND occurred_at >= {since:DateTime64(3)}
              AND toString(properties.channel) = 'whatsapp' AND c != ''
            GROUP BY c
            SQL, ['since' => Data::ch($since)]);
        $unsubBy = array_column($unsubs, 'n', 'c');
        $names = DB::table('campaigns')->whereIn('id', $sent->keys())->pluck('name', 'id');
        foreach ($sent as $id => $row) {
            $n = (int) $row->sent;
            $bad = (int) $row->blocked + (int) ($unsubBy[(string) $id] ?? 0);
            if ($n < 50 || $bad / $n < 0.02) {
                continue;
            }
            $rate = $bad / $n;
            $out[] = new InsightCandidate(
                fingerprint: 'campaign:'.$id,
                severity: $rate >= 0.05 ? 'critical' : 'warning',
                title: __('La campaña «:name» tuvo :rate de bloqueos y bajas en WhatsApp', ['name' => (string) ($names[$id] ?? ''), 'rate' => Money::percent($rate)]),
                facts: ['campaign' => (string) ($names[$id] ?? ''), 'sent' => $n, 'blocked' => (int) $row->blocked, 'unsubscribed' => (int) ($unsubBy[(string) $id] ?? 0), 'rate' => round($rate, 4)],
                assumptions: [__('Muchos bloqueos bajan la calidad del número. Segmenta mejor y revisa la frecuencia.')],
                action: ['label' => __('Revisa la campaña'), 'to' => 'campanas'],
                periodStart: $since->toDateString(),
                periodEnd: $now->toDateString(),
                magnitude: $rate,
            );
        }

        return $out;
    }
}
