<?php

declare(strict_types=1);

namespace Tests\Feature\Referrals;

use App\Domain\Commerce\Contracts\CouponIssuer;
use App\Domain\Contacts\Actions\CaptureContact;
use App\Domain\Contacts\Data\CaptureInput;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Loyalty\Contracts\LoyaltyLedger;
use App\Domain\Referrals\Models\Affiliate;
use App\Domain\Referrals\Models\ReferralProgram;
use App\Domain\Referrals\Support\ProgramSettings;
use App\Domain\Referrals\Support\ReferralSwitch;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

/**
 * Datos de prueba de M14: tienda en Colombia conectada, programa de referidos,
 * cupones y libro de puntos falsos, y pedidos que entran por la ingesta real.
 *
 * @property User $owner
 * @property Workspace $ws
 * @property Site $site
 * @property string $secret
 * @property FakeReferralCoupons $coupons
 * @property MockInterface&LoyaltyLedger $ledger
 * @property list<array{type: string, contact: string, value: string, key: string}> $ledgerCalls
 */
trait ReferralFixtures
{
    public function referralWorkspace(): void
    {
        $this->useIngestInfrastructure();
        $this->owner = $this->userWith2fa();
        $this->ws = $this->workspaceFor($this->owner);
        $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
        [$this->site, $this->secret] = $this->connectedSite($this->ws);
        $this->coupons = new FakeReferralCoupons;
        app()->instance(CouponIssuer::class, $this->coupons);
        $this->ledgerCalls = [];
        $this->ledger = Mockery::mock(LoyaltyLedger::class);
        $this->ledger->shouldReceive('isActive')->andReturn(true)->byDefault();
        $this->ledger->shouldReceive('rulePoints')->andReturn(500)->byDefault();
        $this->ledger->shouldReceive('earnPoints')->andReturnUsing(function (string $contact, int $points, string $reason, string $key) {
            $this->ledgerCalls[] = ['type' => 'points', 'contact' => $contact, 'value' => (string) $points, 'key' => $key];

            return 'LG'.strtoupper(Str::random(24));
        })->byDefault();
        $this->ledger->shouldReceive('creditWallet')->andReturnUsing(function (string $contact, string $amount, string $reason, string $key) {
            $this->ledgerCalls[] = ['type' => 'wallet', 'contact' => $contact, 'value' => $amount, 'key' => $key];

            return 'LG'.strtoupper(Str::random(24));
        })->byDefault();
        app()->instance(LoyaltyLedger::class, $this->ledger);
    }

    public function inWs(callable $fn, ?Workspace $ws = null): mixed
    {
        return app(CurrentWorkspace::class)->run($ws ?? $this->ws, $fn);
    }

    /** @param array<string, mixed> $overrides */
    public function referralProgram(array $overrides = [], ?Workspace $ws = null): ReferralProgram
    {
        $ws ??= $this->ws;

        return $this->inWs(function () use ($overrides, $ws) {
            $program = ReferralProgram::query()->create(array_replace_recursive([
                'workspace_id' => $ws->id,
                'status' => 'active',
                'friend_reward' => ['kind' => 'percent', 'amount' => '10.00', 'expires_days' => 30],
                'referrer_reward' => ['type' => 'wallet', 'amount' => '20000.00'],
                'rules' => ProgramSettings::defaultRules(),
                'share_text' => null,
            ], $overrides));
            ReferralSwitch::forget($ws->id);

            return $program;
        }, $ws);
    }

    /**
     * Una persona que dejó sus datos en el sitio (misma identidad que la ingesta).
     */
    public function referralContact(string $name, ?string $email = null, ?string $phone = null, ?string $anon = null, ?Workspace $ws = null, ?Site $site = null): Contact
    {
        $ws ??= $this->ws;
        $site ??= $this->site;
        $email ??= Str::slug($name).'-'.Str::lower(Str::random(4)).'@ejemplo.com';
        $phone ??= '+5730'.random_int(10000000, 99999999);

        return $this->inWs(fn () => app(CaptureContact::class)->handle($ws, $site, new CaptureInput(
            source: 'widget:prueba', email: $email, phone: $phone, firstName: $name, lastName: 'Prueba', anonymousId: $anon, country: 'CO',
        ))->contact, $ws);
    }

    /** @param array<string, mixed> $attrs */
    public function referralAffiliate(array $attrs = []): Affiliate
    {
        return $this->inWs(function () use ($attrs) {
            $affiliate = Affiliate::query()->create($attrs + [
                'name' => 'Laura Influencer', 'kind' => 'influencer', 'link_code' => 'LAURA', 'coupon_codes' => ['LAURA15'],
                'commission' => ['type' => 'percent', 'value' => '10.00', 'basis' => 'net'], 'status' => 'active',
            ]);
            ReferralSwitch::forget($this->ws->id);

            return $affiliate;
        });
    }

    /**
     * Un pedido pagado que entra por la ingesta, con su `last_touch` (v4) y el
     * `anonymous_id` del navegador.
     *
     * @param  array<string, mixed>  $order  se fusiona sobre orderSnapshot()
     * @param  array<string, mixed>  $context
     */
    public function referralOrder(string $event, array $order, array $context = [], ?string $anon = null, ?string $at = null): void
    {
        $overrides = ['schema_version' => 4, 'context' => $context + ['ip' => '181.50.10.20']];
        if ($at !== null) {
            $overrides['occurred_at'] = CarbonImmutable::parse($at)->toIso8601String();
        }
        if ($anon !== null) {
            $overrides['identity'] = ['anonymous_id' => $anon];
        }
        $snapshot = $this->orderSnapshot($order);
        $props = ['order' => $snapshot];
        if (! in_array($event, ['order.paid', 'order.created'], true)) {
            $props['order_id'] = $snapshot['order_id'];
        }
        if ($event === 'order.refunded') {
            $props += ['refund_id' => 'r'.$snapshot['order_id'], 'amount' => $snapshot['totals']['refunded'], 'is_partial' => $snapshot['totals']['refunded'] !== $snapshot['totals']['total']];
        }
        $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, $event, $props, $overrides)])->assertSuccessful();
        $this->runWorker();
    }

    /** @return array<string, mixed> billing de una persona */
    public function referralBilling(string $first, string $email, string $phone, string $city = 'Manizales'): array
    {
        return ['first_name' => $first, 'last_name' => 'Prueba', 'email' => $email, 'phone' => $phone, 'city' => $city, 'state' => 'CO-CAL', 'country' => 'CO', 'postcode' => ''];
    }

    public function referralApi(string $path): string
    {
        return '/api/w/'.$this->ws->publicId().$path;
    }
}
