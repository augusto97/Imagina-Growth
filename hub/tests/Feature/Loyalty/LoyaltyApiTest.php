<?php

use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Domain\Loyalty\Models\LoyaltyReward;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LoyaltyFixtures.php';

/*
 * API de Fidelización de la SPA (M13): permisos por rol, validación, auditoría
 * de los ajustes, aislamiento entre espacios de trabajo y los nodos «dar
 * puntos» y «dar saldo» de los flujos.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->base = "/api/w/{$this->ws->publicId()}/loyalty";
    $this->contact = loyaltyContact($this->ws);
});

function loyaltyProgramPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'active', 'currency_name' => 'Puntos Pintor', 'pending_days' => 15,
        'earn_rules' => ['per_amount' => ['points' => 1, 'amount' => '1000'], 'basis' => 'without_shipping', 'signup' => 100, 'birthday' => 200, 'review' => 50, 'review_media' => 100, 'referral' => 500, 'profile' => 50, 'category_multipliers' => [], 'campaigns' => []],
        'redeem_rules' => ['min_points' => 0, 'coupon_expiry_days' => 30],
        'expiry_policy' => ['months' => 12, 'warning_days' => 15],
        'wallet_rules' => ['cashback_pct' => 2],
    ], $overrides);
}

it('la persona de marketing configura el programa; quien solo ve no puede', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);

    $this->actingAs($viewer)->getJson($this->base)->assertOk()->assertJsonPath('program.id', null)->assertJsonPath('program.status', 'draft');
    $this->actingAs($viewer)->putJson("{$this->base}/program", loyaltyProgramPayload())->assertForbidden();

    $res = $this->actingAs($marketing)->putJson("{$this->base}/program", loyaltyProgramPayload())->assertOk();
    expect($res->json('program.status'))->toBe('active')->and($res->json('program.wallet_rules.cashback_pct'))->toBe('2.00')
        ->and($res->json('explainer.sections.0.items.0'))->toContain('Gana 1 Puntos Pintor');
    expect(DB::table('audit_logs')->where('action', 'loyalty.program_saved')->count())->toBe(1);

    $this->actingAs($marketing)->putJson("{$this->base}/program", loyaltyProgramPayload(['earn_rules' => ['campaigns' => [['name' => 'BF', 'multiplier' => 2, 'starts_on' => '2026-11-30', 'ends_on' => '2026-11-27']]]]))
        ->assertStatus(422)->assertJsonValidationErrors('earn_rules.campaigns.0.ends_on');
    $this->actingAs($marketing)->putJson("{$this->base}/program", loyaltyProgramPayload(['pending_days' => 500]))->assertStatus(422);
});

it('guarda los niveles en orden, valida los umbrales y conserva los IDs', function () {
    $this->actingAs($this->owner)->putJson("{$this->base}/program", loyaltyProgramPayload())->assertOk();
    $tiers = [
        ['name' => 'Bronce', 'threshold_type' => 'spend_12m', 'threshold' => 0, 'benefits' => ['multiplier' => 1]],
        ['name' => 'Plata', 'threshold_type' => 'spend_12m', 'threshold' => 500000, 'benefits' => ['multiplier' => 1.5, 'free_shipping' => true], 'wc_role' => 'cliente_plata'],
    ];
    $res = $this->actingAs($this->owner)->putJson("{$this->base}/tiers", ['tiers' => $tiers])->assertOk();
    $plata = $res->json('tiers.1.id');
    expect($res->json('tiers.1.rank'))->toBe(2)->and($res->json('tiers.1.benefits.free_shipping'))->toBeTrue();

    // Reordenar con un nivel nuevo arriba conserva el ID de Plata.
    $tiers[1]['id'] = $plata;
    $tiers[] = ['name' => 'Oro', 'threshold_type' => 'spend_12m', 'threshold' => 2000000];
    $res = $this->actingAs($this->owner)->putJson("{$this->base}/tiers", ['tiers' => $tiers])->assertOk();
    expect($res->json('tiers.1.id'))->toBe($plata)->and($res->json('tiers.2.name'))->toBe('Oro');

    $this->actingAs($this->owner)->putJson("{$this->base}/tiers", ['tiers' => [$tiers[1], $tiers[0]]])->assertStatus(422)->assertJsonValidationErrors('tiers.1.threshold');
    $this->actingAs($this->owner)->putJson("{$this->base}/tiers", ['tiers' => [$tiers[0], ['name' => 'X', 'threshold_type' => 'orders_12m', 'threshold' => 3]]])->assertStatus(422);
    $this->actingAs($this->owner)->putJson("{$this->base}/tiers", ['tiers' => [$tiers[0], ['name' => 'X', 'threshold_type' => 'spend_12m', 'threshold' => 3, 'wc_role' => 'Rol Con Espacios']]])->assertStatus(422);
});

it('crea, edita y archiva recompensas', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->base}/rewards", ['name' => '$20.000 de descuento', 'kind' => 'coupon_fixed', 'points_cost' => 500, 'value' => 20000])->assertCreated();
    $id = $res->json('id');
    $this->actingAs($this->owner)->postJson("{$this->base}/rewards", ['name' => 'Gratis', 'kind' => 'free_product', 'points_cost' => 900, 'value' => 50000, 'config' => ['product_id' => '404']])
        ->assertStatus(422)->assertJsonValidationErrors('config.product_id');
    $this->actingAs($this->owner)->postJson("{$this->base}/rewards", ['name' => '120%', 'kind' => 'coupon_percent', 'points_cost' => 900, 'value' => 120])->assertStatus(422);
    $this->actingAs($this->owner)->postJson("{$this->base}/rewards", ['name' => 'Envío', 'kind' => 'free_shipping', 'points_cost' => 300])->assertCreated()->assertJsonPath('value', '0.00');

    $this->actingAs($this->owner)->patchJson("{$this->base}/rewards/{$id}", ['name' => '$25.000 de descuento', 'kind' => 'coupon_fixed', 'points_cost' => 600, 'value' => 25000])->assertOk()->assertJsonPath('points_cost', 600);
    $this->actingAs($this->owner)->deleteJson("{$this->base}/rewards/{$id}")->assertOk();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => LoyaltyReward::query()->where('status', 'archived')->count()))->toBe(1);
    // El programa quedó creado como borrador al agregar la primera recompensa.
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => LoyaltyProgram::query()->value('status')))->toBe('draft');
});

it('ajusta saldos a mano con auditoría y sin dejar saldos negativos', function () {
    loyaltyProgram($this->ws);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $url = "{$this->base}/contacts/{$this->contact->publicId()}";

    $this->actingAs($viewer)->postJson("{$url}/adjust", ['kind' => 'points', 'direction' => 'add', 'amount' => 100, 'reason' => 'goodwill', 'note' => 'Disculpas por la demora'])->assertForbidden();
    $this->actingAs($marketing)->postJson("{$url}/adjust", ['kind' => 'points', 'direction' => 'add', 'amount' => 100, 'reason' => 'goodwill', 'note' => 'Disculpas por la demora'])->assertCreated()->assertJsonPath('delta', 100);
    $this->actingAs($marketing)->postJson("{$url}/adjust", ['kind' => 'points', 'direction' => 'remove', 'amount' => 150, 'reason' => 'correction', 'note' => 'Error'])->assertStatus(422);
    $this->actingAs($marketing)->postJson("{$url}/adjust", ['kind' => 'points', 'direction' => 'add', 'amount' => 1.5, 'reason' => 'manual', 'note' => 'Medio'])->assertStatus(422);
    $this->actingAs($marketing)->postJson("{$url}/adjust", ['kind' => 'wallet', 'direction' => 'add', 'amount' => 20000, 'reason' => 'refund', 'note' => 'Devolución como saldo'])->assertCreated();
    $this->actingAs($marketing)->postJson("{$url}/adjust", ['kind' => 'wallet', 'direction' => 'remove', 'amount' => 5000, 'reason' => 'correction', 'note' => 'Ajuste'])->assertCreated();

    $res = $this->actingAs($marketing)->getJson($url)->assertOk();
    expect($res->json('balances.points'))->toBe(100)->and($res->json('balances.wallet'))->toBe('15000.00')
        ->and($res->json('ledger'))->toHaveCount(3)->and($res->json('ledger.0.reason'))->toBe('correction');
    $logs = DB::table('audit_logs')->where('action', 'loyalty.adjusted')->get();
    expect($logs)->toHaveCount(3)->and(json_decode((string) $logs[0]->changes, true)['note'])->toBe('Disculpas por la demora');
    $this->actingAs($viewer)->getJson($url)->assertForbidden();
});

it('lista los miembros y las cifras del programa', function () {
    $program = loyaltyProgram($this->ws);
    loyaltyReward($this->ws, $program);
    loyaltyIn($this->ws, function ($ledger) {
        $ledger->earnPoints($this->contact->id, 800, 'campaign', 'a');
        $ledger->debitPoints($this->contact->id, 500, 'redeem', 'b', 'reward', 'x');
        $ledger->earnPoints($this->contact->id, 120, 'order', 'c', 'order', 'o1', pending: true);
    });
    $other = loyaltyContact($this->ws);   // sin movimientos: no es miembro
    $res = $this->actingAs($this->owner)->getJson("{$this->base}/members")->assertOk();
    expect($res->json('data'))->toHaveCount(1)->and($res->json('data.0.points'))->toBe(300)->and($res->json('meta.total'))->toBe(1);

    $stats = $this->actingAs($this->owner)->getJson("{$this->base}/stats")->assertOk();
    expect($stats->json('members'))->toBe(1)->and($stats->json('points_outstanding'))->toBe(300)->and($stats->json('points_pending'))->toBe(120)
        ->and($stats->json('point_value'))->toBe('40.0000')->and($stats->json('points_liability'))->toBe('12000.00')
        ->and(collect($stats->json('monthly'))->last())->toMatchArray(['issued' => 800, 'redeemed' => 500, 'expired' => 0])
        ->and($stats->json('monthly'))->toHaveCount(6);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->base}/stats")->assertOk();
    $this->actingAs($viewer)->getJson("{$this->base}/members")->assertForbidden();
});

it('nada de un espacio de trabajo se ve ni se toca desde otro', function () {
    $program = loyaltyProgram($this->ws);
    $reward = loyaltyReward($this->ws, $program);
    loyaltyIn($this->ws, fn ($ledger) => $ledger->earnPoints($this->contact->id, 100, 'campaign', 'a'));
    $ownerB = $this->userWith2fa();
    $wsB = $this->workspaceFor($ownerB, 'Otra tienda');
    $baseB = "/api/w/{$wsB->publicId()}/loyalty";

    $this->actingAs($ownerB)->getJson($baseB)->assertOk()->assertJsonPath('program.id', null)->assertJsonPath('rewards', []);
    $this->actingAs($ownerB)->patchJson("{$baseB}/rewards/{$reward->publicId()}", ['status' => 'archived'])->assertNotFound();
    $this->actingAs($ownerB)->getJson("{$baseB}/contacts/{$this->contact->publicId()}")->assertNotFound();
    $this->actingAs($ownerB)->postJson("{$baseB}/contacts/{$this->contact->publicId()}/adjust", ['kind' => 'points', 'direction' => 'add', 'amount' => 5, 'reason' => 'manual', 'note' => 'x y z'])->assertNotFound();
    $this->actingAs($ownerB)->getJson("{$baseB}/members")->assertOk()->assertJsonPath('meta.total', 0);
    $this->actingAs($ownerB)->getJson("{$baseB}/stats")->assertOk()->assertJsonPath('members', 0);
    $this->actingAs($ownerB)->getJson("{$this->base}")->assertNotFound();
    // El libro de B no tiene filas de A.
    expect(app(CurrentWorkspace::class)->run($wsB, fn () => LedgerEntry::query()->count()))->toBe(0);
});

it('los nodos «dar puntos» y «dar saldo» acreditan una vez por visita, salvo al grupo de control', function () {
    loyaltyProgram($this->ws);
    $flow = app(CurrentWorkspace::class)->run($this->ws, function () {
        $saved = app(SaveFlow::class)->handle(null, ['name' => 'Premio', 'trigger' => ['type' => 'manual'], 'reentry_policy' => ['mode' => 'always'], 'graph' => [
            'nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'pts', 'type' => 'give_points', 'config' => ['points' => 250]],
                ['id' => 'sal', 'type' => 'give_wallet', 'config' => ['amount' => '5000']],
            ],
            'edges' => [['from' => 'trigger', 'to' => 'pts'], ['from' => 'pts', 'to' => 'sal']],
        ]]);
        expect($saved['issues'] ?? [])->toBe([]);
        app(PublishFlow::class)->handle($saved['flow']);
        app(ChangeFlowStatus::class)->handle($saved['flow']->refresh(), Flow::ACTIVE);

        return $saved['flow']->refresh();
    });
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    loyaltyIn($this->ws, function ($ledger) {
        expect($ledger->pointsBalance($this->contact->id))->toBe(250)->and($ledger->walletBalance($this->contact->id))->toBe('5000.00');
        $run = FlowRun::query()->sole();
        expect($run->status)->toBe('completed')
            ->and(LedgerEntry::query()->where('kind', 'points')->value('idempotency_key'))->toBe("run:{$run->id}:pts:1")
            ->and(FlowRunStep::query()->where('run_id', $run->id)->pluck('outcome', 'node_id')->all())->toEqual(['pts' => 'continue', 'sal' => 'continue']);
    });

    // Grupo de control: recorre el flujo sin recibir nada.
    $other = loyaltyContact($this->ws);
    app(CurrentWorkspace::class)->run($this->ws, function () use ($flow, $other) {
        $flow->forceFill(['holdout_pct' => 100])->save();
        app(EnrollContacts::class)->handle($flow, [$other->id]);
    });
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($other->id))->toBe(0)->and($ledger->walletBalance($other->id))->toBe('0.00'));

    // Validación: cantidades fuera de rango.
    $issues = app(CurrentWorkspace::class)->run($this->ws, fn () => app(SaveFlow::class)->handle(null, ['name' => 'Malo', 'trigger' => ['type' => 'manual'], 'graph' => [
        'nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'pts', 'type' => 'give_points', 'config' => ['points' => 0]], ['id' => 'sal', 'type' => 'give_wallet', 'config' => []]],
        'edges' => [['from' => 'trigger', 'to' => 'pts'], ['from' => 'pts', 'to' => 'sal']],
    ]])['issues']);
    expect(array_keys($issues))->toContain('pts')->toContain('sal');
});
