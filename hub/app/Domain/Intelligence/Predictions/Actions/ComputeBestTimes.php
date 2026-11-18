<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Domain\Consent\Policy\LegalWindow;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\BestHour;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\Decay;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Mejor hora y canal (inteligencia.md §4.7):
 * - Hora: histograma por contacto (en su zona horaria) de lecturas, clics,
 *   respuestas y compras, ponderado por recencia; la hora de más peso dentro de la
 *   ventana legal → `preferred_hour`, con 5 interacciones o más. Para los demás, la
 *   mejor del espacio de trabajo por canal (`BestHour::workspaceHour`).
 * - Canal: el de mayor tasa de interacción en 90 días → `preferred_channel`.
 */
final class ComputeBestTimes
{
    /** @return array{status: string} */
    public function handle(Workspace $workspace): array
    {
        $cfg = (array) config('igr-predictions.best_hour');
        $now = now()->getTimestamp();
        $hl = (float) ($cfg['half_life_days'] ?? 60);
        $since = now()->subDays((int) ($cfg['window_days'] ?? 180));
        $channelSince = now()->subDays((int) ($cfg['channel_days'] ?? 90))->getTimestamp();
        $minInteractions = (int) ($cfg['min_interactions'] ?? 5);
        $minSent = (int) ($cfg['min_sent'] ?? 3);
        $country = strtoupper((string) ($workspace->country ?: 'CO'));

        $zones = [];
        foreach (DB::table('contacts')->where('workspace_id', $workspace->id)->select(['id', 'country', 'timezone'])->cursor() as $c) {
            $zones[(string) $c->id] = [strtoupper((string) ($c->country ?: $country)), LegalWindow::timezoneFor((string) ($c->country ?: $country), $c->timezone !== null ? (string) $c->timezone : null)];
        }
        $hist = [];
        $counts = [];
        $byChannel = [];
        $sent = [];
        $hits = [];
        $mark = function (string $cid, ?string $at, ?string $channel) use (&$hist, &$counts, &$byChannel, $zones, $now, $hl): ?int {
            if ($at === null || ! isset($zones[$cid])) {
                return null;
            }
            $ts = (int) strtotime($at.' UTC');
            $hour = (int) CarbonImmutable::createFromTimestamp($ts)->setTimezone($zones[$cid][1])->format('G');
            $w = Decay::weight($ts, $now, $hl);
            $hist[$cid][$hour] = ($hist[$cid][$hour] ?? 0) + $w;
            $counts[$cid] = ($counts[$cid] ?? 0) + 1;
            $byChannel[$channel ?? 'all'][$hour] = ($byChannel[$channel ?? 'all'][$hour] ?? 0) + $w;
            $byChannel['all'][$hour] = ($byChannel['all'][$hour] ?? 0) + ($channel === null ? 0 : $w);

            return $ts;
        };

        foreach (DB::table('messages')->where('workspace_id', $workspace->id)->where('direction', 'out')->whereNotNull('contact_id')
            ->where('created_at', '>=', $since)->select(['contact_id', 'channel', 'sent_at', 'read_at', 'clicked_at', 'replied_at'])->cursor() as $m) {
            $cid = (string) $m->contact_id;
            $ch = (string) $m->channel;
            $interacted = false;
            foreach (['read_at', 'clicked_at', 'replied_at'] as $col) {
                if ($m->{$col} !== null) {
                    $ts = $mark($cid, (string) $m->{$col}, $ch);
                    $interacted = $interacted || ($ts !== null && $ts >= $channelSince);
                }
            }
            if ($m->sent_at !== null && strtotime((string) $m->sent_at.' UTC') >= $channelSince) {
                $sent[$cid][$ch] = ($sent[$cid][$ch] ?? 0) + 1;
                $hits[$cid][$ch] = ($hits[$cid][$ch] ?? 0) + ($interacted ? 1 : 0);
            }
        }
        foreach (DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->whereNotNull('contact_id')
            ->where('created_at', '>=', $since)->select(['contact_id', 'created_at'])->cursor() as $o) {
            $mark((string) $o->contact_id, (string) $o->created_at, null);
        }

        $rows = [];
        foreach ($hist as $cid => $h) {
            if (($counts[$cid] ?? 0) >= $minInteractions) {
                $rows[$cid]['preferred_hour'] = BestHour::pick($h, $zones[$cid][0]);
            }
        }
        foreach ($sent as $cid => $channels) {
            $best = null;
            $bestRate = -1.0;
            foreach ($channels as $ch => $n) {
                if ($n < $minSent || ! in_array($ch, ['whatsapp', 'email', 'sms'], true)) {
                    continue;
                }
                $rate = ($hits[$cid][$ch] ?? 0) / $n;
                if ($rate > $bestRate) {
                    $best = $ch;
                    $bestRate = $rate;
                }
            }
            if ($best !== null && $bestRate > 0) {
                $rows[$cid]['preferred_channel'] = $best;
            }
        }
        $channels = [];
        foreach ($byChannel as $ch => $h) {
            $pick = BestHour::pick($h, $country);
            if ($pick !== null) {
                $channels[$ch] = $pick;
            }
        }
        DB::transaction(function () use ($workspace, $rows): void {
            DB::table('contacts')->where('workspace_id', $workspace->id)->where(fn ($q) => $q->whereNotNull('preferred_hour')->orWhereNotNull('preferred_channel'))
                ->update(['preferred_hour' => null, 'preferred_channel' => null]);
            Bulk::contacts($workspace->id, $rows);
        });
        Cache::forget('ws:'.$workspace->id.':best_hours');
        PredictionRun::record($workspace->id, 'best_hour', 'completed', [
            'contacts_with_hour' => count(array_filter($rows, fn ($r) => isset($r['preferred_hour']))),
            'contacts_with_channel' => count(array_filter($rows, fn ($r) => isset($r['preferred_channel']))),
        ], ['channels' => $channels, 'min_interactions' => $minInteractions, 'allowed_hours' => BestHour::allowedHours($country)]);

        return ['status' => 'completed'];
    }
}
