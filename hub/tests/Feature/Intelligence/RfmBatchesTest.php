<?php

use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Intelligence\Rfm\RfmSegment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * R15 de la auditoría: el RFM se calcula en una tabla temporal y se escribe por lotes. Mismo resultado que el
 * cálculo en PHP (quintiles por posición, empates juntos) y sin la lista de clientes en memoria: con más de 65.535
 * clientes el cálculo anterior fallaba (demasiados marcadores en el whereNotIn).
 */

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
});

/** Contactos y pedidos con la tabla de secuencias de MariaDB (sin pasar por PHP fila a fila). El prefijo es de 6 letras. */
function rfmCustomers($test, int $count, string $prefix, CarbonImmutable $now): void
{
    $ws = $test->ws->id;
    $site = $test->site->id;
    DB::statement("INSERT INTO contacts (id, workspace_id, lifecycle_stage, created_at, updated_at)
        SELECT CONCAT(?, LPAD(seq, 20, '0')), ?, 'customer', ?, ? FROM seq_1_to_{$count}", [$prefix, $ws, $now, $now]);
    // 1 a 4 pedidos por cliente, con días y montos repetidos (empates) y algunos fuera de la ventana de 24 meses.
    foreach ([1, 2, 3, 4] as $k) {
        DB::statement("INSERT INTO orders (id, workspace_id, site_id, external_id, contact_id, status, is_paid, currency, total, total_ws, created_at)
            SELECT CONCAT(?, ?, LPAD(seq, 19, '0')), ?, ?, CONCAT(?, ?, '-', seq), CONCAT(?, LPAD(seq, 20, '0')), 'completed', 1, 'COP',
                   (seq % 37) * 10000, (seq % 37) * 10000, DATE_SUB(?, INTERVAL ((seq * 7 + ?) % 800) DAY)
            FROM seq_1_to_{$count} WHERE seq % 4 >= ?", [$prefix, $k, $ws, $site, $prefix, $k, $prefix, $now, $k * 13, $k - 1]);
    }
}

it('da el mismo resultado que el cálculo por quintiles en PHP', function () {
    $now = CarbonImmutable::parse('2026-11-20 12:00:00');
    rfmCustomers($this, 400, '01RFMQ', $now);
    $calc = app(RfmCalculator::class);
    $metrics = $calc->run($this->ws, $now);

    // Referencia: el algoritmo original en PHP sobre los mismos pedidos.
    $rows = DB::table('orders')->where('workspace_id', $this->ws->id)->where('is_paid', true)->whereNotNull('contact_id')
        ->where('created_at', '>=', $now->subMonths(24))->groupBy('contact_id')
        ->selectRaw('contact_id, MAX(created_at) AS last_at, COUNT(*) AS n, COALESCE(SUM(total_ws), 0) AS spend')->get();
    $customers = [];
    foreach ($rows as $row) {
        $customers[(string) $row->contact_id] = [
            'days' => max(0, (int) floor(($now->getTimestamp() - CarbonImmutable::parse((string) $row->last_at)->getTimestamp()) / 86400)),
            'n' => (int) $row->n, 'spend' => (float) $row->spend,
        ];
    }
    $r = $calc->quintiles(array_map(fn ($c) => -$c['days'], $customers));
    $m = $calc->quintiles(array_map(fn ($c) => $c['spend'], $customers));
    $expected = [];
    foreach ($customers as $id => $c) {
        $f = $calc->frequency($c['n'], [1, 2, 3, 5, 10]);
        $expected[$id] = [$r[$id], $f, $m[$id], RfmSegment::classify($r[$id], $f, $m[$id])->value];
    }
    ksort($expected);

    $actual = DB::table('contacts')->where('workspace_id', $this->ws->id)->whereNotNull('rfm_segment')->orderBy('id')->get(['id', 'rfm_r', 'rfm_f', 'rfm_m', 'rfm_segment'])
        ->mapWithKeys(fn ($c) => [(string) $c->id => [(int) $c->rfm_r, (int) $c->rfm_f, (int) $c->rfm_m, (string) $c->rfm_segment]])->all();

    expect($metrics['method'])->toBe('quintiles')->and($metrics['population'])->toBe(count($customers))
        ->and(count($customers))->toBeGreaterThan(200)
        ->and($actual)->toBe($expected)
        ->and(array_sum($metrics['segments']))->toBe(count($customers));
});

it('con más de 65.535 clientes calcula y escribe por lotes; quien sale de la población queda sin RFM', function () {
    $now = CarbonImmutable::parse('2026-11-20 12:00:00');
    rfmCustomers($this, 70000, '01RFMB', $now);
    // Un cliente que ya tenía RFM y ya no tiene pedidos en la ventana.
    DB::table('contacts')->insert(['id' => '01RFMVIEJO000000000000000X', 'workspace_id' => $this->ws->id, 'lifecycle_stage' => 'customer', 'rfm_r' => 5, 'rfm_f' => 5, 'rfm_m' => 5, 'rfm_segment' => 'champions']);

    $metrics = app(RfmCalculator::class)->run($this->ws, $now);
    expect($metrics['population'])->toBeGreaterThan(65535)
        ->and(DB::table('contacts')->where('workspace_id', $this->ws->id)->whereNotNull('rfm_segment')->count())->toBe($metrics['population'])
        ->and(DB::table('contacts')->where('id', '01RFMVIEJO000000000000000X')->value('rfm_segment'))->toBeNull();
});
