<?php

declare(strict_types=1);

namespace App\Domain\Services\Queries;

use App\Analytics\Semantic\Period;
use App\Domain\Intelligence\Insights\Support\Data;
use Illuminate\Support\Facades\DB;

/**
 * Los indicadores de un negocio sin tienda (rediseño v2 §11.2) que comparten el Inicio (`GET home`) y los Resultados
 * de servicios (`GET services/results`): leads nuevos, tiempo de primera respuesta (mediana en minutos), citas
 * agendadas y cumplidas, tasa de cierre (ganados ÷ cerrados del periodo) y valor ganado; la serie diaria de leads y
 * valor ganado, y los leads por origen. Todo de MariaDB y siempre filtrado por `workspace_id`.
 */
final class ServicesKpis
{
    /** Unidad de cada indicador. */
    public const UNITS = ['leads' => 'count', 'first_response_minutes' => 'minutes', 'appointments_booked' => 'count', 'appointments_attended' => 'count', 'close_rate' => 'percent', 'won_value' => 'money'];

    /** @return array{leads: float, first_response_minutes: float|null, appointments_booked: float, appointments_attended: float, close_rate: float|null, won_value: float} */
    public static function measure(string $workspaceId, Period $p): array
    {
        $range = [$p->from->utc(), $p->to->utc()];
        $leads = DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('created_at', $range);
        $seconds = array_values(array_map('floatval', (clone $leads)->whereNotNull('first_response_at')
            ->selectRaw('TIMESTAMPDIFF(SECOND, created_at, first_response_at) AS s')->limit(5000)->pluck('s')->all()));
        $won = DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('won_at', $range);
        $wonCount = (clone $won)->count();
        $closed = $wonCount + DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('lost_at', $range)->count();
        $appointments = DB::table('appointments')->where('workspace_id', $workspaceId);

        return [
            'leads' => (float) (clone $leads)->count(),
            'first_response_minutes' => $seconds === [] ? null : round(max(0.0, Data::percentile($seconds, 0.5)) / 60, 1),
            'appointments_booked' => (float) (clone $appointments)->whereBetween('created_at', $range)->count(),
            'appointments_attended' => (float) (clone $appointments)->where('status', 'completed')->whereBetween('starts_at', $range)->count(),
            'close_rate' => $closed > 0 ? round($wonCount / $closed * 100, 1) : null,
            'won_value' => (float) $won->sum('value_ws'),
        ];
    }

    /**
     * Leads creados y valor ganado por día local (con el desfase de la zona: LATAM no usa horario de verano).
     *
     * @return list<array{date: string, leads?: float, won_value?: float}>
     */
    public static function daily(string $workspaceId, Period $series): array
    {
        $offset = [(int) $series->offsetSeconds()];
        $range = [$series->from->utc(), $series->to->utc()];
        $rows = [];
        foreach (DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('created_at', $range)
            ->selectRaw('DATE(DATE_ADD(created_at, INTERVAL ? SECOND)) AS d, COUNT(*) AS n', $offset)->groupBy('d')->get() as $r) {
            $rows[(string) $r->d]['leads'] = (float) $r->n;
        }
        foreach (DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('won_at', $range)
            ->selectRaw('DATE(DATE_ADD(won_at, INTERVAL ? SECOND)) AS d, COALESCE(SUM(value_ws), 0) AS v', $offset)->groupBy('d')->get() as $r) {
            $rows[(string) $r->d]['won_value'] = (float) $r->v;
        }
        $list = [];
        foreach ($rows as $date => $values) {
            $list[] = ['date' => (string) $date] + $values;
        }

        return $list;
    }

    /**
     * Leads del periodo por origen (formulario, WhatsApp, Messenger, Instagram, cotizador, agenda, manual, API).
     *
     * @return list<array{origin: string, label: string, count: int}>
     */
    public static function origins(string $workspaceId, Period $p): array
    {
        $sources = [];
        foreach (DB::table('leads')->where('workspace_id', $workspaceId)->whereBetween('created_at', [$p->from->utc(), $p->to->utc()])
            ->selectRaw('origin, COUNT(*) AS n')->groupBy('origin')->orderByDesc('n')->get() as $r) {
            $origin = (string) ($r->origin ?? 'other');
            $label = __('home.lead_origins.'.$origin);
            $sources[] = ['origin' => $origin, 'label' => $label !== 'home.lead_origins.'.$origin ? (string) $label : (string) __('home.lead_origins.other'), 'count' => (int) $r->n];
        }

        return $sources;
    }
}
