<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Rfm;

use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * RFM adaptado a LATAM (inteligencia.md §3). Se recalcula cada noche.
 *
 * - Población: contactos con ≥ 1 pedido válido en los últimos N meses (24).
 * - R: quintiles de días desde la última compra (5 = más reciente). Con menos
 *   de 200 clientes, cortes fijos de 0,5×, 1×, 2× y 4× el tiempo mediano entre compras.
 * - F: valores absolutos (1, 2, 3–4, 5–9, 10+), configurables por industria.
 * - M: quintiles del gasto en la ventana.
 *
 * Los contactos que salen de la población quedan sin RFM (null).
 */
final class RfmCalculator
{
    public const MIN_FOR_QUINTILES = 200;

    private static int $seq = 0;

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return array<string, mixed> métricas del cálculo (se guardan en prediction_runs) */
    public function run(Workspace $workspace, ?CarbonImmutable $now = null): array
    {
        return $this->current->run($workspace, fn () => $this->compute($workspace, $now ?? CarbonImmutable::now()));
    }

    /** Contactos por UPDATE al escribir el resultado. */
    private const WRITE_CHUNK = 5000;

    /**
     * R15: el cálculo va en una tabla temporal de MariaDB (población, cortes, puntajes y segmento) y se
     * escribe por lotes; PHP nunca tiene la lista de clientes en memoria. Mismo resultado que el cálculo
     * en PHP: los cortes de quintiles se leen por posición en la columna ordenada (quintiles()).
     *
     * @return array<string, mixed>
     */
    private function compute(Workspace $workspace, CarbonImmutable $now): array
    {
        $months = (int) $workspace->setting('rfm.window_months', 24);
        $since = $now->subMonths($months);
        /** @var list<int> $fCuts */
        $fCuts = array_values(array_map('intval', (array) $workspace->setting('rfm.f_cuts', [1, 2, 3, 5, 10])));
        $tmp = 'igr_rfm_tmp_'.getmypid().'_'.(++self::$seq);
        Schema::create($tmp, function (Blueprint $table): void {
            $table->temporary();
            $table->char('contact_id', 26)->primary();
            $table->unsignedInteger('days');
            $table->unsignedInteger('n');
            $table->decimal('spend', 20, 2);
            $table->unsignedTinyInteger('r')->nullable();
            $table->unsignedTinyInteger('f')->nullable();
            $table->unsignedTinyInteger('m')->nullable();
            $table->string('segment', 32)->nullable();
            $table->index('days');
            $table->index('spend');
        });

        try {
            $at = $now->utc()->format('Y-m-d H:i:s');
            $count = DB::table($tmp)->insertUsing(['contact_id', 'days', 'n', 'spend'], DB::table('orders')
                ->where('workspace_id', $workspace->id)
                ->where('is_paid', true)
                ->whereNotNull('contact_id')
                ->where('created_at', '>=', $since)
                ->groupBy('contact_id')
                ->selectRaw('contact_id, GREATEST(0, FLOOR(TIMESTAMPDIFF(SECOND, MAX(created_at), ?) / 86400)), COUNT(*), COALESCE(SUM(total_ws), 0)', [$at]));
            $method = $count >= self::MIN_FOR_QUINTILES ? 'quintiles' : 'fixed_cuts';

            if ($count > 0) {
                [$rSql, $rBindings] = $method === 'quintiles' ? $this->recencyQuintiles($tmp, $count) : $this->recencyFixed($workspace);
                [$mSql, $mBindings] = $this->quintileScore($tmp, 'spend', $count);
                [$fSql, $fBindings] = $this->frequencySql($fCuts);
                DB::statement("UPDATE `{$tmp}` SET r = {$rSql}, m = {$mSql}, f = {$fSql}", [...$rBindings, ...$mBindings, ...$fBindings]);
                [$segSql, $segBindings] = $this->segmentSql();
                DB::statement("UPDATE `{$tmp}` SET segment = {$segSql}", $segBindings);
            }

            /** @var array<string, int> $bySegment */
            $bySegment = DB::table($tmp)->groupBy('segment')->selectRaw('segment, COUNT(*) AS n')->pluck('n', 'segment')
                ->mapWithKeys(fn ($n, $segment) => [(string) $segment => (int) $n])->all();

            DB::transaction(function () use ($workspace, $tmp, $at): void {
                // Fuera de la población: sin RFM.
                DB::statement("UPDATE contacts c LEFT JOIN `{$tmp}` t ON t.contact_id = c.id
                    SET c.rfm_r = NULL, c.rfm_f = NULL, c.rfm_m = NULL, c.rfm_segment = NULL, c.rfm_computed_at = ?
                    WHERE c.workspace_id = ? AND c.rfm_segment IS NOT NULL AND t.contact_id IS NULL", [$at, $workspace->id]);
                $after = '';
                while (true) {
                    $upTo = DB::table($tmp)->where('contact_id', '>', $after)->orderBy('contact_id')->offset(self::WRITE_CHUNK - 1)->limit(1)->value('contact_id');
                    $upTo = is_string($upTo) ? $upTo : null;
                    DB::statement("UPDATE contacts c JOIN `{$tmp}` t ON t.contact_id = c.id
                        SET c.rfm_r = t.r, c.rfm_f = t.f, c.rfm_m = t.m, c.rfm_segment = t.segment, c.rfm_computed_at = ?
                        WHERE c.workspace_id = ? AND t.contact_id > ?".($upTo !== null ? ' AND t.contact_id <= ?' : ''),
                        array_values(array_filter([$at, $workspace->id, $after, $upTo], fn ($v) => $v !== null)));
                    if ($upTo === null) {
                        break;
                    }
                    $after = $upTo;
                }
            });
        } finally {
            // Con TEMPORARY: un DROP TABLE a secas confirma la transacción abierta en MariaDB.
            DB::statement('DROP TEMPORARY TABLE IF EXISTS `'.$tmp.'`');
        }

        ksort($bySegment);
        $metrics = ['population' => $count, 'method' => $method, 'window_months' => $months, 'segments' => $bySegment];
        DB::table('prediction_runs')->insert([
            'id' => strtoupper((string) Str::ulid()),
            'workspace_id' => $workspace->id,
            'model' => 'rfm',
            'trained_at' => $now,
            'metrics' => json_encode($metrics),
            'params' => json_encode(['f_cuts' => $fCuts]),
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $metrics;
    }

    /**
     * Valor en la posición `floor(q·n/5)` de la columna ordenada, para q = 1..4 (los cortes de quintiles()).
     *
     * @param  'asc'|'desc'  $direction
     * @return list<float>
     */
    private function cuts(string $tmp, string $column, int $count, string $direction): array
    {
        $cuts = [];
        for ($q = 1; $q <= 4; $q++) {
            $offset = (int) min($count - 1, (int) floor($q * $count / 5));
            $cuts[] = (float) DB::table($tmp)->orderBy($column, $direction)->offset($offset)->limit(1)->value($column);
        }

        return $cuts;
    }

    /**
     * M (y R por quintiles): 1 + cuántos cortes alcanza el valor, máximo 5.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function quintileScore(string $tmp, string $column, int $count): array
    {
        $cuts = $this->cuts($tmp, $column, $count, 'asc');

        return ['LEAST(5, 1 + ('.$column.' >= ?) + ('.$column.' >= ?) + ('.$column.' >= ?) + ('.$column.' >= ?))', $cuts];
    }

    /**
     * R por quintiles de «menos días = mejor»: los cortes de -días son los días en orden descendente, y
     * `-días >= corte` es `días <= -corte`.
     *
     * @return array{0: string, 1: list<float>}
     */
    private function recencyQuintiles(string $tmp, int $count): array
    {
        $cuts = $this->cuts($tmp, 'days', $count, 'desc');

        return ['LEAST(5, 1 + (days <= ?) + (days <= ?) + (days <= ?) + (days <= ?))', $cuts];
    }

    /**
     * R con cortes fijos según el ciclo del negocio: múltiplos 0,5×, 1×, 2× y 4× del tiempo mediano entre
     * compras (90 días si aún no hay recompras).
     *
     * @return array{0: string, 1: list<float>}
     */
    private function recencyFixed(Workspace $workspace): array
    {
        $gaps = DB::table('contacts')->where('workspace_id', $workspace->id)->whereNotNull('avg_days_between_orders');
        $n = (clone $gaps)->count();
        $median = $n === 0 ? 90.0 : max(7.0, (float) (clone $gaps)->orderBy('avg_days_between_orders')->offset(intdiv($n, 2))->limit(1)->value('avg_days_between_orders'));

        return ['CASE WHEN days <= ? THEN 5 WHEN days <= ? THEN 4 WHEN days <= ? THEN 3 WHEN days <= ? THEN 2 ELSE 1 END', [0.5 * $median, $median, 2 * $median, 4 * $median]];
    }

    /**
     * F = el último corte que alcanza el número de pedidos (como frequency()), entre 1 y 5.
     *
     * @param  list<int>  $cuts
     * @return array{0: string, 1: list<int>}
     */
    private function frequencySql(array $cuts): array
    {
        if ($cuts === []) {
            return ['1', []];
        }
        $sql = 'CASE';
        $bindings = [];
        for ($i = count($cuts) - 1; $i >= 0; $i--) {
            $sql .= ' WHEN n >= ? THEN '.max(1, min(5, $i + 1));
            $bindings[] = $cuts[$i];
        }

        return [$sql.' ELSE 1 END', $bindings];
    }

    /**
     * El segmento de cada combinación R, F, M (RfmSegment::classify, 125 casos).
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function segmentSql(): array
    {
        $sql = 'CASE r * 100 + f * 10 + m';
        $bindings = [];
        for ($r = 1; $r <= 5; $r++) {
            for ($f = 1; $f <= 5; $f++) {
                for ($m = 1; $m <= 5; $m++) {
                    $sql .= ' WHEN ? THEN ?';
                    array_push($bindings, $r * 100 + $f * 10 + $m, RfmSegment::classify($r, $f, $m)->value);
                }
            }
        }

        return [$sql.' END', $bindings];
    }

    /**
     * Puntajes 1–5 por quintiles (5 = valor más alto). Los empates quedan en
     * el mismo quintil: el corte se hace por valor, no por posición.
     *
     * @param  array<string, float|int>  $values
     * @return array<string, int>
     */
    public function quintiles(array $values): array
    {
        if ($values === []) {
            return [];
        }
        $sorted = array_values($values);
        sort($sorted);
        $n = count($sorted);
        $cuts = [];
        for ($q = 1; $q <= 4; $q++) {
            $cuts[] = $sorted[(int) min($n - 1, (int) floor($q * $n / 5))];
        }
        $out = [];
        foreach ($values as $id => $v) {
            $score = 1;
            foreach ($cuts as $cut) {
                if ($v >= $cut) {
                    $score++;
                }
            }
            $out[$id] = min(5, $score);
        }

        return $out;
    }

    /** @param list<int> $cuts umbrales mínimos de pedidos para F = 1..5 */
    public function frequency(int $orders, array $cuts): int
    {
        $score = 1;
        foreach ($cuts as $i => $min) {
            if ($orders >= $min) {
                $score = $i + 1;
            }
        }

        return max(1, min(5, $score));
    }
}
