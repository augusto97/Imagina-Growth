<?php

require_once __DIR__.'/LeadsHelpers.php';

use App\Analytics\Ch;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Listeners\LeadSignalsListener;
use App\Domain\Services\Leads\Support\LeadDefaults;
use App\Domain\Services\Leads\Support\LeadQualifier;
use App\Domain\Services\Leads\Support\LeadScorer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    leadsSetUp($this);
    $this->ai = FakeAiGateway::install();
    $this->messages = FakeMessageDispatcher::install();
});

it('suma las señales de la navegación previa (precios, visita recurrente, WhatsApp) y del perfil', function () {
    $this->ai->enabled = false;
    leadsSettings($this, ['scoring' => ['service_cities' => ['bogota', 'Chía']]]);
    $contact = leadsContact($this);
    leadsChVisit($this, $contact, 'page.viewed', '/precios/', now()->subDays(3)->toIso8601String());
    leadsChVisit($this, $contact, 'page.viewed', '/precios/planes', now()->subDays(3)->addHour()->toIso8601String());
    leadsChVisit($this, $contact, 'page.viewed', '/servicios/laboral', now()->subDay()->toIso8601String());
    leadsChVisit($this, $contact, 'cta.clicked', '/servicios/laboral', now()->subDay()->toIso8601String(), ['kind' => 'whatsapp', 'target' => 'https://wa.me/57300']);
    leadsChVisit($this, $contact, 'page.viewed', '/precios/', now()->subDays(45)->toIso8601String()); // fuera de los 30 días

    leadsForm($this, $contact);
    $lead = leadsOne($this);
    $signals = collect($lead->score_reasons)->keyBy('signal');
    expect($signals->keys()->sort()->values()->all())->toBe(['corporate_email', 'form', 'pricing_page', 'recurring_visit', 'service_area', 'whatsapp_click'])
        ->and($signals['pricing_page']['detail']['views'])->toBe(3)
        ->and($lead->score)->toBe(30 + 10 + 5 + 15 + 10 + 5)
        ->and($lead->temperature)->toBe('hot');
});

it('las señales en vivo suman una vez y llevan la cuenta de vistas de precios', function () {
    $this->ai->enabled = false;
    $contact = leadsContact($this, ['email' => 'ana@gmail.com']);
    leadsForm($this, $contact);
    expect(leadsOne($this)->score)->toBe(30); // correo personal: no suma

    leadsBrowse($this, $contact, 'page.viewed', '/precios/');
    leadsBrowse($this, $contact, 'page.viewed', '/precios/');
    leadsBrowse($this, $contact, 'cta.clicked', '/', ['kind' => 'phone', 'target' => 'tel:+57300']);
    leadsBrowse($this, $contact, 'cta.clicked', '/', ['kind' => 'download', 'target' => 'x.pdf']);
    $lead = leadsOne($this);
    expect($lead->score)->toBe(30 + 10 + 15)
        ->and(collect($lead->score_reasons)->firstWhere('signal', 'pricing_page')['detail']['views'])->toBe(2);

    // Otro día con visita: visita recurrente.
    $this->travel(2)->days();
    leadsBrowse($this, $contact, 'page.viewed', '/blog/');
    expect(leadsOne($this)->score)->toBe(60)->and(leadsOne($this)->temperature)->toBe('hot');
});

it('respeta reglas y umbrales del espacio de trabajo', function () {
    $this->ai->enabled = false;
    leadsSettings($this, ['scoring' => ['points' => ['form' => 50, 'corporate_email' => 0], 'thresholds' => ['warm' => 20, 'hot' => 45], 'pricing_paths' => ['/tarifas']]]);
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    $lead = leadsOne($this);
    expect($lead->score)->toBe(50)->and($lead->temperature)->toBe('hot');
    leadsBrowse($this, $contact, 'page.viewed', '/precios/'); // ya no es página de precios
    expect(collect(leadsOne($this)->score_reasons)->pluck('signal')->all())->toBe(['form']);
});

it('decae 5 puntos por semana sin actividad, sin restar dos veces la misma noche', function () {
    $this->ai->enabled = false;
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    expect(leadsOne($this)->score)->toBe(40);

    $this->travel(15)->days();
    $this->artisan('igr:leads:decay')->assertSuccessful();
    $this->artisan('igr:leads:decay')->assertSuccessful();
    $lead = leadsOne($this);
    expect($lead->score)->toBe(30)
        ->and(collect($lead->score_reasons)->firstWhere('signal', 'decay'))->toMatchArray(['points' => -10, 'detail' => ['weeks' => 2]])
        ->and($lead->temperature)->toBe('warm');

    // Una señal nueva reinicia el decaimiento.
    leadsBrowse($this, $contact, 'page.viewed', '/precios/');
    $this->artisan('igr:leads:decay')->assertSuccessful();
    expect(leadsOne($this)->score)->toBe(50);
});

it('la IA califica el mensaje (±20) con razón visible y sin guardar el mensaje', function () {
    $this->ai->replyJson(['puntos' => 18, 'intencion' => 'alta', 'urgencia' => true, 'presupuesto_mencionado' => false, 'razon' => 'Pide asesoría urgente por un despido', 'servicio' => 'Despido']);
    $contact = leadsContact($this);
    leadsForm($this, $contact, ['service' => '', 'message' => 'Me despidieron ayer, escríbanme a ana@ruiz.co o al 3001234567']);

    $lead = leadsOne($this);
    $ai = collect($lead->score_reasons)->firstWhere('signal', 'ai');
    expect($ai)->toMatchArray(['points' => 18, 'reason' => 'Pide asesoría urgente por un despido'])
        ->and($lead->score)->toBe(58)
        ->and($lead->service)->toBe('Despido');
    $request = $this->ai->requests[0]['request'];
    expect($request->feature)->toBe(LeadQualifier::FEATURE)
        ->and(json_encode($request->messages))->not->toContain('3001234567')->not->toContain('ana@ruiz.co')
        ->and(json_encode([$lead->toArray(), leadsActivities($this, $lead)->toArray()]))->not->toContain('despidieron');
});

it('limita la IA a ±20 y sin IA (o si falla) queda el puntaje de las reglas', function () {
    $this->ai->replyJson(['puntos' => -80, 'intencion' => 'ninguna', 'urgencia' => false, 'presupuesto_mencionado' => false, 'razon' => 'Es un proveedor ofreciendo servicios', 'servicio' => '']);
    leadsForm($this, leadsContact($this));
    expect(collect(leadsOne($this)->score_reasons)->firstWhere('signal', 'ai')['points'])->toBe(-20)->and(leadsOne($this)->score)->toBe(20);

    $this->ai->failWith('provider');
    leadsForm($this, leadsContact($this, ['email' => 'luis@constructora.co']));
    $lead = leadsOne($this);
    expect(collect($lead->score_reasons)->pluck('signal')->all())->not->toContain('ai')->and($lead->score)->toBe(40);

    leadsSettings($this, ['ai_qualify' => false]);
    $before = count($this->ai->requests);
    leadsForm($this, leadsContact($this, ['email' => 'eva@empresa.co']));
    expect(count($this->ai->requests))->toBe($before);
});

it('cuenta la navegación anónima previa aunque identity_map de ClickHouse todavía no tenga el enlace', function () {
    $contact = leadsContact($this);
    $aid = (string) Str::uuid();
    // La visita a /servicios/ llegó anónima; el formulario enlazó el visitante en MariaDB (contact_identities),
    // y ClickHouse recibe identity_map en el siguiente lote de la ingesta.
    app(Ch::class)->insert('events', [[
        'workspace_id' => $this->ws->ch_id, 'site_id' => $this->site->ch_id, 'event_id' => (string) Str::uuid7(), 'event' => 'page.viewed',
        'occurred_at' => now()->subMinutes(5)->utc()->format('Y-m-d H:i:s.v'), 'received_at' => now()->utc()->format('Y-m-d H:i:s.v'),
        'source_type' => 'browser', 'anonymous_id' => $aid, 'session_id' => 's-1', 'contact_id' => '', 'path' => '/servicios/',
        'channel' => 'paid_google', 'revenue' => '0', 'properties' => new stdClass,
    ]]);
    DB::table('contact_identities')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact->id,
        'type' => 'anonymous_id', 'value' => $aid, 'first_seen_at' => now(), 'last_seen_at' => now()]);

    expect(app(LeadScorer::class)->browsing($this->ws, $contact->id, LeadDefaults::SCORING['pricing_paths'])['pricing_views'])->toBe(1);
});

it('una segunda pasada suma la visita a precios que llegó a ClickHouse después de crear el lead', function () {
    $this->ai->enabled = false;
    $contact = leadsContact($this);
    leadsForm($this, $contact);
    $lead = leadsOne($this);
    expect(collect($lead->score_reasons)->pluck('signal')->all())->not->toContain('pricing_page');

    leadsChVisit($this, $contact, 'page.viewed', '/precios/', now()->subMinute()->toIso8601String());
    leadsRun($this, fn () => app()->call([new RescoreLeadHistory($this->ws->id, $lead->id), 'handle']));
    leadsRun($this, fn () => app()->call([new RescoreLeadHistory($this->ws->id, $lead->id), 'handle'])); // repetirla no infla el puntaje

    $after = leadsOne($this);
    expect(collect($after->score_reasons)->where('signal', 'pricing_page'))->toHaveCount(1)
        ->and($after->score)->toBe($lead->score + LeadDefaults::SCORING['points']['pricing_page']);
});

it('distingue correos corporativos y ciudades del área de servicio', function () {
    expect(LeadScorer::isCorporate('ana@ruizabogados.co'))->toBeTrue()
        ->and(LeadScorer::isCorporate('ana@gmail.com'))->toBeFalse()
        ->and(LeadScorer::isCorporate('ana@Hotmail.com'))->toBeFalse()
        ->and(LeadScorer::isCorporate('ana@miempresa.com', ['miempresa.com']))->toBeFalse()
        ->and(LeadScorer::inServiceArea('Bogotá D.C', ['bogota d.c']))->toBeTrue()
        ->and(LeadScorer::inServiceArea('Medellín', ['Bogotá']))->toBeFalse()
        ->and(LeadSignalsListener::amount('$2.500.000'))->toBe('2500000.00')
        ->and(LeadSignalsListener::amount('1,250,000.50'))->toBe('1250000.50')
        ->and(LeadSignalsListener::amount('no sé'))->toBeNull()
        // Rangos y palabras de los <select> de presupuesto: «2 a 8 millones» no es $28.
        ->and(LeadSignalsListener::amount('2 a 8 millones'))->toBe('5000000.00')
        ->and(LeadSignalsListener::amount('Menos de 2 millones'))->toBe('2000000.00')
        ->and(LeadSignalsListener::amount('Más de 8 millones'))->toBe('8000000.00')
        ->and(LeadSignalsListener::amount('1,5 millones'))->toBe('1500000.00')
        ->and(LeadSignalsListener::amount('entre 500 mil y 1 millón'))->toBe('750000.00')
        ->and(LeadSignalsListener::amount('800 mil'))->toBe('800000.00')
        ->and(LeadSignalsListener::amount('$1.200.000 - $2.000.000'))->toBe('1600000.00')
        ->and(LeadSignalsListener::amount('3M'))->toBe('3000000.00')
        ->and(LeadDefaults::SCORING['thresholds'])->toBe(['warm' => 30, 'hot' => 60]);
});
