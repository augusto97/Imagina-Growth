<?php

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Campaigns\CampaignFixtures;

uses(CampaignFixtures::class);

beforeEach(function () {
    $this->campaignWorkspace();
});

it('arma la audiencia con segmentos incluidos y excluidos y reglas de compra y mensajes', function () {
    [$ana, $luis, $marta, $pedro] = [$this->contact('Ana'), $this->contact('Luis'), $this->contact('Marta'), $this->contact('Pedro')];
    $vip = $this->segmentOf('VIP', [$ana, $luis, $marta, $pedro]);
    $staff = $this->segmentOf('Empleados', [$pedro]);
    // Luis compró hace 3 días; Marta recibió un WhatsApp ayer.
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => 'S1', 'external_id' => '1', 'contact_id' => $luis->id, 'status' => 'processing', 'is_paid' => true, 'currency' => 'COP', 'total' => 100000, 'total_ws' => 100000, 'created_at' => now()->subDays(3), 'updated_at' => now()]);
    DB::table('messages')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $marta->id, 'channel' => 'whatsapp', 'direction' => 'out', 'kind' => 'marketing', 'source_type' => 'campaign', 'status' => 'delivered', 'created_at' => now()->subDay(), 'updated_at' => now()]);

    $query = app(AudienceQuery::class);
    $names = [$ana->id => 'Ana', $luis->id => 'Luis', $marta->id => 'Marta', $pedro->id => 'Pedro'];
    $audience = function (array $a) use ($query, $names) {
        $ids = $this->inWs(fn () => $query->query($this->ws, $a)->pluck('c.id')->all());
        $out = array_map(fn ($id) => $names[$id], $ids);
        sort($out);

        return $out;
    };
    expect($audience(['segments_include' => [$vip->publicId()]]))->toBe(['Ana', 'Luis', 'Marta', 'Pedro'])
        ->and($audience(['segments_include' => [$vip->publicId()], 'segments_exclude' => [$staff->publicId()]]))->toBe(['Ana', 'Luis', 'Marta'])
        ->and($audience(['segments_include' => [$vip->publicId()], 'rules_exclude' => [['type' => 'purchased_within_days', 'days' => 7], ['type' => 'messaged_within_days', 'days' => 3]]]))->toBe(['Ana', 'Pedro'])
        ->and($audience(['segments_include' => [$vip->publicId()], 'rules_include' => [['type' => 'never_purchased']]]))->toBe(['Ana', 'Marta', 'Pedro'])
        ->and($audience(['segments_include' => []]))->toBe([]);

    $campaign = $this->readyCampaign([$vip]);
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/audience-count"), ['audience' => ['segments_include' => [$vip->publicId()], 'segments_exclude' => [$staff->publicId()]]])
        ->assertOk()->assertJsonPath('count', 3);
    $this->actingAs($this->owner)->patchJson($this->api("/{$campaign->publicId()}"), ['audience' => ['rules_exclude' => [['type' => 'inventada']]]])->assertUnprocessable();
});

it('revisa costo y cumplimiento antes de aprobar: quién queda fuera y por qué', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-23 12:00:00', 'America/Bogota'));   // lunes
    $ok = $this->contact('Ana');
    $mx = $this->contact('Lupita', attrs: ['country' => 'MX', 'timezone' => 'America/Mexico_City']);
    $noConsent = $this->contact('Sin consentimiento', consents: ['email']);
    $unsubscribed = $this->contact('Dada de baja');
    $busy = $this->contact('Ya recibió hoy');
    DB::table('suppressions')->insert(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'value_bidx' => $unsubscribed->phone_bidx, 'reason' => 'unsubscribe']);
    $segment = $this->segmentOf('Todos', [$ok, $mx, $noConsent, $unsubscribed, $busy]);
    // Programada para el martes 24 a las 10:00; «Ya recibió hoy» ya tiene un mensaje comercial ese día.
    $sendAt = CarbonImmutable::parse('2026-11-24 10:00:00', 'America/Bogota');
    $this->inWs(fn () => app(SendPolicy::class)->checkAndReserve(new PolicyRequest($this->ws, $busy->fresh(), Channel::WhatsApp, MessageKind::Marketing, 'flow', null, $sendAt->subHour())));
    $campaign = $this->readyCampaign([$segment], ['send_mode' => 'scheduled', 'send_at' => $sendAt->toIso8601String()]);

    $res = $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/review"))->assertOk();
    $excluded = collect($res->json('compliance.excluded'))->pluck('count', 'reason')->all();
    $deferred = collect($res->json('compliance.deferred'))->pluck('count', 'reason')->all();
    expect($res->json('compliance.audience'))->toBe(5)
        ->and($res->json('compliance.will_send'))->toBe(2)
        ->and($excluded)->toBe(['no_consent' => 1, 'unsubscribed' => 1])
        ->and($deferred)->toBe(['frequency_day' => 1])
        ->and($res->json('compliance.channel_available'))->toBeTrue()
        ->and(collect($res->json('compliance.excluded'))->firstWhere('reason', 'no_consent')['label'])->toContain('consentimiento');
    // Costo: 3 destinatarios efectivos (2 + 1 aplazado): 2 en Colombia y 1 en México.
    $byCountry = collect($res->json('cost.by_country'))->keyBy('country');
    expect($res->json('cost.recipients'))->toBe(3)
        ->and($byCountry['CO']['recipients'])->toBe(2)->and($byCountry['MX']['recipients'])->toBe(1)
        ->and($res->json('cost.total_usd'))->toEqualWithDelta(2 * 0.0125 + 0.0305, 0.00001);
    expect($this->inWs(fn () => Campaign::query()->first()->compliance_check['will_send']))->toBe(2);
});

it('aprobar exige rol de marketing, cuenta del canal y contenido; queda en la auditoría', function () {
    $segment = $this->segmentOf('VIP', [$this->contact('Ana')]);
    $campaign = $this->readyCampaign([$segment], ['send_mode' => 'scheduled', 'send_at' => now()->addDay()->toIso8601String()]);
    $id = $campaign->publicId();

    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson($this->api())->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewer)->getJson($this->api("/$id/report"))->assertOk();
    $this->actingAs($viewer)->postJson($this->api("/$id/schedule"))->assertForbidden();
    $this->actingAs($viewer)->postJson($this->api(), ['name' => 'x', 'channel' => 'email'])->assertForbidden();
    $this->actingAs($this->addMember($this->ws, WorkspaceRole::Agent))->getJson($this->api())->assertForbidden();

    // Sin cuenta activa del canal no se aprueba.
    DB::table('channel_accounts')->where('channel', 'whatsapp')->update(['status' => 'paused']);
    $this->actingAs($this->owner)->postJson($this->api("/$id/schedule"))->assertUnprocessable()->assertJsonValidationErrors('channel');
    DB::table('channel_accounts')->update(['status' => 'active']);

    // Sin plantilla tampoco.
    $this->actingAs($this->owner)->patchJson($this->api("/$id"), ['template_id' => null])->assertOk();
    $this->actingAs($this->owner)->postJson($this->api("/$id/schedule"))->assertUnprocessable()->assertJsonValidationErrors('template_id');
    // La plantilla debe ser del canal.
    $this->actingAs($this->owner)->patchJson($this->api("/$id"), ['template_id' => $this->template('email')])->assertUnprocessable();
    $this->actingAs($this->owner)->patchJson($this->api("/$id"), ['template_id' => $this->template('whatsapp')])->assertOk();

    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->postJson($this->api("/$id/schedule"))->assertOk()->assertJsonPath('status', 'scheduled');
    expect(DB::table('audit_logs')->where('action', 'campaign.approved')->exists())->toBeTrue();
    // Aprobada y sin empezar: editarla la devuelve a borrador (necesita otra aprobación).
    $this->actingAs($this->owner)->patchJson($this->api("/$id"), ['name' => 'Otro nombre'])->assertOk()->assertJsonPath('status', 'draft');
});

it('A/B: valida variantes del canal, guarda la configuración y la presenta con IDs públicos', function () {
    $segment = $this->segmentOf('VIP', [$this->contact('Ana')]);
    $campaign = $this->readyCampaign([$segment]);
    [$a, $b] = [$this->template('whatsapp', 'A'), $this->template('whatsapp', 'B')];
    $res = $this->actingAs($this->owner)->patchJson($this->api("/{$campaign->publicId()}"), [
        'variants' => [['key' => 'A', 'template_id' => $a], ['key' => 'B', 'template_id' => $b]],
        'ab_config' => ['sample_pct' => 20, 'metric' => 'revenue', 'wait_hours' => 6],
        'holdout_pct' => 10,
    ])->assertOk();
    expect($res->json('is_ab'))->toBeTrue()
        ->and($res->json('variants.1.template_id'))->toBe($b)
        ->and($res->json('ab_config'))->toBe(['sample_pct' => 20, 'metric' => 'revenue', 'wait_hours' => 6]);
    $this->actingAs($this->owner)->patchJson($this->api("/{$campaign->publicId()}"), ['variants' => [['key' => 'A', 'template_id' => $a], ['key' => 'A', 'template_id' => $b]]])->assertUnprocessable();
});

it('opciones del asistente y calendario comercial con las campañas del año', function () {
    $this->segmentOf('VIP', [$this->contact('Ana')]);
    $this->template('email', 'Boletín');
    $options = $this->actingAs($this->owner)->getJson($this->api('/options'))->assertOk();
    expect(collect($options->json('segments'))->pluck('name'))->toContain('VIP')
        ->and(collect($options->json('templates'))->pluck('name')->all())->toBe(['Boletín'])
        ->and($options->json('active_channels'))->toContain('whatsapp')
        ->and($options->json('wa_rates.effective_from'))->toBe('2025-07-01');

    $calendar = $this->actingAs($this->owner)->getJson($this->api('/calendar?year=2026&country=CO'))->assertOk();
    $events = collect($calendar->json('events'))->keyBy('key');
    expect($events['amor_amistad']['date'])->toBe('2026-09-19')
        ->and($events['dia_madre']['date'])->toBe('2026-05-10')
        ->and($events['black_friday']['date'])->toBe('2026-11-27')
        ->and($events['cyberlunes']['date'])->toBe('2026-11-30')
        ->and($events['prima_junio']['date'])->toBe('2026-06-30')
        ->and($events->has('buen_fin'))->toBeFalse()
        ->and($events['navidad']['ideas'])->not->toBeEmpty();
    $mx = collect($this->actingAs($this->owner)->getJson($this->api('/calendar?year=2026&country=MX'))->json('events'))->keyBy('key');
    expect($mx['dia_madre_mx']['date'])->toBe('2026-05-10')
        ->and($mx['buen_fin']['date'])->toBe('2026-11-13')->and($mx['buen_fin']['ends'])->toBe('2026-11-16')
        ->and($mx['hot_sale']['date'])->toBe('2026-05-25');
});

it('solo se borran los borradores y duplicar crea otro borrador', function () {
    $segment = $this->segmentOf('VIP', [$this->contact('Ana')]);
    $campaign = $this->readyCampaign([$segment], ['send_mode' => 'scheduled', 'send_at' => now()->addDay()->toIso8601String()]);
    $copy = $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/duplicate"))->assertCreated();
    expect($copy->json('status'))->toBe('draft')->and($copy->json('name'))->toStartWith('Copia de');
    $this->actingAs($this->owner)->postJson($this->api("/{$campaign->publicId()}/schedule"))->assertOk();
    $this->actingAs($this->owner)->deleteJson($this->api("/{$campaign->publicId()}"))->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson($this->api("/{$copy->json('id')}"))->assertOk();
});

it('otro espacio de trabajo no ve ni toca las campañas', function () {
    $segment = $this->segmentOf('VIP', [$this->contact('Ana')]);
    $campaign = $this->readyCampaign([$segment]);
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra tienda');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/campaigns")->assertOk()->assertJsonCount(0, 'data');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/campaigns/{$campaign->publicId()}")->assertNotFound();
    $this->actingAs($other)->getJson($this->api())->assertNotFound();
    // La plantilla de otro espacio de trabajo no sirve.
    $foreign = $this->template('whatsapp', 'Ajena', $otherWs);
    $this->actingAs($this->owner)->patchJson($this->api("/{$campaign->publicId()}"), ['template_id' => $foreign])->assertUnprocessable();
});
