<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Ledger\DatabaseLoyaltyLedger;
use App\Domain\Loyalty\Models\LedgerEntry;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LoyaltyFixtures.php';

/*
 * Libro de puntos y saldo (M13): solo inserción, idempotente por clave,
 * pendientes hasta confirmar, vencimiento FIFO y saldos en el contacto.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->program = loyaltyProgram($this->ws);
    $this->contact = loyaltyContact($this->ws);
});

it('sin programa activo no da puntos, pero el saldo sí funciona', function () {
    $this->program->forceFill(['status' => 'paused'])->save();
    app(DatabaseLoyaltyLedger::class)->forgetProgram();
    loyaltyIn($this->ws, function ($ledger) {
        expect($ledger->isActive())->toBeFalse()
            ->and($ledger->earnPoints($this->contact->id, 100, 'manual', 'k1'))->toBeNull()
            ->and($ledger->rulePoints('signup'))->toBe(0);
        $ledger->creditWallet($this->contact->id, '15000', 'manual', 'w1');
        expect($ledger->walletBalance($this->contact->id))->toBe('15000.00');
    });
});

it('es idempotente por clave y deja el saldo en el contacto', function () {
    loyaltyIn($this->ws, function ($ledger) {
        $a = $ledger->earnPoints($this->contact->id, 120, 'campaign', 'promo:1');
        $b = $ledger->earnPoints($this->contact->id, 120, 'campaign', 'promo:1');
        expect($a)->toBe($b)->and($ledger->pointsBalance($this->contact->id))->toBe(120);
        // Con points <= 0 usa la regla del programa (signup = 100 por defecto).
        $ledger->earnPoints($this->contact->id, 0, 'signup', 'signup:x');
        expect($ledger->pointsBalance($this->contact->id))->toBe(220)
            ->and(LedgerEntry::query()->count())->toBe(2)
            ->and((int) Contact::query()->find($this->contact->id)->points_balance)->toBe(220);
        $last = LedgerEntry::query()->where('idempotency_key', 'signup:x')->first();
        expect((int) $last->balance_after)->toBe(220)->and($last->expires_at)->not->toBeNull();
    });
    $rows = $this->chRows($this->ws, "SELECT event, JSONExtractString(toJSONString(properties), 'status') AS status FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'points.earned'");
    expect($rows)->toHaveCount(2)->and($rows[0]['status'])->toBe('confirmed');
});

it('los pendientes no cuentan hasta confirmarse y se pueden revertir', function () {
    loyaltyIn($this->ws, function ($ledger) {
        $ledger->earnPoints($this->contact->id, 300, 'order', 'order:A', 'order', 'A', pending: true);
        $ledger->earnPoints($this->contact->id, 200, 'order', 'order:B', 'order', 'B', pending: true);
        expect($ledger->pointsBalance($this->contact->id))->toBe(0)->and($ledger->summary($this->contact->id)['pending'])->toBe(500);

        $ledger->settle('order', 'A', true);
        $ledger->settle('order', 'A', true);          // dos veces: una sola confirmación
        $ledger->settle('order', 'B', false);
        $ledger->settle('order', 'B', true);          // ya revertido: no se confirma después
        expect($ledger->pointsBalance($this->contact->id))->toBe(300)->and($ledger->summary($this->contact->id)['pending'])->toBe(0);
        expect(LedgerEntry::query()->where('status', 'reversed')->sum('delta'))->toEqual(-200)
            ->and(LedgerEntry::query()->where('ref_id', 'A')->where('status', 'confirmed')->count())->toBe(1);
    });
});

it('una reversión parcial antes de confirmar reduce lo que se confirma', function () {
    loyaltyIn($this->ws, function ($ledger) {
        $id = $ledger->earnPoints($this->contact->id, 270, 'order', 'order:C', 'order', 'C', pending: true);
        $pending = LedgerEntry::query()->find($id);
        expect($ledger->reversePending($pending, 90, 'refund:C:1'))->toBe(90)
            ->and($ledger->reversePending($pending, 90, 'refund:C:1'))->toBe(0)   // misma clave
            ->and($ledger->summary($this->contact->id)['pending'])->toBe(180);
        $ledger->settle('order', 'C', true);
        expect($ledger->pointsBalance($this->contact->id))->toBe(180);
    });
});

it('canjear exige saldo y dos débitos a la vez no lo dejan en negativo', function () {
    loyaltyIn($this->ws, function ($ledger) {
        $ledger->earnPoints($this->contact->id, 500, 'campaign', 'c:1');
        $ledger->debitPoints($this->contact->id, 400, 'redeem', 'r:1');
        expect(fn () => $ledger->debitPoints($this->contact->id, 400, 'redeem', 'r:2'))->toThrow(RuntimeException::class)
            ->and($ledger->pointsBalance($this->contact->id))->toBe(100);
        $ledger->creditWallet($this->contact->id, '10000', 'manual', 'w:1');
        $ledger->debitWallet($this->contact->id, '6000', 'order', 'wd:1', pending: true);
        expect($ledger->walletBalance($this->contact->id))->toBe('4000.00')
            ->and(fn () => $ledger->debitWallet($this->contact->id, '5000', 'order', 'wd:2'))->toThrow(RuntimeException::class);
    });
});

it('vence lo que queda de cada abono en orden FIFO y anuncia el próximo vencimiento', function () {
    $this->travelTo(CarbonImmutable::parse('2025-01-10 12:00:00'));
    loyaltyIn($this->ws, fn ($ledger) => $ledger->earnPoints($this->contact->id, 300, 'campaign', 'old'));
    $this->travelTo(CarbonImmutable::parse('2025-06-10 12:00:00'));
    loyaltyIn($this->ws, function ($ledger) {
        $ledger->earnPoints($this->contact->id, 200, 'campaign', 'new');
        $ledger->debitPoints($this->contact->id, 100, 'redeem', 'r');   // consume del abono más viejo
    });
    $contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->find($this->contact->id));
    expect((int) $contact->points_expiring)->toBe(200)->and((string) $contact->points_expiring_at)->toStartWith('2026-01-10');

    $this->travelTo(CarbonImmutable::parse('2026-01-11 12:00:00'));
    $this->artisan('igr:loyalty:nightly')->assertSuccessful();
    loyaltyIn($this->ws, function ($ledger) {
        expect($ledger->pointsBalance($this->contact->id))->toBe(200)
            ->and((int) LedgerEntry::query()->where('status', 'expired')->sum('delta'))->toBe(-200);
    });
    $contact->refresh();
    expect((int) $contact->points_expiring)->toBe(200)->and((string) $contact->points_expiring_at)->toStartWith('2026-06-10');
    // Otra pasada no vence dos veces.
    $this->artisan('igr:loyalty:nightly')->assertSuccessful();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => LedgerEntry::query()->where('status', 'expired')->count()))->toBe(1);
    $rows = $this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'points.expired'");
    expect($rows)->toHaveCount(1);
    $this->travelBack();
});

it('da los puntos de cumpleaños una vez al año a los clientes', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-15 15:00:00'));
    DB::table('contacts')->where('id', $this->contact->id)->update(['birthday' => '1990-03-15', 'orders_count' => 2]);
    $this->artisan('igr:loyalty:nightly')->assertSuccessful();
    $this->artisan('igr:loyalty:nightly')->assertSuccessful();
    loyaltyIn($this->ws, fn ($ledger) => expect($ledger->pointsBalance($this->contact->id))->toBe(200));
    $this->travelBack();
});

it('pasa el saldo de un contacto fusionado al que quedó', function () {
    $winner = loyaltyContact($this->ws);
    loyaltyIn($this->ws, function ($ledger) use ($winner) {
        $ledger->earnPoints($this->contact->id, 150, 'campaign', 'm:1');
        $ledger->creditWallet($this->contact->id, '5000', 'manual', 'm:2');
        DB::table('contacts')->where('id', $this->contact->id)->update(['merged_into_id' => $winner->id]);
        // Lo que llegue después al fusionado va al que quedó.
        $ledger->earnPoints($this->contact->id, 50, 'campaign', 'm:3');
        $ledger->absorbMerged($winner->id);
        $ledger->absorbMerged($winner->id);
        expect($ledger->pointsBalance($winner->id))->toBe(200)->and($ledger->walletBalance($winner->id))->toBe('5000.00')
            ->and((int) DB::table('contacts')->where('id', $this->contact->id)->value('points_balance'))->toBe(0);
    });
});
