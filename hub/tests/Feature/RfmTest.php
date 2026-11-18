<?php

use App\Domain\Intelligence\Rfm\RfmCalculator;
use App\Domain\Intelligence\Rfm\RfmSegment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('clasifica los 11 segmentos en el orden de inteligencia.md §3', function (int $r, int $f, int $m, RfmSegment $expected) {
    expect(RfmSegment::classify($r, $f, $m))->toBe($expected);
})->with([
    [5, 5, 1, RfmSegment::Champions], [4, 4, 5, RfmSegment::Champions], [3, 3, 1, RfmSegment::Loyal], [5, 3, 1, RfmSegment::Loyal],
    [2, 4, 1, RfmSegment::CantLose], [1, 5, 5, RfmSegment::CantLose], [2, 3, 1, RfmSegment::AtRisk], [1, 2, 4, RfmSegment::AtRisk],
    [5, 2, 1, RfmSegment::PotentialLoyal], [5, 1, 5, RfmSegment::New], [4, 1, 1, RfmSegment::Promising], [3, 2, 1, RfmSegment::NeedAttention],
    [3, 1, 1, RfmSegment::NeedAttention], [2, 1, 1, RfmSegment::AboutToSleep], [2, 2, 3, RfmSegment::AboutToSleep],
    [1, 2, 1, RfmSegment::Hibernating], [1, 1, 5, RfmSegment::Lost],
]);

it('F usa valores absolutos y los quintiles respetan empates', function () {
    $calc = app(RfmCalculator::class);
    expect(array_map(fn ($n) => $calc->frequency($n, [1, 2, 3, 5, 10]), [1, 2, 3, 4, 5, 9, 10, 40]))->toBe([1, 2, 3, 3, 4, 4, 5, 5]);
    $q = $calc->quintiles(['a' => 10, 'b' => 10, 'c' => 20, 'd' => 30, 'e' => 40, 'f' => 50, 'g' => 60, 'h' => 70, 'i' => 80, 'j' => 90]);
    expect($q['a'])->toBe($q['b'])->and($q['j'])->toBe(5)->and($q['a'])->toBe(1);
});

it('calcula el RFM de los clientes con cortes fijos si son menos de 200', function () {
    $ws = $this->workspaceFor($this->userWith2fa());
    [$site] = $this->connectedSite($ws);
    $make = function (int $orders, int $daysAgo, float $total) use ($ws, $site): string {
        $contact = strtoupper((string) Str::ulid());
        DB::table('contacts')->insert(['id' => $contact, 'workspace_id' => $ws->id, 'lifecycle_stage' => 'customer', 'created_at' => now(), 'updated_at' => now(), 'avg_days_between_orders' => 30]);
        for ($i = 0; $i < $orders; $i++) {
            DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $ws->id, 'site_id' => $site->id, 'external_id' => Str::random(8),
                'contact_id' => $contact, 'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => $total, 'total_ws' => $total,
                'created_at' => now()->subDays($daysAgo + $i * 20)]);
        }

        return $contact;
    };
    $champion = $make(6, 3, 300000);
    $lost = $make(1, 400, 50000);
    $newbie = $make(1, 5, 80000);
    $old = $make(1, 900, 99999);   // fuera de la ventana de 24 meses

    $metrics = app(RfmCalculator::class)->run($ws);
    $seg = DB::table('contacts')->pluck('rfm_segment', 'id');
    expect($metrics['method'])->toBe('fixed_cuts')->and($metrics['population'])->toBe(3)
        ->and($seg[$champion])->toBe('champions')->and($seg[$lost])->toBe('lost')->and($seg[$newbie])->toBe('new')->and($seg[$old])->toBeNull()
        ->and(DB::table('prediction_runs')->where('model', 'rfm')->count())->toBe(1);
});
