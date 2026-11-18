<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Predictions\Actions;

use App\Analytics\Ch;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\Bulk;
use App\Domain\Intelligence\Predictions\Support\Decay;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puntaje de interacción de 0 a 100 (inteligencia.md §4.8): suma con decaimiento
 * de visitas al sitio, aperturas de correo **sin** las automáticas de Apple (MPP),
 * lecturas de WhatsApp, clics, respuestas y compras, con pesos configurables
 * (`igr-predictions.engagement`). `100 × (1 − e^(−suma/escala))`. Sirve para
 * suprimir a quien no interactúa.
 */
final class ComputeEngagement
{
    /** @return array{status: string} */
    public function handle(Workspace $workspace): array
    {
        $cfg = (array) config('igr-predictions.engagement');
        $w = (array) $cfg['weights'];
        $hl = (float) ($cfg['half_life_days'] ?? 30);
        $scale = (float) ($cfg['scale'] ?? 15);
        $since = now()->subDays((int) ($cfg['window_days'] ?? 180));
        $now = now()->getTimestamp();
        $raw = [];

        try {
            $ch = Ch::forWorkspace($workspace);
            $params = ['hl' => $hl, 'from' => $since->utc()->format('Y-m-d H:i:s.v')];
            $messages = $ch->select(
                "SELECT contact_id AS cid, event AS ev, sum(pow(0.5, dateDiff('second', occurred_at, now64(3)) / 86400 / {hl:Float64})) AS w
                 FROM events
                 WHERE workspace_id = {workspace_id:UInt32} AND contact_id != '' AND occurred_at >= {from:DateTime64(3)}
                   AND event IN ('message.opened', 'message.read', 'message.clicked', 'message.replied')
                   AND NOT (event = 'message.opened' AND toString(properties.is_mpp) = 'true')
                 GROUP BY cid, ev",
                $params,
            );
            $visits = $ch->select(
                "SELECT if(s.contact_id != '', s.contact_id, im.contact_id) AS cid, sum(pow(0.5, dateDiff('second', s.started_at, now64(3)) / 86400 / {hl:Float64})) AS w
                 FROM sessions AS s
                 LEFT JOIN (SELECT anonymous_id, argMax(contact_id, linked_at) AS contact_id FROM identity_map
                            WHERE workspace_id = {workspace_id:UInt32} GROUP BY anonymous_id) AS im ON im.anonymous_id = s.anonymous_id
                 WHERE s.workspace_id = {workspace_id:UInt32} AND s.started_at >= {from:DateTime64(3)}
                 GROUP BY cid HAVING cid != ''",
                $params,
            );
        } catch (Throwable $e) {
            Log::warning('igr.predictions.engagement_events_unavailable', ['workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 200)]);
            $messages = [];
            $visits = [];
        }
        $map = ['message.opened' => 'open', 'message.read' => 'read', 'message.clicked' => 'click', 'message.replied' => 'reply'];
        foreach ($messages as $r) {
            $key = $map[(string) $r['ev']] ?? null;
            if ($key !== null) {
                $raw[(string) $r['cid']] = ($raw[(string) $r['cid']] ?? 0) + (float) $r['w'] * (float) $w[$key];
            }
        }
        foreach ($visits as $r) {
            $raw[(string) $r['cid']] = ($raw[(string) $r['cid']] ?? 0) + (float) $r['w'] * (float) $w['visit'];
        }
        foreach (DB::table('orders')->where('workspace_id', $workspace->id)->where('is_paid', true)->whereNotNull('contact_id')
            ->where('created_at', '>=', $since)->select(['contact_id', 'created_at'])->cursor() as $o) {
            $raw[(string) $o->contact_id] = ($raw[(string) $o->contact_id] ?? 0)
                + (float) $w['purchase'] * Decay::weight((int) strtotime((string) $o->created_at.' UTC'), $now, $hl);
        }

        $rows = [];
        $bands = ['0' => 0, '1-24' => 0, '25-49' => 0, '50-74' => 0, '75-100' => 0];
        foreach ($raw as $cid => $sum) {
            $score = (int) round(100 * (1 - exp(-$sum / max(0.1, $scale))));
            $rows[$cid] = ['engagement_score' => $score];
            $bands[match (true) {
                $score === 0 => '0', $score < 25 => '1-24', $score < 50 => '25-49', $score < 75 => '50-74', default => '75-100'
            }]++;
        }
        $existing = array_map('strval', DB::table('contacts')->where('workspace_id', $workspace->id)->whereIn('id', array_keys($rows))->pluck('id')->all());
        $rows = array_intersect_key($rows, array_flip($existing));
        DB::transaction(function () use ($workspace, $rows): void {
            DB::table('contacts')->where('workspace_id', $workspace->id)->where('engagement_score', '!=', 0)->update(['engagement_score' => 0]);
            Bulk::contacts($workspace->id, $rows);
        });
        PredictionRun::record($workspace->id, 'engagement', 'completed', ['contacts' => count($rows), 'bands' => $bands], ['weights' => $w, 'half_life_days' => $hl, 'scale' => $scale]);

        return ['status' => 'completed'];
    }
}
