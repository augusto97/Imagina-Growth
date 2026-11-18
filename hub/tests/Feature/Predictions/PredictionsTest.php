<?php

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Support\SendSchedule;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Intelligence\Predictions\Actions\RunPredictions;
use App\Domain\Intelligence\Predictions\Jobs\RunPredictionsJob;
use App\Domain\Intelligence\Predictions\Models\PredictionRun;
use App\Domain\Intelligence\Predictions\Support\BestHour;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site] = $this->connectedSite($this->ws);
    $this->api = "/api/w/{$this->ws->publicId()}/predictions";
    config(['igr-predictions.ml.token' => 'tok', 'igr-predictions.ml.url' => 'http://ml.test']);
});

function predictContact($test, array $attrs = []): Contact
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Contact::query()->create($attrs + ['workspace_id' => $test->ws->id, 'first_name' => 'Ana', 'country' => 'CO']));
}

/** Pedido pagado hace `$daysAgo` días con un ítem del producto y su categoría. */
function predictOrder($test, Contact $c, int $daysAgo, float $total = 100000, string $product = '88', string $category = 'pinturas', ?string $brand = 'Pintuco', float $unit = 50000): string
{
    $id = strtoupper((string) Str::ulid());
    $at = now()->subDays($daysAgo);
    DB::table('orders')->insert(['id' => $id, 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => Str::random(8),
        'contact_id' => $c->id, 'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => $total, 'total_ws' => $total,
        'created_at' => $at, 'updated_at' => $at]);
    DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'order_id' => $id, 'external_item_id' => Str::random(6), 'external_product_id' => $product,
        'name' => 'Producto '.$product, 'category_ids' => json_encode([$category]), 'brand' => $brand, 'qty' => 1, 'unit_price' => $unit, 'line_total' => $unit]);
    DB::table('contacts')->where('id', $c->id)->increment('orders_count');

    return $id;
}

/** El servicio de ML simulado: devuelve una predicción por cada cliente de `full`. */
function predictFakeMl(?array &$captured = null): void
{
    Http::fake(['ml.test/*' => function (HttpRequest $request) use (&$captured) {
        $captured = json_decode($request->body(), true);
        $preds = array_map(fn (array $row) => ['id' => $row[0], 'p_alive' => $row[1] >= 2 ? 0.9 : 0.2, 'exp_90' => 0.8, 'exp_365' => 2.5, 'expected_value' => 120000, 'clv' => 300000],
            $captured['full']['customers']);

        return Http::response(['params' => ['bgnbd' => ['r' => 0.3, 'alpha' => 4, 'a' => 1.2, 'b' => 2.5], 'gamma_gamma' => ['p' => 6, 'q' => 4, 'v' => 15]],
            'validation' => ['holdout_days' => 90, 'customers' => 3, 'churn_auc' => 0.81, 'active_in_holdout_pct' => 40.0,
                'purchases' => ['predicted_total' => 41.0, 'actual_total' => 40.0, 'error_pct' => 2.5, 'mae' => 0.4, 'correlation' => 0.62],
                'purchases_by_decile' => [['decile' => 1, 'customers' => 1, 'predicted' => 2.1, 'actual' => 2.0]],
                'revenue' => ['predicted_total' => 4100000, 'actual_total' => 4000000, 'error_pct' => 2.5, 'mae' => 40000, 'correlation' => 0.5]],
            'predictions' => $preds, 'seconds' => 0.4]);
    }]);
}

it('entrena BG/NBD y Gamma-Gamma con reserva de 90 días, guarda las predicciones y la precisión', function () {
    config(['igr-predictions.thresholds.clv_customers' => 2, 'igr-predictions.thresholds.clv_history_months' => 1]);
    $ana = predictContact($this);
    $luis = predictContact($this, ['first_name' => 'Luis']);
    foreach ([400, 300, 200, 30] as $d) {
        predictOrder($this, $ana, $d, 100000);
    }
    predictOrder($this, $luis, 380, 80000);
    predictFakeMl($sent);

    $result = app(RunPredictions::class)->handle($this->ws, true, ['lifetime']);
    expect($result['lifetime'])->toBe('completed');
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('X-IGR-ML-Token', 'tok') && str_ends_with($r->url(), '/v1/clv/fit'));

    // Ana al corte (hace 90 días): 3 días de compra → x = 2, tx = 200, T = 310; reserva: 1 compra de 100.000.
    $cal = collect($sent['calibration']['customers'])->firstWhere(0, $ana->id);
    expect($cal)->toEqual([$ana->id, 2, 200, 310, 100000])
        ->and($sent['holdout']['actual'][$ana->id])->toEqual([1, 100000])
        ->and(collect($sent['full']['customers'])->firstWhere(0, $ana->id))->toEqual([$ana->id, 3, 370, 400, 100000]);

    $row = DB::table('contacts')->where('id', $ana->id)->first();
    expect((float) $row->p_alive)->toBe(0.9)->and($row->churn_band)->toBe('low')->and((float) $row->churn_risk)->toBe(0.1)
        ->and((float) $row->predicted_clv_12m_ws)->toBe(300000.0)->and((float) $row->expected_orders_365d)->toBe(2.5)->and($row->predictions_at)->not->toBeNull();
    expect(DB::table('contacts')->where('id', $luis->id)->value('churn_band'))->toBe('high');

    $runs = PredictionRun::latestByModel($this->ws->id);
    expect($runs['clv']->status)->toBe('completed')->and($runs['clv']->metrics['purchases']['error_pct'])->toBe(2.5)
        ->and($runs['churn']->metrics['auc'])->toBe(0.81)->and($runs['clv']->params['bgnbd']['r'])->toBe(0.3);

    $overview = $this->actingAs($this->owner)->getJson($this->api)->assertOk();
    $clv = collect($overview->json('models'))->firstWhere('key', 'clv');
    expect($clv['reliability'])->toBe('high')->and($overview->json('coverage.with_clv'))->toBe(2);
});

it('con pocos datos no predice: queda «Datos insuficientes» con el motivo y el abandono usa la heurística', function () {
    config(['igr-predictions.thresholds.heuristic_repeat_customers' => 1]);
    $ana = predictContact($this);
    foreach ([120, 90, 60] as $d) {
        predictOrder($this, $ana, $d);
    }
    Http::fake();
    $result = app(RunPredictions::class)->handle($this->ws, true, ['edno', 'lifetime']);
    Http::assertNothingSent();

    expect($result)->toMatchArray(['edno' => 'insufficient_data', 'lifetime' => 'insufficient_data', 'churn' => 'heuristic']);
    $runs = PredictionRun::latestByModel($this->ws->id);
    expect($runs['clv']->metrics['reason'])->toContain('1 clientes con compras')->and($runs['edno']->metrics['reason'])->toContain('300');
    // 60 días sin comprar frente a un intervalo de 30: ratio 2 → riesgo medio.
    expect(DB::table('contacts')->where('id', $ana->id)->value('churn_band'))->toBe('medium');

    $profile = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/contacts/{$ana->publicId()}")->assertOk();
    expect($profile->json('clv.predicted_reason'))->toContain('clientes con compras')
        ->and($profile->json('predictions.next_order_reason'))->toContain('300')
        ->and($profile->json('predictions.method.churn'))->toBe('heuristic');
});

it('si el servicio de predicciones no responde, la corrida falla y las predicciones anteriores se quedan', function () {
    config(['igr-predictions.thresholds.clv_customers' => 1, 'igr-predictions.thresholds.clv_history_months' => 1]);
    $ana = predictContact($this, ['predicted_clv_12m_ws' => 555000, 'churn_band' => 'low', 'p_alive' => 0.8]);
    predictOrder($this, $ana, 400);
    predictOrder($this, $ana, 100);
    Http::fake(['ml.test/*' => Http::response('caído', 502)]);

    expect(app(RunPredictions::class)->handle($this->ws, true, ['lifetime'])['lifetime'])->toBe('failed');
    expect((float) DB::table('contacts')->where('id', $ana->id)->value('predicted_clv_12m_ws'))->toBe(555000.0)
        ->and(PredictionRun::latestByModel($this->ws->id)['clv']->metrics['reason'])->toBe('El servicio de predicciones no respondió.');
});

it('fecha esperada de próxima compra: mediana personal ponderada con la de su categoría, y la validación de 90 días', function () {
    config(['igr-predictions.thresholds.edno_repeat_customers' => 2]);
    // Ana: compras cada 30 días (3 pedidos → w = 0,5). Luis: cada 50 días. Referencia de «pinturas»: global (menos de 20 brechas).
    $ana = predictContact($this);
    foreach ([160, 130, 100] as $d) {
        predictOrder($this, $ana, $d);
    }
    $luis = predictContact($this, ['first_name' => 'Luis']);
    foreach ([210, 160, 110] as $d) {
        predictOrder($this, $luis, $d);
    }
    // Sofía: una sola compra; usa la mediana de días hasta la segunda compra (30 y 50 → 40).
    $sofia = predictContact($this, ['first_name' => 'Sofía']);
    predictOrder($this, $sofia, 10);

    expect(app(RunPredictions::class)->handle($this->ws, false, ['edno'])['edno'])->toBe('completed');
    // Mediana global de brechas: [30, 30, 50, 50] = 40 → Ana: 0,5 × 30 + 0,5 × 40 = 35 días después de su última compra.
    $ana = DB::table('contacts')->where('id', $ana->id)->value('expected_next_order_at');
    expect(CarbonImmutable::parse($ana)->diffInDays(now()->subDays(100)->startOfDay(), true))->toEqualWithDelta(35, 1);
    $sofia = DB::table('contacts')->where('id', $sofia->id)->value('expected_next_order_at');
    expect(CarbonImmutable::parse($sofia)->diffInDays(now()->subDays(10)->startOfDay(), true))->toEqualWithDelta(40, 1);
    $run = PredictionRun::latestByModel($this->ws->id)['edno'];
    expect($run->metrics)->toHaveKeys(['due_in_holdout', 'precision_pct', 'median_error_days', 'within_15_days_pct']);
});

it('afinidad por categoría y marca con decaimiento: la compra reciente pesa más que la vieja, y la reseña positiva suma', function () {
    $ana = predictContact($this);
    predictOrder($this, $ana, 2, product: '88', category: 'pinturas', brand: 'Pintuco');
    predictOrder($this, $ana, 120, product: '91', category: 'brochas', brand: 'Goya');
    DB::table('products')->insert([
        ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => '95', 'type' => 'simple', 'name' => 'Rodillo', 'status' => 'publish', 'price' => 20000, 'category_ids' => json_encode(['rodillos']), 'brand' => 'Goya', 'currency' => 'COP', 'content_hash' => 'h', 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('reviews')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'contact_id' => $ana->id, 'external_product_id' => '95', 'rating' => 5, 'body' => 'Bueno', 'status' => 'published', 'source' => 'request', 'created_at' => now(), 'updated_at' => now()]);

    app(RunPredictions::class)->handle($this->ws, false, ['affinity']);
    $cats = json_decode((string) DB::table('contacts')->where('id', $ana->id)->value('category_affinity'), true);
    $brands = json_decode((string) DB::table('contacts')->where('id', $ana->id)->value('brand_affinity'), true);
    // Compra hace 2 días ≈ 5 × 0,95; reseña hoy = 2; compra hace 120 días = 5 × 0,0625.
    expect(array_column($cats, 'category_id'))->toBe(['pinturas', 'rodillos', 'brochas'])
        ->and($cats[0]['score'])->toEqualWithDelta(5 * 0.5 ** (2 / 30), 0.01)
        ->and($cats[2]['score'])->toEqualWithDelta(5 * 0.5 ** 4, 0.01)
        ->and(array_column($brands, 'brand'))->toBe(['Pintuco', 'Goya']);
});

it('«siguiente producto»: transición del pedido N al N+1 con soporte y probabilidad', function () {
    foreach (['Ana', 'Luis', 'Sofía'] as $i => $name) {
        $c = predictContact($this, ['first_name' => $name]);
        predictOrder($this, $c, 60, product: '88', category: 'pinturas');
        predictOrder($this, $c, 20, product: '91', category: 'brochas');
    }
    $solo = predictContact($this, ['first_name' => 'Pedro']);
    predictOrder($this, $solo, 50, product: '88', category: 'pinturas');
    predictOrder($this, $solo, 10, product: '77', category: 'lijas');

    app(RunPredictions::class)->handle($this->ws, false, ['next_product']);
    $row = DB::table('product_transitions')->where('workspace_id', $this->ws->id)->where('level', 'product')->where('from_key', '88')->where('to_key', '91')->first();
    expect($row->support)->toBe(3)->and((float) $row->probability)->toBe(0.75)
        ->and(DB::table('product_transitions')->where('to_key', '77')->exists())->toBeFalse() // soporte 1 < 3
        ->and(DB::table('product_transitions')->where('level', 'category')->where('from_key', 'pinturas')->where('to_key', 'brochas')->value('support'))->toBe(3);
});

it('rango de precio frente a los terciles del catálogo', function () {
    foreach ([10, 20, 30, 40, 50, 60, 70, 80, 90] as $i => $p) {
        DB::table('products')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => "p$i", 'type' => 'simple', 'name' => "P$i", 'status' => 'publish', 'price' => $p * 1000, 'currency' => 'COP', 'content_hash' => 'h', 'created_at' => now(), 'updated_at' => now()]);
    }
    $alta = predictContact($this);
    predictOrder($this, $alta, 5, unit: 85000);
    $baja = predictContact($this, ['first_name' => 'Luis']);
    predictOrder($this, $baja, 5, unit: 15000);
    app(RunPredictions::class)->handle($this->ws, false, ['price_band']);
    expect(DB::table('contacts')->where('id', $alta->id)->value('price_band'))->toBe('high')
        ->and(DB::table('contacts')->where('id', $baja->id)->value('price_band'))->toBe('low');
});

it('mejor hora dentro de la ventana legal y canal preferido; las campañas «mejor hora» usan la del espacio de trabajo', function () {
    $ana = predictContact($this, ['timezone' => 'America/Bogota']);
    $msg = function (string $channel, string $localTime, array $cols) use ($ana) {
        $at = CarbonImmutable::parse($localTime, 'America/Bogota')->utc();
        DB::table('messages')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $ana->id, 'channel' => $channel,
            'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'campaign', 'status' => 'read', 'idempotency_key' => Str::random(12),
            'sent_at' => $at->subMinutes(5), 'created_at' => $at->subMinutes(5), 'updated_at' => $at] + array_fill_keys($cols, $at));
    };
    // Lee y hace clic a las 22:00 (fuera de la ventana) y a las 10:00 (dentro): gana 10.
    foreach (range(1, 3) as $d) {
        $msg('whatsapp', now('America/Bogota')->subDays($d)->format('Y-m-d').' 22:00', ['read_at', 'clicked_at']);
    }
    $msg('whatsapp', now('America/Bogota')->subDays(4)->format('Y-m-d').' 10:00', ['read_at', 'replied_at']);
    foreach (range(1, 3) as $d) {
        $msg('email', now('America/Bogota')->subDays($d + 5)->format('Y-m-d').' 09:00', []);
    }

    app(RunPredictions::class)->handle($this->ws, false, ['best_hour']);
    $row = DB::table('contacts')->where('id', $ana->id)->first();
    expect((int) $row->preferred_hour)->toBe(10)->and($row->preferred_channel)->toBe('whatsapp')
        ->and(BestHour::workspaceHour($this->ws->id, 'whatsapp'))->toBe(10)
        ->and(BestHour::allowedHours('CO'))->toBe(range(7, 18));

    $campaign = new Campaign(['channel' => 'whatsapp', 'send_mode' => 'best_time', 'send_at' => CarbonImmutable::parse('2026-12-01 08:00', 'America/Bogota')->utc()]);
    $at = SendSchedule::planned($campaign, $this->ws, 'CO', 'America/Bogota', null, CarbonImmutable::parse('2026-11-30 12:00'));
    expect($at->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-12-01 10:00');
});

it('puntaje de interacción con decaimiento: compras recientes suben y quien no interactúa vuelve a 0', function () {
    $ana = predictContact($this);
    predictOrder($this, $ana, 1);
    predictOrder($this, $ana, 3);
    $old = predictContact($this, ['first_name' => 'Luis', 'engagement_score' => 80]);

    app(RunPredictions::class)->handle($this->ws, false, ['engagement']);
    $expected = (int) round(100 * (1 - exp(-(6 * 0.5 ** (1 / 30) + 6 * 0.5 ** (3 / 30)) / 15)));
    expect((int) DB::table('contacts')->where('id', $ana->id)->value('engagement_score'))->toBe($expected)
        ->and((int) DB::table('contacts')->where('id', $old->id)->value('engagement_score'))->toBe(0);
});

it('API: ver con viewDashboards, recalcular y crear segmentos con manageMarketing, sin ver otro espacio de trabajo', function () {
    PredictionRun::query()->withoutGlobalScopes()->create(['workspace_id' => $this->ws->id, 'model' => 'churn', 'trained_at' => now(), 'status' => 'completed', 'metrics' => ['auc' => 0.7]]);
    $other = $this->workspaceFor($this->userWith2fa());
    PredictionRun::query()->withoutGlobalScopes()->create(['workspace_id' => $other->id, 'model' => 'clv', 'trained_at' => now(), 'status' => 'completed', 'metrics' => ['purchases' => ['error_pct' => 1, 'correlation' => 0.9]]]);

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $res = $this->actingAs($viewer)->getJson($this->api)->assertOk();
    expect(collect($res->json('models'))->firstWhere('key', 'churn')['reliability'])->toBe('medium')
        ->and(collect($res->json('models'))->firstWhere('key', 'clv')['status'])->toBe('never');
    $this->actingAs($viewer)->postJson("{$this->api}/run")->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->api}/segments", ['preset' => 'buying_soon'])->assertForbidden();

    Queue::fake();
    $this->actingAs($this->owner)->postJson("{$this->api}/run", ['train' => true])->assertStatus(202);
    Queue::assertPushed(RunPredictionsJob::class, fn ($j) => $j->train && $j->workspaceIdValue === $this->ws->id);
    foreach (['high_value_at_risk', 'buying_soon', 'disengaged'] as $preset) {
        $this->actingAs($this->owner)->postJson("{$this->api}/segments", ['preset' => $preset])->assertCreated()->assertJsonStructure(['id', 'name']);
    }
    $this->actingAs($this->owner)->postJson("{$this->api}/segments", ['preset' => 'otro'])->assertUnprocessable();
});
