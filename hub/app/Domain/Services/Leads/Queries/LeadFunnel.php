<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Queries;

use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Support\Pipelines;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * El embudo de leads del lunes (M18, auditoría §5.1 función 9) de un pipeline, para una
 * semana (lunes a domingo en la zona del negocio) frente a la anterior:
 *
 * - `stages`: cuántos leads **llegaron** a cada etapa en la semana (consultas nuevas en
 *   la primera; en las demás, quien entró o pasó por ella al avanzar; «perdido» no
 *   cuenta) y la conversión de cada etapa a la siguiente, frente a la semana anterior.
 * - `drop`: dónde se cae la venta: el paso con menor conversión entre los leads de los
 *   últimos 90 días (una cohorte con tiempo de madurar, no solo la semana).
 * - `lost`: perdidos en la semana, sus motivos y de qué etapa salieron.
 * - `stalled`: leads abiertos que llevan más de N días en su etapa, por antigüedad.
 * - `first_response` y `won`: tiempo de primera respuesta y ganados, frente a la anterior.
 *
 * Todo sale de MariaDB (`leads` y su historia), filtrado por espacio de trabajo y, para
 * el rol agente, por vendedor.
 */
final class LeadFunnel
{
    public const COHORT_DAYS = 90;

    /** @return array<string, mixed> */
    public function week(string $workspaceId, LeadPipeline $pipeline, CarbonImmutable $weekStart, ?string $ownerId = null, int $staleDays = 7): array
    {
        $from = $weekStart->startOfDay();
        $to = $from->addWeek();
        $prevFrom = $from->subWeek();
        $steps = array_values(array_filter($pipeline->stages, fn (array $s) => $s['kind'] !== 'lost'));
        $index = [];
        foreach ($steps as $i => $s) {
            $index[$s['key']] = $i;
        }
        $first = Pipelines::firstOpen($pipeline);
        $leads = fn () => DB::table('leads')->where('leads.workspace_id', $workspaceId)
            ->where(fn ($q) => $pipeline->is_default ? $q->where('leads.pipeline_id', $pipeline->id)->orWhereNull('leads.pipeline_id') : $q->where('leads.pipeline_id', $pipeline->id))
            ->when($ownerId !== null, fn ($q) => $q->where('leads.owner_id', $ownerId));

        $now = $this->reached($leads, $index, $first, $from, $to);
        $before = $this->reached($leads, $index, $first, $prevFrom, $from);
        $stages = [];
        foreach ($steps as $i => $s) {
            $prevStep = $i > 0 ? $steps[$i - 1]['key'] : null;
            $conversion = $prevStep !== null ? self::ratio($now[$s['key']], $now[$prevStep]) : null;
            $previousConversion = $prevStep !== null ? self::ratio($before[$s['key']], $before[$prevStep]) : null;
            $stages[] = [
                'key' => $s['key'], 'label' => $s['label'], 'kind' => $s['kind'],
                'reached' => $now[$s['key']], 'previous' => $before[$s['key']],
                'conversion' => $conversion, 'previous_conversion' => $previousConversion,
                'change_points' => $conversion !== null && $previousConversion !== null ? round(($conversion - $previousConversion) * 100, 1) : null,
            ];
        }

        return [
            'pipeline' => ['id' => $pipeline->publicId(), 'name' => $pipeline->name],
            'week' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()],
            'previous_week' => ['from' => $prevFrom->toDateString(), 'to' => $from->subDay()->toDateString()],
            'stages' => $stages,
            'drop' => $this->drop($leads, $pipeline, $steps, $to),
            'lost' => $this->lost($leads, $pipeline, $from, $to, $prevFrom),
            'stalled' => $this->stalled($leads, $pipeline, $to, $staleDays),
            'stale_days' => $staleDays,
            'first_response' => [
                'week' => $this->firstResponse($leads, $from, $to),
                'previous' => $this->firstResponse($leads, $prevFrom, $from),
            ],
            'won' => ['week' => $this->won($leads, $from, $to), 'previous' => $this->won($leads, $prevFrom, $from)],
        ];
    }

    /**
     * Leads que llegaron a cada etapa en el periodo: los creados (o reabiertos) cuentan en la
     * primera; un avance cuenta en cada etapa por la que pasa; un retroceso, solo donde cae.
     *
     * @param  Closure(): Builder  $leads
     * @param  array<string, int>  $index
     * @return array<string, int>
     */
    private function reached(Closure $leads, array $index, string $first, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hit = array_fill_keys(array_keys($index), []);
        foreach ($leads()->where('leads.created_at', '>=', $from->utc())->where('leads.created_at', '<', $to->utc())->pluck('leads.id') as $id) {
            $hit[$first][(string) $id] = true;
        }
        $moves = $leads()->join('lead_activities as a', fn ($j) => $j->on('a.lead_id', '=', 'leads.id')->on('a.workspace_id', '=', 'leads.workspace_id'))
            ->where('a.kind', 'stage')->where('a.created_at', '>=', $from->utc())->where('a.created_at', '<', $to->utc())
            ->limit(50000)->get(['a.lead_id', 'a.data']);
        foreach ($moves as $m) {
            $data = json_decode((string) $m->data, true);
            if (! is_array($data)) {
                continue;
            }
            $id = (string) $m->lead_id;
            if (($data['reopened'] ?? false) === true && isset($hit[$first])) {
                $hit[$first][$id] = true;

                continue;
            }
            $toKey = (string) ($data['to'] ?? '');
            if (! isset($index[$toKey])) {
                continue; // a perdido, o a una etapa que ya no existe
            }
            $fromIdx = $index[(string) ($data['from'] ?? '')] ?? -1;
            $toIdx = $index[$toKey];
            foreach ($index as $key => $i) {
                if (($toIdx > $fromIdx && $i > $fromIdx && $i <= $toIdx) || ($toIdx <= $fromIdx && $i === $toIdx)) {
                    $hit[$key][$id] = true;
                }
            }
        }

        return array_map('count', $hit);
    }

    /**
     * El paso con menor conversión en los leads creados en los 90 días anteriores al cierre de la
     * semana: hasta dónde llegó cada uno (historia de etapas y etapa actual; «perdido» no avanza).
     *
     * @param  Closure(): Builder  $leads
     * @param  list<array{key: string, label: string, kind: string}>  $steps
     * @return array<string, mixed>|null
     */
    private function drop(Closure $leads, LeadPipeline $pipeline, array $steps, CarbonImmutable $to): ?array
    {
        $cohort = $leads()->where('leads.created_at', '>=', $to->subDays(self::COHORT_DAYS)->utc())->where('leads.created_at', '<', $to->utc())->limit(20000)->get(['leads.id', 'leads.stage']);
        if ($cohort->isEmpty()) {
            return null;
        }
        $order = [];
        foreach ($steps as $i => $s) {
            $order[$s['key']] = $i;
        }
        $furthest = [];
        foreach ($cohort as $l) {
            $furthest[(string) $l->id] = $order[(string) $l->stage] ?? 0;
        }
        foreach (array_chunk(array_keys($furthest), 1000) as $chunk) {
            DB::table('lead_activities')->where('workspace_id', $pipeline->workspace_id)->where('kind', 'stage')->where('created_at', '<', $to->utc())
                ->whereIn('lead_id', $chunk)->get(['lead_id', 'data'])
                ->each(function ($a) use (&$furthest, $order): void {
                    $data = json_decode((string) $a->data, true);
                    $key = is_array($data) ? (string) ($data['to'] ?? '') : '';
                    if (isset($order[$key], $furthest[(string) $a->lead_id])) {
                        $furthest[(string) $a->lead_id] = max($furthest[(string) $a->lead_id], $order[$key]);
                    }
                });
        }
        $counts = [];
        foreach ($steps as $i => $s) {
            $counts[$i] = count(array_filter($furthest, fn (int $f) => $f >= $i));
        }
        $worst = null;
        for ($i = 1; $i < count($steps); $i++) {
            $rate = self::ratio($counts[$i], $counts[$i - 1]);
            if ($rate !== null && ($worst === null || $rate < $worst['conversion'])) {
                $worst = ['from' => $steps[$i - 1]['key'], 'from_label' => $steps[$i - 1]['label'], 'to' => $steps[$i]['key'], 'to_label' => $steps[$i]['label'],
                    'conversion' => $rate, 'entered' => $counts[$i - 1], 'passed' => $counts[$i], 'cohort' => count($furthest), 'days' => self::COHORT_DAYS];
            }
        }

        return $worst;
    }

    /**
     * @param  Closure(): Builder  $leads
     * @return array<string, mixed>
     */
    private function lost(Closure $leads, LeadPipeline $pipeline, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $prevFrom): array
    {
        $rows = $leads()->whereNotNull('leads.lost_at')->where('leads.lost_at', '>=', $from->utc())->where('leads.lost_at', '<', $to->utc())->get(['leads.id', 'leads.lost_reason']);
        $previous = (int) $leads()->whereNotNull('leads.lost_at')->where('leads.lost_at', '>=', $prevFrom->utc())->where('leads.lost_at', '<', $from->utc())->count();
        $reasons = [];
        foreach ($rows as $r) {
            $reason = is_string($r->lost_reason) && trim($r->lost_reason) !== '' ? mb_substr(trim($r->lost_reason), 0, 120) : '';
            $key = mb_strtolower($reason);
            $reasons[$key] ??= ['reason' => $reason !== '' ? $reason : null, 'count' => 0];
            $reasons[$key]['count']++;
        }
        usort($reasons, fn (array $a, array $b) => $b['count'] <=> $a['count']);
        $fromStage = [];
        DB::table('lead_activities')->where('workspace_id', $pipeline->workspace_id)->where('kind', 'stage')->whereIn('lead_id', $rows->pluck('id')->all())
            ->where('created_at', '>=', $from->utc())->where('created_at', '<', $to->utc())->get(['lead_id', 'data'])
            ->each(function ($a) use (&$fromStage, $pipeline): void {
                $data = json_decode((string) $a->data, true);
                if (is_array($data) && $pipeline->kindOf((string) ($data['to'] ?? '')) === 'lost') {
                    $fromStage[(string) $a->lead_id] = (string) ($data['from'] ?? '');
                }
            });
        $byStage = [];
        foreach (array_count_values($fromStage) as $key => $n) {
            $byStage[] = ['stage' => (string) $key, 'label' => $pipeline->stage((string) $key)['label'] ?? (string) $key, 'count' => $n];
        }
        usort($byStage, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return ['count' => $rows->count(), 'previous' => $previous, 'reasons' => array_slice($reasons, 0, 5), 'by_stage' => $byStage];
    }

    /**
     * @param  Closure(): Builder  $leads
     * @return list<array<string, mixed>>
     */
    private function stalled(Closure $leads, LeadPipeline $pipeline, CarbonImmutable $at, int $staleDays): array
    {
        $rows = $leads()->whereNull('leads.won_at')->whereNull('leads.lost_at')
            ->whereRaw('COALESCE(leads.stage_changed_at, leads.created_at) < ?', [$at->subDays($staleDays)->utc()])
            ->selectRaw('leads.stage, TIMESTAMPDIFF(DAY, COALESCE(leads.stage_changed_at, leads.created_at), ?) AS days', [$at->utc()])->limit(20000)->get();
        $out = [];
        foreach ($pipeline->stages as $s) {
            if ($s['kind'] !== 'open') {
                continue;
            }
            $days = $rows->where('stage', $s['key'])->pluck('days')->map(fn ($d) => (int) $d);
            if ($days->isEmpty()) {
                continue;
            }
            $out[] = ['stage' => $s['key'], 'label' => $s['label'], 'count' => $days->count(), 'max_days' => (int) $days->max(),
                'buckets' => ['up_to_14' => $days->filter(fn (int $d) => $d <= 14)->count(), 'from_15_to_30' => $days->filter(fn (int $d) => $d > 14 && $d <= 30)->count(), 'over_30' => $days->filter(fn (int $d) => $d > 30)->count()]];
        }

        return $out;
    }

    /**
     * @param  Closure(): Builder  $leads
     * @return array{leads: int, answered: int, median_seconds: int|null, within_15m_rate: float|null}
     */
    private function firstResponse(Closure $leads, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cohort = $leads()->where('leads.created_at', '>=', $from->utc())->where('leads.created_at', '<', $to->utc());
        $total = (int) (clone $cohort)->count();
        $seconds = (clone $cohort)->whereNotNull('leads.first_response_at')->selectRaw('TIMESTAMPDIFF(SECOND, leads.created_at, leads.first_response_at) AS s')
            ->limit(20000)->pluck('s')->map(fn ($v) => max(0, (int) $v))->sort()->values()->all();
        $n = count($seconds);
        $median = $n > 0 ? ($n % 2 === 1 ? $seconds[intdiv($n, 2)] : intdiv($seconds[$n / 2 - 1] + $seconds[$n / 2], 2)) : null;

        return ['leads' => $total, 'answered' => $n, 'median_seconds' => $median, 'within_15m_rate' => $total > 0 ? round(count(array_filter($seconds, fn (int $s) => $s <= 900)) / $total, 4) : null];
    }

    /**
     * @param  Closure(): Builder  $leads
     * @return array{count: int, value: string}
     */
    private function won(Closure $leads, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $row = $leads()->whereNotNull('leads.won_at')->where('leads.won_at', '>=', $from->utc())->where('leads.won_at', '<', $to->utc())
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(leads.value_ws), 0) AS v')->first();

        return ['count' => (int) ($row->n ?? 0), 'value' => number_format((float) ($row->v ?? 0), 2, '.', '')];
    }

    private static function ratio(int $num, int $den): ?float
    {
        return $den > 0 ? round($num / $den, 4) : null;
    }
}
