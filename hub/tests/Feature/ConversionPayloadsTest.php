<?php

use App\Domain\Measurement\Conversions\ConversionInput;
use App\Domain\Measurement\Conversions\ConversionInputFactory;
use App\Domain\Measurement\Conversions\Platforms\DataManagerTarget;
use App\Domain\Measurement\Conversions\Platforms\Ga4Target;
use App\Domain\Measurement\Conversions\Platforms\MetaCapiTarget;
use App\Domain\Measurement\Conversions\Platforms\TikTokTarget;
use App\Domain\Measurement\Enums\Platform;
use App\Support\Tenancy\CurrentWorkspace;
use Tests\Support\MeasurementFixtures;

uses(MeasurementFixtures::class);

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site] = $this->connectedSite($this->ws);
    $this->input = app(CurrentWorkspace::class)->run($this->ws, fn () => app(ConversionInputFactory::class)->make($this->paidEvent($this->ws, $this->site->id), $this->ws, $this->site));
});

it('arma el Purchase de Meta CAPI con datos con hash, fbp/fbc y el event_id compartido con el píxel', function () {
    $dest = $this->destination($this->ws, Platform::Meta);
    $payload = app(MetaCapiTarget::class)->build($this->input, $dest);

    expect($payload['event_id'])->toBe('0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a')
        ->and($payload['action_source'])->toBe('website')
        ->and($payload['user_data']['em'][0])->toBe(hash('sha256', 'ana@ejemplo.com'))
        ->and($payload['user_data']['ph'][0])->toBe(hash('sha256', '573001234567'))
        ->and($payload['user_data']['fbc'])->toBe('fb.1.'.(strtotime('2026-11-17T18:05:00-05:00') * 1000).'.IwAR0abc')
        ->and($payload['custom_data']['value'])->toBe(282000.0)
        ->and(app(MetaCapiTarget::class)->matchKeys($payload))->toContain('email', 'phone', 'click_id', 'browser_id', 'external_id');
    $this->assertGolden('meta_purchase', $payload);
});

it('arma el evento de GA4 con client_id del cookie _ga y transaction_id', function () {
    $payload = app(Ga4Target::class)->build($this->input, $this->destination($this->ws, Platform::Google));
    expect($payload['client_id'])->toBe('111222333.1731880000')
        ->and($payload['events'][0]['name'])->toBe('purchase')
        ->and($payload['events'][0]['params']['transaction_id'])->toBe('1234')
        ->and($payload['user_data']['sha256_phone_number'][0])->toBe(hash('sha256', '+573001234567'));
    $this->assertGolden('ga4_purchase', $payload);
});

it('arma la conversión mejorada de Data Manager hacia la acción de compra', function () {
    $payload = app(DataManagerTarget::class)->build($this->input, $this->destination($this->ws, Platform::Google));
    expect($payload['destinations'][0]['operatingAccount'])->toBe(['accountType' => 'GOOGLE_ADS', 'accountId' => '1234567890'])
        ->and($payload['destinations'][0]['productDestinationId'])->toBe('555')
        ->and($payload['events'][0]['adIdentifiers'])->toBe(['gclid' => 'Cj0KCQ'])
        ->and($payload['events'][0]['transactionId'])->toBe('1234')
        ->and($payload)->not->toHaveKey('validateOnly');
    $this->assertGolden('google_data_manager_purchase', $payload);
});

it('arma el CompletePayment de TikTok con ttclid y ttp', function () {
    $payload = app(TikTokTarget::class)->build($this->input, $this->destination($this->ws, Platform::TikTok));
    expect($payload['event'])->toBe('CompletePayment')
        ->and($payload['event_id'])->toBe('0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a')
        ->and($payload['user']['ttclid'])->toBe('E.C.P.tt')
        ->and($payload['user']['phone'])->toBe(hash('sha256', '+573001234567'));
    $this->assertGolden('tiktok_purchase', $payload);
});

it('arma el evento de mensajería de Meta solo con nombres aceptados y ctwa_clid', function () {
    $target = app(MetaCapiTarget::class);
    $input = new ConversionInput(
        workspaceId: 'W', siteId: 'S', event: 'order.paid', eventId: 'e1', occurredAt: 1763420700, sourceUrl: null, referrer: null,
        ip: null, userAgent: null, contactId: 'C1', anonymousId: null, sessionId: null, defaultCountry: 'CO',
        phone: '3001234567', value: '1000.00', currency: 'COP', ctwaClid: 'ARAkLk', consentGranted: true,
    );
    $payload = $target->buildBusinessMessaging($input, 'Purchase');
    expect($payload['action_source'])->toBe('business_messaging')
        ->and($payload['messaging_channel'])->toBe('whatsapp')
        ->and($payload['user_data']['ctwa_clid'])->toBe('ARAkLk')
        ->and(fn () => $target->buildBusinessMessaging($input, 'PageView'))->toThrow(InvalidArgumentException::class);
});
