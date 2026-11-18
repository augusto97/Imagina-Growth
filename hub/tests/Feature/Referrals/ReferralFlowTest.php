<?php

use App\Domain\Commerce\Models\CouponIssued;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Referrals\Actions\QualifyDueReferrals;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\ReferralCodes;
use Carbon\CarbonImmutable;
use Tests\Feature\Referrals\ReferralFixtures;

/*
 * Referidos (modulos.md M14): código por cliente, regalo del amigo, antifraude,
 * atribución del pedido (hub/CLAUDE.md §9 paso 3), calificación y recompensa.
 */

uses(ReferralFixtures::class);

beforeEach(function () {
    $this->referralWorkspace();
});

function referralCodeFor($test, $contact): string
{
    return $test->inWs(fn () => app(ReferralCodes::class)->forContact($contact));
}

function referralCapture($test, $friend, string $code, ?string $anon = null, ?string $ip = null, bool $known = false): ?CouponIssued
{
    return $test->inWs(fn () => app(ReferralRewards::class)->onFriendCaptured($friend->id, $code, $anon, $known, $test->site->id, $ip));
}

function referralOf($test, $friend): ?Referral
{
    return $test->inWs(fn () => Referral::query()->where('friend_contact_id', $friend->id)->first());
}

it('crea el código del cliente una sola vez, legible, único y con su enlace', function () {
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);
    expect($code)->toMatch('/^ANA[A-HJ-NP-Z2-9]{3}$/')
        ->and(referralCodeFor($this, $ana->fresh()))->toBe($code)
        ->and(ReferralCodes::link($this->site, $code))->toBe('https://tienda.co/?ref='.$code);

    $sinNombre = $this->referralContact('X');
    $this->inWs(fn () => $sinNombre->forceFill(['first_name' => null])->save());
    $otro = referralCodeFor($this, $sinNombre);
    expect($otro)->toMatch('/^[A-HJ-NP-Z2-9]{6}$/');

    // Se busca sin importar mayúsculas y siguiendo las fusiones de perfiles.
    expect($this->inWs(fn () => app(ReferralCodes::class)->contactFor(strtolower($code)))?->id)->toBe($ana->id)
        ->and($this->inWs(fn () => app(ReferralCodes::class)->contactFor('NO EXISTE')))->toBeNull();
    $this->inWs(fn () => $sinNombre->forceFill(['merged_into_id' => $ana->id])->save());
    expect($this->inWs(fn () => app(ReferralCodes::class)->contactFor($otro))?->id)->toBe($ana->id);
});

it('da el regalo del amigo una sola vez y nunca a un autorreferido, a quien ya es cliente ni con el programa apagado', function () {
    $this->referralProgram();
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);
    $beto = $this->referralContact('Beto');

    $coupon = referralCapture($this, $beto, strtolower($code), 'anon-beto');
    expect($coupon)->not->toBeNull()
        ->and($coupon->contact_id)->toBe($beto->id)
        ->and($coupon->source_type)->toBe('referral')
        ->and($this->coupons->issued[0]->prefix)->toBe('AMIGO')
        ->and($this->coupons->issued[0]->restrictToContact)->toBeTrue()
        ->and($this->coupons->issued[0]->kind)->toBe('percent')
        ->and($this->coupons->issued[0]->amount)->toBe('10.00');
    $referral = referralOf($this, $beto);
    expect($referral->status)->toBe('signed_up')->and($referral->referrer_contact_id)->toBe($ana->id)
        ->and($referral->code)->toBe($code)->and($referral->friend_coupon_id)->toBe($coupon->id)->and($referral->review_status)->toBeNull();

    // La misma captura otra vez: el mismo cupón, no uno nuevo.
    expect(referralCapture($this, $beto, $code)?->id)->toBe($coupon->id)->and($this->coupons->issued)->toHaveCount(1);
    // Otro cliente no puede «referir» a quien ya fue referido.
    $carla = $this->referralContact('Carla');
    expect(referralCapture($this, $beto, referralCodeFor($this, $carla)))->toBeNull();

    // Autorreferido: ni cupón ni referido.
    expect(referralCapture($this, $ana, $code))->toBeNull()->and(referralOf($this, $ana))->toBeNull();

    // Quien ya compró no recibe el regalo de primera compra.
    $this->referralOrder('order.paid', ['order_id' => '3001', 'customer_ref' => 'wc:301', 'coupons' => [], 'billing' => $this->referralBilling('Dora', 'dora@ejemplo.com', '300 555 0101')]);
    $dora = $this->inWs(fn () => Order::query()->where('external_id', '3001')->firstOrFail()->contact_id);
    expect(referralCapture($this, (object) ['id' => $dora], $code, known: true))->toBeNull();

    // Código inexistente o programa en pausa: nada.
    $eva = $this->referralContact('Eva');
    expect(referralCapture($this, $eva, 'NOEXISTE'))->toBeNull();
    $this->inWs(fn () => ReferralProgram::query()->update(['status' => 'paused']));
    expect(referralCapture($this, $eva, $code))->toBeNull()->and($this->coupons->issued)->toHaveCount(1);
});

it('manda a revisión los referidos con señales de fraude y la recompensa espera la decisión', function () {
    $this->referralProgram();
    $ana = $this->referralContact('Ana', anon: 'anon-ana');
    $code = referralCodeFor($this, $ana);
    app(Fingerprints::class)->record($this->ws->id, $ana->id, 'ip', '190.24.8.9');

    $fede = $this->referralContact('Fede');
    // Mismo navegador (anonymous_id de Ana) y misma IP que usó Ana al compartir.
    $coupon = referralCapture($this, $fede, $code, 'anon-ana', '190.24.8.9');
    expect($coupon)->not->toBeNull();   // el amigo igual recibe su regalo
    $referral = referralOf($this, $fede);
    expect($referral->review_status)->toBe('pending_review')
        ->and($referral->signals())->toEqualCanonicalizing(['same_device', 'same_ip']);

    // Ya calificado, no se recompensa mientras esté en revisión.
    $this->inWs(fn () => $referral->forceFill(['status' => 'qualified'])->save());
    $this->inWs(fn () => app(QualifyDueReferrals::class)->handle());
    expect($this->ledgerCalls)->toBe([]);

    // Sin señales, nada que revisar.
    $gina = $this->referralContact('Gina');
    referralCapture($this, $gina, $code, 'anon-gina', '200.1.1.1');
    expect(referralOf($this, $gina)->review_status)->toBeNull()->and(referralOf($this, $gina)->signals())->toBe([]);
});

it('sigue al amigo hasta la recompensa: compra con su cupón, se completa, pasa la devolución y quien refiere recibe su saldo una vez', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'America/Bogota'));
    $this->referralProgram(['rules' => ['return_days' => 15]]);
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);
    $beto = $this->referralContact('Beto', 'beto@ejemplo.com', '+573005550202', 'anon-beto');
    $coupon = referralCapture($this, $beto, $code, 'anon-beto');

    $order = ['order_id' => '2001', 'number' => '2001', 'customer_ref' => 'wc:201', 'coupons' => [strtolower($coupon->code)], 'billing' => $this->referralBilling('Beto', 'beto@ejemplo.com', '300 555 0202')];
    $this->referralOrder('order.paid', $order, anon: 'anon-beto');
    $referral = referralOf($this, $beto);
    $projected = $this->inWs(fn () => Order::query()->where('external_id', '2001')->firstOrFail());
    expect($projected->contact_id)->toBe($beto->id)
        ->and($referral->status)->toBe('ordered')
        ->and($referral->order_id)->toBe($projected->id)
        ->and($referral->order_amount_ws)->toBe('282000.00')
        ->and($projected->getAttribute('attributed_referral_id'))->toBe($referral->id)
        ->and($referral->qualifies_at)->toBeNull();   // todavía no se entrega

    $this->travel(2)->days();
    $this->referralOrder('order.completed', $order + ['status' => 'completed', 'modified_at' => now()->toIso8601String()]);
    $referral->refresh();
    expect($referral->qualifies_at?->toDateString())->toBe(now()->addDays(15)->toDateString());

    // Antes de que pase la devolución no califica.
    $this->artisan('igr:referrals:qualify', ['--at' => now()->addDays(10)->toIso8601String()])->assertSuccessful();
    expect($referral->refresh()->status)->toBe('ordered')->and($this->ledgerCalls)->toBe([]);

    $this->artisan('igr:referrals:qualify', ['--at' => now()->addDays(16)->toIso8601String()])->assertSuccessful();
    $referral->refresh();
    expect($referral->status)->toBe('rewarded')
        ->and($referral->reward['type'])->toBe('wallet')
        ->and($referral->reward['label'])->toContain('saldo a favor')
        ->and($referral->reward_cost_ws)->toBe('50000.00')   // 30.000 de descuento del amigo + 20.000 de saldo
        ->and($this->ledgerCalls)->toBe([['type' => 'wallet', 'contact' => $ana->id, 'value' => '20000.00', 'key' => 'referral:'.$referral->id]]);

    $events = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'referral.converted'");
    expect($events)->toHaveCount(1)->and($events[0]['contact_id'])->toBe($ana->id);
    $props = json_decode($events[0]['p'], true);
    expect($props['order_id'])->toBe('2001')->and($props['referral_id'])->toBe($referral->publicId())->and($props['status'])->toBe('qualified');

    // Correr otra vez no paga dos veces.
    $this->artisan('igr:referrals:qualify', ['--at' => now()->addDays(17)->toIso8601String()])->assertSuccessful();
    expect($this->ledgerCalls)->toHaveCount(1);
});

it('rechaza el referido si el pedido se reembolsa antes de calificar o si no llega a la compra mínima', function () {
    $this->referralProgram(['rules' => ['min_order' => '100000.00']]);
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);

    $beto = $this->referralContact('Beto', 'beto@ejemplo.com');
    $coupon = referralCapture($this, $beto, $code);
    $order = ['order_id' => '2101', 'customer_ref' => 'wc:211', 'coupons' => [strtolower($coupon->code)], 'billing' => $this->referralBilling('Beto', 'beto@ejemplo.com', '300 555 0303')];
    $this->referralOrder('order.paid', $order);
    expect(referralOf($this, $beto)->status)->toBe('ordered');
    $this->referralOrder('order.refunded', $order + ['status' => 'refunded', 'modified_at' => now()->addMinute()->toIso8601String(), 'totals' => ['refunded' => '282000.00']]);
    expect(referralOf($this, $beto)->status)->toBe('rejected')->and(referralOf($this, $beto)->rejected_reason)->toBe('order_refunded');

    // Compra mínima de 300.000 y el pedido es de 282.000.
    $this->inWs(fn () => ReferralProgram::query()->update(['rules' => json_encode(['min_order' => '300000.00', 'return_days' => 15, 'qualify_on' => 'completed', 'review_suspicious' => true])]));
    $carla = $this->referralContact('Carla', 'carla@ejemplo.com');
    referralCapture($this, $carla, $code);
    $this->referralOrder('order.paid', ['order_id' => '2102', 'customer_ref' => 'wc:212', 'coupons' => [], 'billing' => $this->referralBilling('Carla', 'carla@ejemplo.com', '300 555 0404')]);
    expect(referralOf($this, $carla)->status)->toBe('rejected')->and(referralOf($this, $carla)->rejected_reason)->toBe('below_minimum');

    $this->artisan('igr:referrals:qualify', ['--at' => now()->addDays(40)->toIso8601String()])->assertSuccessful();
    expect($this->ledgerCalls)->toBe([]);
});

it('respeta el límite mensual de recompensas de quien refiere', function () {
    $this->referralProgram(['rules' => ['monthly_limit' => 1], 'referrer_reward' => ['type' => 'points', 'points' => 300]]);
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);
    $friends = [$this->referralContact('Beto'), $this->referralContact('Carla')];
    foreach ($friends as $friend) {
        $this->inWs(fn () => Referral::query()->create(['referrer_contact_id' => $ana->id, 'code' => $code, 'friend_contact_id' => $friend->id, 'status' => 'qualified']));
    }
    $counts = $this->inWs(fn () => app(QualifyDueReferrals::class)->handle());
    expect($counts['rewarded'])->toBe(1)
        ->and($this->ledgerCalls)->toHaveCount(1)
        ->and($this->ledgerCalls[0]['type'])->toBe('points')->and($this->ledgerCalls[0]['value'])->toBe('300');
    $statuses = array_map(fn ($f) => referralOf($this, $f)->status, $friends);
    sort($statuses);
    expect($statuses)->toBe(['rejected', 'rewarded']);
    expect($this->inWs(fn () => Referral::query()->where('status', 'rejected')->value('rejected_reason')))->toBe('monthly_limit');
});

it('sin programa de puntos la recompensa queda pendiente con el error y se reintenta', function () {
    $this->referralProgram(['referrer_reward' => ['type' => 'points', 'points' => 300]]);
    $this->ledger->shouldReceive('earnPoints')->andReturn(null);
    $ana = $this->referralContact('Ana');
    $beto = $this->referralContact('Beto');
    $referral = $this->inWs(fn () => Referral::query()->create(['referrer_contact_id' => $ana->id, 'code' => referralCodeFor($this, $ana), 'friend_contact_id' => $beto->id, 'status' => 'qualified']));
    $this->inWs(fn () => app(QualifyDueReferrals::class)->handle());
    $referral->refresh();
    expect($referral->status)->toBe('qualified')->and($referral->reward['state'])->toBe('failed')->and($referral->reward['error'])->toBe('loyalty_inactive');
});

it('crea el referido cuando el amigo llega solo con el enlace: el pedido trae el código en el último contacto', function () {
    $this->referralProgram();
    $ana = $this->referralContact('Ana');
    $code = referralCodeFor($this, $ana);
    $this->referralOrder('order.paid', ['order_id' => '2201', 'customer_ref' => 'wc:221', 'coupons' => [], 'billing' => $this->referralBilling('Hugo', 'hugo@ejemplo.com', '300 555 0505')],
        ['last_touch' => ['referral_code' => strtolower($code), 'landing' => 'https://tienda.co/?ref='.$code, 'at' => now()->subHour()->toIso8601String()]], 'anon-hugo');
    $order = $this->inWs(fn () => Order::query()->where('external_id', '2201')->firstOrFail());
    $referral = $this->inWs(fn () => Referral::query()->where('friend_contact_id', $order->contact_id)->first());
    expect($referral)->not->toBeNull()
        ->and($referral->status)->toBe('ordered')->and($referral->referrer_contact_id)->toBe($ana->id)->and($referral->friend_coupon_id)->toBeNull()
        ->and($order->getAttribute('attributed_referral_id'))->toBe($referral->id)
        ->and($order->getAttribute('channel_last'))->toBe('affiliates');

    // Una segunda compra del mismo amigo no es «primera compra»: no cambia el referido.
    $this->referralOrder('order.paid', ['order_id' => '2202', 'customer_ref' => 'wc:221', 'coupons' => [], 'created_at' => now()->addDay()->toIso8601String(), 'billing' => $this->referralBilling('Hugo', 'hugo@ejemplo.com', '300 555 0505')],
        ['last_touch' => ['referral_code' => $code]]);
    expect($referral->refresh()->order_id)->toBe($order->id)
        ->and($this->inWs(fn () => Order::query()->where('external_id', '2202')->value('attributed_referral_id')))->toBeNull();
});

it('detecta en el pedido que el amigo envía al mismo destinatario que quien refiere', function () {
    $this->referralProgram();
    // Ana compró antes con su nombre y su ciudad.
    $this->referralOrder('order.paid', ['order_id' => '2301', 'customer_ref' => 'wc:231', 'coupons' => [], 'billing' => $this->referralBilling('Ana', 'ana@ejemplo.com', '300 555 0606')]);
    $ana = $this->inWs(fn () => Contact::query()->find(Order::query()->where('external_id', '2301')->value('contact_id')));
    $code = referralCodeFor($this, $ana);

    $ivan = $this->referralContact('Ivan', 'ivan@ejemplo.com');
    referralCapture($this, $ivan, $code);
    expect(referralOf($this, $ivan)->review_status)->toBeNull();
    // Ivan compra pero el pedido va a nombre de Ana, en su misma ciudad.
    $this->referralOrder('order.paid', ['order_id' => '2302', 'customer_ref' => 'wc:232', 'coupons' => [], 'billing' => $this->referralBilling('Ana', 'ivan@ejemplo.com', '300 555 0707')]);
    $referral = referralOf($this, $ivan);
    expect($referral->status)->toBe('ordered')->and($referral->signals())->toContain('same_address')->and($referral->review_status)->toBe('pending_review');
});
