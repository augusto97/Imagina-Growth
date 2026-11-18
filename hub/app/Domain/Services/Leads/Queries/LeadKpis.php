<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Queries;

use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\LeadFacts;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Indicadores de leads (M18) para la cohorte de leads creados en el periodo:
 * leads por fuente con sus ganados y su valor, embudo por etapa (cuántos
 * llegaron a cada una), tiempo hasta la primera respuesta (mediana y dentro de
 * 15 minutos) y el valor ganado en el periodo. Todo sale de MariaDB.
 */
final class LeadKpis
{
    /** @return array<string, mixed> */
    public function handle(string $workspaceId, LeadPipeline $pipeline, CarbonImmutable $from, CarbonImmutable $to, ?string $ownerId = null): array
    {
        $base = fn () => DB::table('leads')->where('workspace_id', $workspaceId)
            ->where(fn ($q) => $pipeline->is_default ? $q->where('pipeline_id', $pipeline->id)->orWhereNull('pipeline_id') : $q->where('pipeline_id', $pipeline->id))
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId));
        $cohort = fn () => $base()->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc());

        $total = (int) $cohort()->count();
        $bySource = $cohort()->selectRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(source, '$.channel')), 'unknown') AS channel, COUNT(*) AS leads,
                SUM(won_at IS NOT NULL) AS won, COALESCE(SUM(CASE WHEN won_at IS NOT NULL THEN value_ws END), 0) AS won_value")
            ->groupBy('channel')->orderByDesc('leads')->get();

        // Embudo: un lead «llegó» a una etapa abierta si está en ella, pasó por ella (historial) o
        // está más adelante (sin contar «perdido»); a ganado o perdido, si está o estuvo ahí.
        $order = [];
        $kinds = [];
        foreach ($pipeline->stages as $i => $s) {
            $order[$s['key']] = $i;
            $kinds[$s['key']] = $s['kind'];
        }
        $rows = $cohort()->get(['id', 'stage']);
        $visited = [];
        foreach ($rows as $r) {
            $visited[(string) $r->id] = [(string) $r->stage => true];
        }
        DB::table('lead_activities')->where('workspace_id', $workspaceId)->where('kind', 'stage')
            ->whereIn('lead_id', array_keys($visited))->get(['lead_id', 'data'])
            ->each(function ($v) use (&$visited): void {
                $data = json_decode((string) $v->data, true);
                if (is_array($data) && is_string($data['to'] ?? null) && isset($visited[(string) $v->lead_id])) {
                    $visited[(string) $v->lead_id][$data['to']] = true;
                }
            });
        $furthest = array_map(function (array $stages) use ($order, $kinds): int {
            $max = 0;
            foreach (array_keys($stages) as $key) {
                if (($kinds[$key] ?? 'open') !== 'lost') {
                    $max = max($max, $order[$key] ?? 0);
                }
            }

            return $max;
        }, $visited);
        $funnel = [];
        foreach ($pipeline->stages as $i => $s) {
            $n = 0;
            foreach ($visited as $id => $stages) {
                $n += ($s['kind'] === 'open' ? $furthest[$id] >= $i : isset($stages[$s['key']])) ? 1 : 0;
            }
            $funnel[] = ['stage' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind'], 'leads' => $n, 'rate' => $total > 0 ? round($n / $total, 4) : null];
        }

        $responses = $cohort()->whereNotNull('first_response_at')->selectRaw('TIMESTAMPDIFF(SECOND, created_at, first_response_at) AS s')->limit(20000)->pluck('s')
            ->map(fn ($v) => max(0, (int) $v))->sort()->values()->all();
        $answered = count($responses);
        $median = $answered > 0 ? ($answered % 2 === 1 ? $responses[intdiv($answered, 2)] : intdiv($responses[$answered / 2 - 1] + $responses[$answered / 2], 2)) : null;
        $within15 = count(array_filter($responses, fn (int $s) => $s <= 900));

        $won = $base()->whereNotNull('won_at')->where('won_at', '>=', $from->utc())->where('won_at', '<', $to->utc())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(value_ws), 0) AS v')->first();
        $open = $base()->whereNull('won_at')->whereNull('lost_at')->selectRaw("COUNT(*) AS n, SUM(temperature = 'hot') AS hot, SUM(first_response_at IS NULL) AS unanswered")->first();
        $wonCohort = (int) $cohort()->whereNotNull('won_at')->count();

        return [
            'from' => $from->toDateString(),
            'to' => $to->subSecond()->toDateString(),
            'leads' => $total,
            'won_in_cohort' => $wonCohort,
            'conversion_rate' => $total > 0 ? round($wonCohort / $total, 4) : null,
            'won' => ['count' => (int) ($won->n ?? 0), 'value' => number_format((float) ($won->v ?? 0), 2, '.', '')],
            'open' => ['count' => (int) ($open->n ?? 0), 'hot' => (int) ($open->hot ?? 0), 'unanswered' => (int) ($open->unanswered ?? 0)],
            'first_response' => [
                'answered' => $answered,
                'unanswered' => $total - $answered,
                'median_seconds' => $median,
                'within_15m' => $within15,
                'within_15m_rate' => $total > 0 ? round($within15 / $total, 4) : null,
            ],
            'by_source' => $bySource->map(fn ($r) => [
                'channel' => (string) $r->channel,
                'label' => LeadFacts::channelLabel((string) $r->channel === 'unknown' ? null : (string) $r->channel) ?? __('Sin fuente'),
                'leads' => (int) $r->leads,
                'won' => (int) $r->won,
                'won_value' => number_format((float) $r->won_value, 2, '.', ''),
                'conversion_rate' => (int) $r->leads > 0 ? round((int) $r->won / (int) $r->leads, 4) : null,
            ])->values()->all(),
            'funnel' => $funnel,
        ];
    }
}
