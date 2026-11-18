<?php

declare(strict_types=1);

namespace App\Domain\Flows\Console;

use App\Domain\Flows\Actions\EnterFlow;
use App\Domain\Flows\Jobs\AdvanceFlowRun;
use App\Domain\Flows\Models\Flow;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cada hora: disparadores por fecha de un atributo y por umbral (modulos.md M09).
 *
 * - Fecha: cumpleaños, aniversario de la primera compra, fecha esperada de
 *   próxima compra ± N días y «segunda compra» (un pedido y ya pasó el tiempo
 *   mediano hasta la segunda en el espacio de trabajo). Entran a partir de la
 *   hora configurada (local) con `trigger_key` `date:{contacto}:{día}`: una vez por día.
 * - Umbral: el contacto cruza el valor (`threshold:{contacto}`, una sola vez).
 */
final class DateTriggersCommand extends Command
{
    protected $signature = 'igr:flows:date-triggers {--now= : Fecha y hora de referencia (pruebas)}';

    protected $description = 'Hace entrar a los contactos a flujos por fecha o umbral';

    /** Columnas que un umbral puede mirar. */
    public const METRICS = ['orders_count', 'revenue_total_ws', 'aov_ws', 'engagement_score', 'lead_score', 'predicted_clv_12m_ws', 'churn_risk'];

    private const PER_FLOW = 5000;

    public function handle(EnterFlow $enter, CurrentWorkspace $current): int
    {
        $now = $this->option('now') ? CarbonImmutable::parse((string) $this->option('now')) : CarbonImmutable::now();
        $flows = Flow::withoutWorkspaceScope()->where('status', Flow::ACTIVE)->get()
            ->filter(fn (Flow $f) => in_array($f->trigger['type'] ?? null, ['date', 'threshold'], true));
        $entered = 0;
        foreach ($flows as $flow) {
            $workspace = Workspace::query()->find($flow->workspace_id);
            if ($workspace === null) {
                continue;
            }
            $entered += $current->run($workspace, function () use ($flow, $workspace, $now, $enter): int {
                $count = 0;
                foreach ($this->candidates($flow, $workspace, $now) as [$contactId, $key]) {
                    $run = $enter->handle($flow, $contactId, $key, ['trigger' => (string) ($flow->trigger['type'] ?? 'date'), 'props' => ['attribute' => $flow->trigger['attribute'] ?? $flow->trigger['metric'] ?? null]]);
                    if ($run !== null) {
                        AdvanceFlowRun::dispatch($run->workspace_id, $run->id);
                        $count++;
                    }
                }

                return $count;
            });
        }
        $this->line("Entradas: {$entered}");

        return self::SUCCESS;
    }

    /** @return list<array{0: string, 1: string}> contacto y trigger_key */
    private function candidates(Flow $flow, Workspace $workspace, CarbonImmutable $now): array
    {
        $trigger = (array) $flow->trigger;
        $local = $now->setTimezone($workspace->timezone ?: 'America/Bogota');
        $base = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNull('merged_into_id');

        if (($trigger['type'] ?? null) === 'threshold') {
            $metric = (string) ($trigger['metric'] ?? '');
            $op = ['gte' => '>=', 'gt' => '>', 'lte' => '<=', 'lt' => '<'][$trigger['operator'] ?? 'gte'] ?? '>=';
            if (! in_array($metric, self::METRICS, true) || ! is_numeric($trigger['value'] ?? null)) {
                return [];
            }
            $ids = $base->where($metric, $op, (float) $trigger['value'])->limit(self::PER_FLOW)->pluck('id')->all();

            return array_values(array_map(fn ($id) => [(string) $id, 'threshold:'.$id], $ids));
        }

        if ($local->hour < (int) ($trigger['hour'] ?? 9)) {
            return [];
        }
        $today = $local->toDateString();
        $md = $local->format('m-d');
        $offset = (int) ($trigger['offset_days'] ?? 0);
        $query = match ($trigger['attribute'] ?? null) {
            'birthday' => $base->whereNotNull('birthday')->where(function ($q) use ($md, $local) {
                $q->whereRaw("DATE_FORMAT(birthday, '%m-%d') = ?", [$md]);
                // 29 de febrero: se celebra el 28 en los años no bisiestos.
                if ($md === '02-28' && ! $local->isLeapYear()) {
                    $q->orWhereRaw("DATE_FORMAT(birthday, '%m-%d') = '02-29'");
                }
            }),
            'first_order_anniversary' => $base->whereNotNull('first_order_at')->where('first_order_at', '<', $local->subDays(300)->utc())
                ->whereRaw("DATE_FORMAT(first_order_at, '%m-%d') = ?", [$md]),
            'expected_next_order_at' => $base->whereNotNull('expected_next_order_at')
                ->whereRaw('DATE(expected_next_order_at) = ?', [$local->subDays($offset)->toDateString()]),
            // M13: próximo vencimiento de puntos (solo si de verdad vencen puntos ese día).
            'points_expiring_at' => $base->where('points_expiring', '>', 0)->whereNotNull('points_expiring_at')
                ->whereRaw('DATE(points_expiring_at) = ?', [$local->subDays($offset)->toDateString()]),
            'second_purchase_due' => $base->where('orders_count', 1)->whereNotNull('first_order_at')
                ->whereRaw('DATE(first_order_at) = ?', [$local->subDays($this->medianDaysToSecond($workspace->id) - $offset)->toDateString()]),
            default => null,
        };
        if ($query === null) {
            return [];
        }

        return array_values(array_map(fn ($id) => [(string) $id, 'date:'.$id.':'.$today], $query->limit(self::PER_FLOW)->pluck('id')->all()));
    }

    /** Mediana de días entre la primera y la segunda compra (45 si hay pocos datos). */
    private function medianDaysToSecond(string $workspaceId): int
    {
        $days = DB::table('orders as o2')
            ->join('orders as o1', fn ($j) => $j->on('o1.contact_id', '=', 'o2.contact_id')->where('o1.order_index', 1))
            ->where('o2.workspace_id', $workspaceId)->where('o2.order_index', 2)->where('o2.is_paid', true)
            ->selectRaw('DATEDIFF(o2.created_at, o1.created_at) AS d')->limit(2000)->pluck('d')
            ->map(fn ($d) => (int) $d)->sort()->values();
        if ($days->count() < 20) {
            return 45;
        }

        return max(7, (int) $days[intdiv($days->count(), 2)]);
    }
}
