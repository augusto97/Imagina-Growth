<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Referrals\Http\Public\CreatorPortalController;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Queries\CreatorPortalData;
use App\Domain\Referrals\Support\CreatorToken;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Referrals\ReferralFixtures;

/*
 * Afiliados e influencers (modulos.md M14): atribución por cupón o enlace, comisión,
 * estados, pagos manuales y panel del creador con enlace mágico.
 */

uses(ReferralFixtures::class);

beforeEach(function () {
    $this->referralWorkspace();
    $this->travelTo(CarbonImmutable::parse('2026-11-23 10:00:00', 'America/Bogota'));
});

function referralConversion($test, string $externalOrderId): ?AffiliateConversion
{
    return $test->inWs(fn () => AffiliateConversion::query()->where('order_id', Order::query()->where('external_id', $externalOrderId)->value('id'))->first());
}

it('atribuye el pedido con el cupón del afiliado, calcula su comisión y la aprueba al pasar la devolución', function () {
    $laura = $this->referralAffiliate();
    $order = ['order_id' => '4001', 'number' => '4001', 'customer_ref' => 'wc:401', 'coupons' => ['laura15'], 'billing' => $this->referralBilling('Juan', 'juan@ejemplo.com', '300 555 0808')];
    $this->referralOrder('order.paid', $order);

    $conversion = referralConversion($this, '4001');
    $projected = $this->inWs(fn () => Order::query()->where('external_id', '4001')->firstOrFail());
    // Neto: 282.000 − 12.000 de envío − 0 de impuestos = 270.000; 10 % = 27.000.
    expect($conversion)->not->toBeNull()
        ->and($conversion->via)->toBe('coupon')->and($conversion->status)->toBe('pending')
        ->and($conversion->amount_ws)->toBe('270000.00')->and($conversion->commission_ws)->toBe('27000.00')
        ->and($projected->getAttribute('attributed_affiliate_id'))->toBe($laura->id)
        ->and($projected->getAttribute('attributed_referral_id'))->toBeNull();

    $this->referralOrder('order.completed', $order + ['status' => 'completed', 'modified_at' => now()->toIso8601String()]);
    expect(referralConversion($this, '4001')->qualifies_at?->toDateString())->toBe(now()->addDays(15)->toDateString());
    $this->artisan('igr:referrals:qualify', ['--at' => now()->addDays(16)->toIso8601String()])->assertSuccessful();
    $conversion = referralConversion($this, '4001');
    expect($conversion->status)->toBe('approved')->and($conversion->approved_at)->not->toBeNull();

    // Pagos: el dueño ve lo aprobado, lo exporta y lo marca pagado.
    $this->actingAs($this->owner)->getJson($this->referralApi('/affiliate-conversions/payouts'))->assertOk()
        ->assertJsonPath('data.0.name', 'Laura Influencer')->assertJsonPath('data.0.commission', '27000.00')->assertJsonPath('data.0.orders', 1);
    $csv = $this->actingAs($this->owner)->get($this->referralApi('/affiliate-conversions/payouts.csv'));
    $csv->assertOk();
    expect($csv->streamedContent())->toContain('Laura Influencer')->toContain('27000.00')->toContain($conversion->publicId());
    expect(DB::table('audit_logs')->where('action', 'affiliates.payouts_exported')->exists())->toBeTrue();
    $this->actingAs($this->owner)->postJson($this->referralApi('/affiliate-conversions/mark-paid'), ['ids' => [$conversion->publicId()]])->assertOk()->assertJsonPath('updated', 1);
    expect(referralConversion($this, '4001')->status)->toBe('paid');
    // Un reembolso después de pagada no la toca.
    $this->referralOrder('order.refunded', $order + ['status' => 'refunded', 'modified_at' => now()->addMinute()->toIso8601String(), 'totals' => ['refunded' => '282000.00']]);
    expect(referralConversion($this, '4001')->status)->toBe('paid');
});

it('atribuye por el enlace del afiliado, usa comisión fija o sobre el total y la anula si se cancela', function () {
    $this->referralAffiliate(['name' => 'Tienda Aliada', 'kind' => 'partner', 'link_code' => 'ALIADA', 'coupon_codes' => null, 'commission' => ['type' => 'fixed', 'value' => '15000.00']]);
    $order = ['order_id' => '4101', 'customer_ref' => 'wc:411', 'coupons' => [], 'billing' => $this->referralBilling('Kata', 'kata@ejemplo.com', '300 555 0909')];
    $this->referralOrder('order.paid', $order, ['last_touch' => ['referral_code' => 'aliada', 'at' => now()->subHour()->toIso8601String()]]);
    $conversion = referralConversion($this, '4101');
    expect($conversion->via)->toBe('link')->and($conversion->commission_ws)->toBe('15000.00')
        ->and($this->inWs(fn () => Order::query()->where('external_id', '4101')->value('channel_last')))->toBe('affiliates');

    $this->referralOrder('order.cancelled', $order + ['status' => 'cancelled', 'modified_at' => now()->addMinute()->toIso8601String()]);
    $conversion = referralConversion($this, '4101');
    expect($conversion->status)->toBe('void')->and($conversion->void_reason)->toBe('order_cancelled');

    // Porcentaje sobre el total (282.000): 5 % = 14.100.
    $this->referralAffiliate(['name' => 'Mario', 'link_code' => 'MARIO', 'coupon_codes' => ['MARIO5'], 'commission' => ['type' => 'percent', 'value' => '5', 'basis' => 'total']]);
    $this->referralOrder('order.paid', ['order_id' => '4102', 'customer_ref' => 'wc:412', 'coupons' => ['mario5'], 'billing' => $this->referralBilling('Luz', 'luz@ejemplo.com', '300 555 1010')]);
    expect(referralConversion($this, '4102')->commission_ws)->toBe('14100.00');
});

it('el cupón del afiliado gana sobre el enlace de un cliente, y nunca se atribuye a los dos', function () {
    $this->referralProgram();
    $this->referralAffiliate();
    $ana = $this->referralContact('Ana');
    $code = $this->inWs(fn () => app(ReferralCodes::class)->forContact($ana));
    $this->referralOrder('order.paid', ['order_id' => '4201', 'customer_ref' => 'wc:421', 'coupons' => ['laura15'], 'billing' => $this->referralBilling('Nico', 'nico@ejemplo.com', '300 555 1111')],
        ['last_touch' => ['referral_code' => $code]]);
    $order = $this->inWs(fn () => Order::query()->where('external_id', '4201')->firstOrFail());
    expect($order->getAttribute('attributed_affiliate_id'))->not->toBeNull()->and($order->getAttribute('attributed_referral_id'))->toBeNull()
        ->and($this->inWs(fn () => Referral::query()->count()))->toBe(0);
});

it('administra afiliados con permisos: el equipo de marketing crea, quien solo ve no, y los datos de pago son del dueño', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $payload = ['name' => 'Sofía Creadora', 'kind' => 'influencer', 'email' => 'sofia@creadora.co', 'coupon_codes' => ['sofia10'], 'commission' => ['type' => 'percent', 'value' => 12], 'payout_info' => 'Nequi 300 000 0000', 'material' => [['label' => 'Fotos de la campaña', 'url' => 'https://drive.example.com/x']]];

    $this->actingAs($viewer)->postJson($this->referralApi('/affiliates'), $payload)->assertForbidden();
    $created = $this->actingAs($marketing)->postJson($this->referralApi('/affiliates'), $payload)->assertCreated()
        ->assertJsonPath('coupon_codes', ['SOFIA10'])->assertJsonPath('email', 'sofia@creadora.co')->assertJsonPath('payout_info', null)->json();
    expect($created['link_code'])->toMatch('/^SOFIA[A-HJ-NP-Z2-9]{2,4}$/');
    $affiliate = $this->inWs(fn () => Affiliate::findByPublicId($created['id']));
    expect($affiliate->payout_info)->toBeNull()   // marketing no puede escribir datos de pago
        ->and($affiliate->email_bidx)->not->toBeNull()
        ->and(DB::table('affiliates')->where('id', $affiliate->id)->value('email'))->not->toContain('sofia');   // cifrado

    $this->actingAs($this->owner)->patchJson($this->referralApi('/affiliates/'.$created['id']), ['payout_info' => 'Nequi 300 000 0000'])->assertOk()->assertJsonPath('payout_info', 'Nequi 300 000 0000');
    $this->actingAs($viewer)->getJson($this->referralApi('/affiliates'))->assertOk()->assertJsonPath('data.0.email', null)->assertJsonPath('data.0.payout_info', null);
    $this->actingAs($marketing)->getJson($this->referralApi('/affiliate-conversions/payouts'))->assertForbidden();

    // Cupones y códigos únicos.
    $this->actingAs($marketing)->postJson($this->referralApi('/affiliates'), ['name' => 'Otro', 'kind' => 'affiliate', 'coupon_codes' => ['SOFIA10'], 'commission' => ['type' => 'fixed', 'value' => 1000]])->assertUnprocessable()->assertJsonValidationErrors('coupon_codes');
    $this->actingAs($marketing)->postJson($this->referralApi('/affiliates'), ['name' => 'Otro', 'kind' => 'affiliate', 'link_code' => $created['link_code'], 'commission' => ['type' => 'fixed', 'value' => 1000]])->assertUnprocessable()->assertJsonValidationErrors('link_code');
    $this->actingAs($marketing)->postJson($this->referralApi('/affiliates'), ['name' => 'Otro', 'kind' => 'affiliate', 'commission' => ['type' => 'percent', 'value' => 120]])->assertUnprocessable();

    $this->actingAs($marketing)->deleteJson($this->referralApi('/affiliates/'.$created['id']))->assertOk();
    expect($this->inWs(fn () => Affiliate::query()->find($affiliate->id)->status))->toBe('archived');
    $this->actingAs($marketing)->getJson($this->referralApi('/affiliates'))->assertOk()->assertJsonCount(0, 'data');
});

it('envía el enlace mágico del panel del creador: muestra sus ventas, los enlaces viejos dejan de servir y uno vencido se puede renovar', function () {
    $fake = FakeMessageDispatcher::install();
    $laura = $this->referralAffiliate(['email' => 'laura@creadora.co', 'material' => [['label' => 'Guía de la marca', 'url' => 'https://drive.example.com/guia']]]);
    $this->referralOrder('order.paid', ['order_id' => '4301', 'number' => '4301', 'customer_ref' => 'wc:431', 'coupons' => ['laura15'], 'billing' => $this->referralBilling('Olga', 'olga@ejemplo.com', '300 555 1212')],
        ['last_touch' => ['utm' => ['source' => 'instagram', 'medium' => 'social', 'campaign' => 'navidad-laura']]]);

    $first = $this->actingAs($this->owner)->postJson($this->referralApi('/affiliates/'.$laura->publicId().'/portal-link'))->assertOk()->assertJsonPath('sent', true)->json('url');
    expect($fake->dispatched)->toHaveCount(1)
        ->and($fake->dispatched[0]->to)->toBe('laura@creadora.co')
        ->and($fake->dispatched[0]->kind->value)->toBe('transactional')
        ->and($fake->dispatched[0]->context['html'])->toContain($first);
    $token = substr($first, strlen('http://localhost/creadores/'));

    // El panel (la SPA tiene su propia ruta comodín: se prueba el controlador).
    $page = app(CreatorPortalController::class)->show($token, app(CreatorPortalData::class));
    $html = (string) $page->getContent();
    expect($page->getStatusCode())->toBe(200)
        ->and($html)->toContain('Laura Influencer')->toContain('https://tienda.co/?ref=LAURA')->toContain('LAURA15')
        ->and($html)->toContain('#4301')->toContain('Guía de la marca')->toContain('navidad-laura')
        ->and($html)->not->toContain('olga@ejemplo.com')->not->toContain('Olga');

    // Un enlace nuevo invalida el anterior.
    $second = $this->actingAs($this->owner)->postJson($this->referralApi('/affiliates/'.$laura->publicId().'/portal-link'), ['email' => false])->assertOk()->assertJsonPath('sent', false)->json('url');
    expect(app(CreatorPortalController::class)->show($token, app(CreatorPortalData::class))->getStatusCode())->toBe(404)
        ->and($second)->not->toBe($first);

    // Vencido: ofrece mandar uno nuevo, que solo va al correo registrado.
    $nonce = $this->inWs(fn () => Affiliate::query()->find($laura->id)->portal_nonce);
    $expired = CreatorToken::make($this->ws->id, $laura->id, $nonce, now()->subDay()->getTimestamp());
    expect((string) app(CreatorPortalController::class)->show($expired, app(CreatorPortalData::class))->getContent())->toContain('Enviarme un enlace nuevo');
    $this->post('/creadores/'.$expired)->assertOk()->assertSee('Te enviamos un enlace nuevo');
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->to)->toBe('laura@creadora.co');

    // Tokens alterados o de un afiliado archivado: no.
    expect(app(CreatorPortalController::class)->show($token.'x', app(CreatorPortalData::class))->getStatusCode())->toBe(404)
        ->and(CreatorToken::parse('basura'))->toBeNull();
});
