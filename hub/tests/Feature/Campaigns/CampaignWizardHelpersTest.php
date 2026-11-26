<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Campaigns\CampaignFixtures;

/*
 * Asistente de campañas (rediseño v2, Mensajes): a quién llega de verdad la audiencia por canal y la ventana legal
 * explicada antes de programar.
 */

uses(CampaignFixtures::class);

beforeEach(function () {
    $this->campaignWorkspace();
});

it('dice cuántos aceptaron cada canal y cuánto costaría el WhatsApp', function () {
    $ana = $this->contact('Ana');
    $luis = $this->contact('Luis', consents: ['email']);
    $marta = $this->contact('Marta', consents: []);
    $vip = $this->segmentOf('VIP', [$ana, $luis, $marta]);
    $campaign = $this->readyCampaign([$vip]);

    $res = $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/audience-count"), ['audience' => ['segments_include' => [$vip->publicId()]]])->assertOk();
    expect($res->json('count'))->toBe(3)
        ->and($res->json('by_channel.whatsapp'))->toBe(['consented' => 1, 'connected' => true])
        ->and($res->json('by_channel.email'))->toBe(['consented' => 2, 'connected' => true])
        ->and($res->json('by_channel.sms'))->toBe(['consented' => 0, 'connected' => false])
        ->and($res->json('whatsapp_cost.usd'))->toBeGreaterThan(0)
        ->and($res->json('whatsapp_cost.currency'))->toBe('COP');

    // Sin nadie con WhatsApp, no hay costo que estimar.
    DB::table('contact_consents')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->update(['status' => 'revoked']);
    expect($this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/audience-count"))->assertOk()->json('whatsapp_cost'))->toBeNull();
});

it('explica por qué la ley mueve la hora de envío: domingo, festivo o fuera de horario', function () {
    $this->travelTo(CarbonImmutable::parse('2026-12-04 10:00:00', 'America/Bogota'));   // viernes
    $explain = fn (string $mode, ?string $at) => $this->actingAs($this->owner)->getJson($this->api('/send-window?'.http_build_query(array_filter(['mode' => $mode, 'at' => $at]))))->assertOk();
    $at = fn (string $local) => CarbonImmutable::parse($local, 'America/Bogota')->toIso8601String();
    $local = fn (string $iso) => CarbonImmutable::parse($iso)->setTimezone('America/Bogota')->format('Y-m-d H:i');

    // Dentro de la franja: sale a la hora pedida.
    $ok = $explain('scheduled', $at('2026-12-04 15:00'));
    expect($ok->json('moved'))->toBeFalse()->and($ok->json('reason'))->toBeNull()->and($ok->json('requested_window'))->toBe(['07:00', '19:00']);

    // Sábado a las 16:00 (pasa de las 15:00) y el domingo no se envía: sale el lunes a las 7:00.
    $sat = $explain('scheduled', $at('2026-12-05 16:00'));
    expect($sat->json('moved'))->toBeTrue()->and($sat->json('reason'))->toBe('outside_hours')
        ->and($local($sat->json('sends_at')))->toBe('2026-12-07 07:00')
        ->and($sat->json('skipped'))->toBe([['date' => '2026-12-06', 'reason' => 'sunday', 'holiday' => null]]);

    // El martes 8 es festivo (Inmaculada Concepción): sale el miércoles.
    $holiday = $explain('scheduled', $at('2026-12-08 09:00'));
    expect($holiday->json('reason'))->toBe('holiday')->and($holiday->json('holiday'))->not->toBeNull()
        ->and($local($holiday->json('sends_at')))->toBe('2026-12-09 07:00');

    // Una fecha que ya pasó cuenta desde ahora; «a la hora de cada contacto» avisa que cada uno tiene la suya.
    expect($explain('scheduled', '2026-12-01T10:00:00-05:00')->json('reason'))->toBe('past')
        ->and($explain('contact_timezone', $at('2026-12-04 15:00'))->json('per_contact'))->toBeTrue()
        ->and($explain('now', null)->json('moved'))->toBeFalse();
});
