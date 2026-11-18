<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Identity\SiteToken;
use App\Domain\Loyalty\Contracts\MeSection;
use App\Domain\Referrals\Contracts\ReferralRewards;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\AffiliateConversion;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ReferralCodes;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Referrals\ReferralFixtures;

/*
 * API de M14 para la SPA (programa, lista, revisión y reportes), la sección de
 * «Mis recompensas», compartir el enlace y el aislamiento entre espacios de trabajo.
 */

uses(ReferralFixtures::class);

beforeEach(function () {
    $this->referralWorkspace();
});

function referralProgramPayload(array $overrides = []): array
{
    return array_replace_recursive([
        'status' => 'active',
        'friend_reward' => ['kind' => 'percent', 'amount' => 15, 'expires_days' => 30, 'minimum_amount' => null],
        'referrer_reward' => ['type' => 'wallet', 'amount' => 25000],
        'rules' => ['min_order' => 80000, 'monthly_limit' => 5, 'return_days' => 10, 'qualify_on' => 'completed', 'review_suspicious' => true, 'signal_window_days' => 30],
        'share_text' => 'Te regalo {regalo} en {tienda}: {enlace}',
    ], $overrides);
}

it('configura el programa: valores por defecto, validación, puntos solo con fidelización activa y auditoría', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);

    $this->actingAs($viewer)->getJson($this->referralApi('/referrals/program'))->assertOk()
        ->assertJsonPath('exists', false)->assertJsonPath('status', 'draft')->assertJsonPath('labels.example_link', 'https://tienda.co/?ref=ANA7K2');
    $this->actingAs($viewer)->putJson($this->referralApi('/referrals/program'), referralProgramPayload())->assertForbidden();

    $this->actingAs($marketing)->putJson($this->referralApi('/referrals/program'), referralProgramPayload(['friend_reward' => ['amount' => 150]]))->assertUnprocessable()->assertJsonValidationErrors('friend_reward.amount');
    $this->actingAs($marketing)->putJson($this->referralApi('/referrals/program'), referralProgramPayload(['share_text' => 'Sin enlace']))->assertUnprocessable()->assertJsonValidationErrors('share_text');
    $this->ledger->shouldReceive('isActive')->andReturn(false);
    $this->actingAs($marketing)->putJson($this->referralApi('/referrals/program'), referralProgramPayload(['referrer_reward' => ['type' => 'points', 'points' => 500]]))->assertUnprocessable()->assertJsonValidationErrors('referrer_reward.type');

    $this->actingAs($marketing)->putJson($this->referralApi('/referrals/program'), referralProgramPayload())->assertOk()
        ->assertJsonPath('exists', true)->assertJsonPath('status', 'active')
        ->assertJsonPath('labels.friend_reward', '15% en su primera compra')
        ->assertJsonPath('labels.referrer_reward', '$ 25.000 de saldo a favor')
        ->assertJsonPath('labels.share_preview', 'Te regalo 15% en tienda.co: https://tienda.co/?ref=ANA7K2')
        ->assertJsonPath('rules.min_order', '80000.00');
    expect($this->inWs(fn () => ReferralProgram::query()->count()))->toBe(1)
        ->and(DB::table('audit_logs')->where('action', 'referrals.program_saved')->exists())->toBeTrue();
});

it('lista los referidos con su estado y señales, y la cola de revisión aprueba (y recompensa) o rechaza', function () {
    $this->referralProgram();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $ana = $this->referralContact('Ana', anon: 'anon-ana');
    $code = $this->inWs(fn () => app(ReferralCodes::class)->forContact($ana));
    $beto = $this->referralContact('Beto');
    $carla = $this->referralContact('Carla');
    $this->inWs(fn () => app(ReferralRewards::class)->onFriendCaptured($beto->id, $code, 'anon-ana', false, $this->site->id));
    $this->inWs(fn () => app(ReferralRewards::class)->onFriendCaptured($carla->id, $code, 'anon-ana', false, $this->site->id));
    $betoRef = $this->inWs(fn () => Referral::query()->where('friend_contact_id', $beto->id)->firstOrFail());
    $carlaRef = $this->inWs(fn () => Referral::query()->where('friend_contact_id', $carla->id)->firstOrFail());
    $this->inWs(fn () => $betoRef->forceFill(['status' => 'qualified'])->save());

    $list = $this->actingAs($marketing)->getJson($this->referralApi('/referrals?review=pending'))->assertOk()->assertJsonPath('pending_review', 2)->json();
    expect($list['data'])->toHaveCount(2)->and($list['data'][0]['signals'])->toBe(['same_device'])
        ->and($list['data'][0]['referrer']['name'])->toBe('Ana Prueba');
    // Quien solo ve los tableros no ve nombres de personas.
    $this->actingAs($viewer)->getJson($this->referralApi('/referrals'))->assertOk()->assertJsonPath('data.0.referrer', null);
    $this->actingAs($viewer)->postJson($this->referralApi('/referrals/'.$betoRef->publicId().'/approve'))->assertForbidden();

    $this->actingAs($marketing)->postJson($this->referralApi('/referrals/'.$betoRef->publicId().'/approve'))->assertOk()
        ->assertJsonPath('review_status', 'approved')->assertJsonPath('status', 'rewarded');
    expect($this->ledgerCalls)->toHaveCount(1);
    $this->actingAs($marketing)->postJson($this->referralApi('/referrals/'.$betoRef->publicId().'/approve'))->assertStatus(409);
    $this->actingAs($marketing)->postJson($this->referralApi('/referrals/'.$carlaRef->publicId().'/reject'))->assertOk()
        ->assertJsonPath('status', 'rejected')->assertJsonPath('rejected_reason', 'manual_review');
    $this->actingAs($marketing)->getJson($this->referralApi('/referrals?status=rejected'))->assertOk()->assertJsonCount(1, 'data');
    expect(DB::table('audit_logs')->whereIn('action', ['referrals.review_approved', 'referrals.review_rejected'])->count())->toBe(2);
});

it('reporta ingresos por referidos y afiliados, costo por referido y la mejor campaña de cada creador', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00:00', 'America/Bogota'));
    $ana = $this->referralContact('Ana');
    $beto = $this->referralContact('Beto');
    $laura = $this->referralAffiliate();
    $this->inWs(function () use ($ana, $beto, $laura) {
        Referral::query()->create(['referrer_contact_id' => $ana->id, 'code' => 'ANA7K2', 'friend_contact_id' => $beto->id, 'status' => 'rewarded', 'ordered_at' => now()->subDays(3), 'order_amount_ws' => '200000.00', 'rewarded_at' => now()->subDay(), 'reward' => ['type' => 'wallet', 'amount' => '20000.00'], 'reward_cost_ws' => '40000.00']);
        foreach ([['o1', 'navidad', '100000.00'], ['o2', 'navidad', '50000.00'], ['o3', null, '300000.00']] as [$ext, $campaign, $amount]) {
            $id = strtoupper((string) Str::ulid());
            DB::table('orders')->insert(['id' => $id, 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => $ext, 'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => $amount, 'total_ws' => $amount, 'utm_last' => $campaign ? json_encode(['campaign' => $campaign]) : null, 'created_at' => now()->subDays(2), 'updated_at' => now()]);
            AffiliateConversion::query()->create(['affiliate_id' => $laura->id, 'order_id' => $id, 'via' => $campaign ? 'link' : 'coupon', 'amount_ws' => $amount, 'commission_ws' => '1000.00', 'status' => 'pending']);
        }
    });

    $report = $this->actingAs($this->owner)->getJson($this->referralApi('/referrals/report?from=2026-11-01&to=2026-11-20'))->assertOk()->json();
    expect($report['kpis']['referral_revenue'])->toBe('200000.00')
        ->and($report['kpis']['affiliate_revenue'])->toBe('450000.00')
        ->and($report['kpis']['affiliate_orders'])->toBe(3)
        ->and($report['kpis']['cost_per_referral'])->toBe('40000.00')
        ->and($report['top_referrers'][0]['contact']['name'])->toBe('Ana Prueba')
        ->and($report['creators'][0]['affiliate']['name'])->toBe('Laura Influencer')
        ->and($report['creators'][0]['best_campaign']['via'])->toBe('coupon')            // 300.000 con el cupón
        ->and($report['creators'][0]['best_campaign']['revenue'])->toBe('300000.00')
        ->and($report['series']['bucket'])->toBe('week');
    $sum = array_sum(array_map(fn ($p) => (float) $p['affiliates'], $report['series']['points']));
    expect($sum)->toBe(450000.0);
});

it('agrega la sección de referidos a «Mis recompensas» y registra cuando el cliente comparte su enlace', function () {
    $this->referralProgram(['share_text' => 'Usa mi enlace {enlace} y te dan {regalo}']);
    $this->referralOrder('order.paid', ['order_id' => '5001', 'customer_ref' => 'wc:501', 'coupons' => [], 'billing' => $this->referralBilling('Ana', 'ana@ejemplo.com', '300 555 1313')]);
    $ana = $this->inWs(fn () => Contact::query()->firstOrFail());

    $sections = collect(app()->tagged(MeSection::TAG))->filter(fn ($s) => $s->key() === 'referral');
    expect($sections)->toHaveCount(1);
    $section = $this->inWs(fn () => $sections->first()->forContact($this->site, $ana));
    expect($section['code'])->toMatch('/^ANA/')
        ->and($section['link'])->toBe('https://tienda.co/?ref='.$section['code'])
        ->and($section['share_text'])->toBe('Usa mi enlace https://tienda.co/?ref='.$section['code'].' y te dan 10%')
        ->and($section['friend_reward'])->toBe('10% en su primera compra')
        ->and($section['referrer_reward'])->toBe('$ 20.000 de saldo a favor')
        ->and($this->inWs(fn () => $sections->first()->forContact($this->site, null)))->toBeNull();

    $token = SiteToken::sign($this->secret, ['site' => $this->site->publicId(), 'ref' => 'wc:501', 'exp' => time() + 3600]);
    $share = fn (array $body) => $this->call('POST', '/p/'.$this->site->public_key.'/me/share', [], [], [], ['CONTENT_TYPE' => 'text/plain;charset=UTF-8', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode($body));
    $share(['token' => $token, 'channel' => 'whatsapp'])->assertStatus(202)->assertHeader('Access-Control-Allow-Origin', 'https://tienda.co');
    $share(['token' => 'falso.'.str_repeat('0', 64), 'channel' => 'whatsapp'])->assertStatus(401);
    $rows = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'referral.shared'");
    expect($rows)->toHaveCount(1)->and($rows[0]['contact_id'])->toBe($ana->id)
        ->and(json_decode($rows[0]['p'], true))->toEqualCanonicalizing(['referral_code' => $section['code'], 'channel' => 'whatsapp'])
        ->and(DB::table('referral_fingerprints')->where('contact_id', $ana->id)->where('kind', 'ip')->count())->toBeGreaterThan(0);

    // Programa apagado: no hay sección ni se puede compartir.
    $this->inWs(fn () => ReferralProgram::query()->update(['status' => 'paused']));
    expect($this->inWs(fn () => $sections->first()->forContact($this->site, $ana)))->toBeNull();
    $share(['token' => $token, 'channel' => 'copy'])->assertStatus(404);
});

it('aísla referidos, afiliados y comisiones por espacio de trabajo', function () {
    $this->referralProgram();
    $laura = $this->referralAffiliate();
    $ana = $this->referralContact('Ana');
    $beto = $this->referralContact('Beto');
    $referral = $this->inWs(fn () => Referral::query()->create(['referrer_contact_id' => $ana->id, 'code' => 'ANA7K2', 'friend_contact_id' => $beto->id, 'status' => 'signed_up', 'review_status' => 'pending_review']));

    $otherOwner = $this->userWith2fa();
    $other = $this->workspaceFor($otherOwner, 'Otra tienda');
    $api = fn (string $path) => '/api/w/'.$other->publicId().$path;
    $this->actingAs($otherOwner)->getJson($api('/referrals'))->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('pending_review', 0);
    $this->actingAs($otherOwner)->postJson($api('/referrals/'.$referral->publicId().'/approve'))->assertNotFound();
    $this->actingAs($otherOwner)->getJson($api('/affiliates'))->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($otherOwner)->getJson($api('/affiliates/'.$laura->publicId()))->assertNotFound();
    $this->actingAs($otherOwner)->getJson($api('/referrals/program'))->assertOk()->assertJsonPath('exists', false);
    // Tampoco por el código: el de Ana no existe en el otro espacio.
    expect(app(CurrentWorkspace::class)->run($other, fn () => app(ReferralCodes::class)->contactFor('ANA7K2')))->toBeNull()
        ->and(app(CurrentWorkspace::class)->run($other, fn () => Affiliate::query()->count()))->toBe(0);
    // Y no se puede ver el panel ajeno sin entrar al espacio.
    $this->actingAs($this->owner)->getJson($api('/referrals'))->assertNotFound();
});
