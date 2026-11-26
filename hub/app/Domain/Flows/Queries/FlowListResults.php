<?php

declare(strict_types=1);

namespace App\Domain\Flows\Queries;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Agency\Queries\MoneyGenerated;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * El resultado de cada automatización para la lista (Mensajes → Automatizaciones): el dinero que generó este mes según
 * MoneyGenerated (la única consulta del dinero generado: cada venta una sola vez), cuántos lograron la meta este mes
 * (compraron, agendaron, avanzaron…: lo que importa en un negocio sin tienda), cuántos mensajes salieron y la
 * comparación con el grupo de control de los últimos 90 días (los que no recibieron mensajes). En pocas consultas para
 * todos los flujos; el dinero va en caché con la versión de la ingesta.
 */
final class FlowListResults
{
    /** Días hacia atrás para comparar con el grupo de control. */
    public const CONTROL_DAYS = 90;

    /** Contactos por grupo para decir algo del grupo de control (como el incremento de FlowAnalytics). */
    public const MIN_GROUP = 30;

    public function __construct(private readonly MoneyGenerated $money) {}

    /**
     * @param  list<string>  $flowIds  IDs internos
     * @return array{month: array{from: string, to: string}, flows: array<string, array{revenue: string, orders: int, conversions: int, messages: int,
     *     control: array{treatment_n: int, treatment_converted: int, holdout_n: int, holdout_converted: int, enough: bool, difference: float|null}}>}
     */
    public function handle(Workspace $workspace, array $flowIds, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$from, $to] = MoneyGenerated::month($workspace, $now);
        $out = ['month' => ['from' => $from->toDateString(), 'to' => $to->subDay()->toDateString()], 'flows' => []];
        if ($flowIds === []) {
            return $out;
        }
        $ws = $workspace->id;
        $range = [$from->utc(), $to->utc()];
        /** @var array<string, array{revenue: string, orders: int}> $money */
        $money = MetricQueryRunner::remember($workspace, 'flows:money:'.$from->toDateString(), fn () => $this->money->bySource($workspace, $from, $to)['flows']);
        $goals = DB::table('flow_runs')->where('workspace_id', $ws)->whereIn('flow_id', $flowIds)->where('exit_reason', 'goal_reached')->where('is_holdout', false)
            ->whereBetween('ended_at', $range)->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->groupBy('flow_id')->selectRaw('flow_id, COUNT(*) AS n')->pluck('n', 'flow_id')->all();
        $sent = DB::table('messages')->where('workspace_id', $ws)->whereIn('flow_id', $flowIds)->where('direction', 'out')
            ->whereBetween('created_at', $range)->whereNotIn('status', ['denied', 'skipped', 'deferred'])
            ->groupBy('flow_id')->selectRaw('flow_id, COUNT(*) AS n')->pluck('n', 'flow_id')->all();
        $groups = DB::table('flow_runs')->where('workspace_id', $ws)->whereIn('flow_id', $flowIds)
            ->where('started_at', '>=', $now->subDays(self::CONTROL_DAYS)->utc())
            ->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->groupBy('flow_id', 'is_holdout')
            ->selectRaw("flow_id, is_holdout, COUNT(*) AS n, SUM(exit_reason = 'goal_reached') AS converted")->get();
        $control = [];
        foreach ($groups as $row) {
            $side = (int) $row->is_holdout === 1 ? 'holdout' : 'treatment';
            $control[(string) $row->flow_id][$side] = ['n' => (int) $row->n, 'converted' => (int) $row->converted];
        }

        foreach ($flowIds as $id) {
            $t = $control[$id]['treatment'] ?? ['n' => 0, 'converted' => 0];
            $h = $control[$id]['holdout'] ?? ['n' => 0, 'converted' => 0];
            $enough = $t['n'] >= self::MIN_GROUP && $h['n'] >= self::MIN_GROUP;
            $out['flows'][$id] = [
                'revenue' => $money[$id]['revenue'] ?? '0.00',
                'orders' => $money[$id]['orders'] ?? 0,
                'conversions' => (int) ($goals[$id] ?? 0),
                'messages' => (int) ($sent[$id] ?? 0),
                'control' => [
                    'treatment_n' => $t['n'], 'treatment_converted' => $t['converted'],
                    'holdout_n' => $h['n'], 'holdout_converted' => $h['converted'],
                    'enough' => $enough,
                    // Diferencia de tasas (puntos porcentuales como fracción): expuestos − control.
                    'difference' => $enough ? round($t['converted'] / $t['n'] - $h['converted'] / $h['n'], 4) : null,
                ],
            ];
        }

        return $out;
    }
}
