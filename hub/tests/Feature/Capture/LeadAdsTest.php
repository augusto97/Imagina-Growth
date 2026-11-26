<?php

use App\Domain\Capture\Models\LeadAdLead;
use App\Domain\Capture\Models\LeadAdSource;
use App\Domain\Connections\Models\OAuthConnection;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Channels\Drivers\MetaMessengerDriver;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Pii\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Connections\Support\ConnectionFixtures;

require_once __DIR__.'/CaptureFixtures.php';

/*
 * Leads de anuncios (rediseño v2 §11.3, modulos.md M18): Meta Lead Ads con la conexión de Meta (suscripción de la
 * página a `leadgen`, webhook verificado con la firma de la app, el lead leído por la Graph API) y los formularios de
 * clientes potenciales de Google Ads por webhook con la clave del espacio de trabajo. Idempotentes, sin consentimiento
 * de marketing salvo la casilla asignada al canal, y los de prueba marcados. Todo con Http::fake.
 */
uses(ConnectionFixtures::class);

const CAPTURE_META_SCOPES = ['ads_management', 'ads_read', 'business_management', 'pages_show_list', 'pages_manage_metadata', 'pages_messaging',
    'leads_retrieval', 'pages_read_engagement', 'pages_manage_ads'];

beforeEach(function () {
    $this->setUpConnections();
    $this->useIngestInfrastructure();
    config(['igr-messaging.meta.app_secret' => 'meta-app-secret']);
    Queue::fake([QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'America/Bogota'));
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota'])->save();
    FakeMessageDispatcher::install();
});

function captureMetaConnection(object $test, array $scopes = CAPTURE_META_SCOPES): OAuthConnection
{
    return captureIn($test->ws, fn () => OAuthConnection::query()->create([
        'workspace_id' => $test->ws->id, 'provider' => 'meta', 'subject' => '', 'status' => 'active', 'external_id' => '777',
        'account_name' => 'Clínica Sonrisa SAS', 'scopes' => $scopes, 'credentials' => ['access_token' => 'EAAsys-usuario-sistema'],
    ]));
}

function captureMetaPage(object $test): string
{
    Http::fake([
        'graph.test/v27.0/5555555/subscribed_apps' => function (Request $r) {
            return $r->method() === 'GET'
                ? Http::response(['data' => [['id' => '1234', 'name' => 'Imagina Growth', 'subscribed_fields' => ['messages', 'messaging_postbacks']]]])
                : Http::response(['success' => true]);
        },
        'graph.test/v27.0/5555555?*' => Http::response(['id' => '5555555', 'name' => 'Clínica Sonrisa', 'access_token' => 'EAApagina-secreta']),
        'graph.test/v27.0/5555555/leadgen_forms*' => Http::response(['data' => [[
            'id' => '880001', 'name' => 'Valoración gratis', 'status' => 'ACTIVE',
            'questions' => [['key' => 'full_name', 'label' => 'Nombre completo', 'type' => 'FULL_NAME'], ['key' => 'phone_number', 'label' => 'Teléfono', 'type' => 'PHONE'],
                ['key' => 'email', 'label' => 'Correo', 'type' => 'EMAIL'], ['key' => 'que_servicio_te_interesa', 'label' => '¿Qué servicio te interesa?', 'type' => 'CUSTOM']],
            'legal_content' => ['custom_disclaimer' => ['checkboxes' => [
                ['key' => 'optin_whatsapp', 'text' => 'Quiero recibir promociones de Clínica Sonrisa por WhatsApp', 'is_required' => false, 'is_checked_by_default' => false],
                ['key' => 'optin_premarcada', 'text' => 'Acepto todo', 'is_required' => false, 'is_checked_by_default' => true],
            ]]],
        ]]]),
    ]);
    $res = $test->actingAs($test->owner)->postJson("{$test->api}/capture/lead-ads/meta/pages", ['page_id' => '5555555'])->assertCreated();
    expect($res->getContent())->not->toContain('EAApagina');

    return (string) $res->json('id');
}

/** El aviso `leadgen` de la página, firmado con la clave secreta de la app. */
function captureLeadgen(object $test, string $leadgenId, string $secret = 'meta-app-secret', string $page = '5555555')
{
    $body = (string) json_encode(['object' => 'page', 'entry' => [['id' => $page, 'time' => time(), 'changes' => [['field' => 'leadgen', 'value' => [
        'ad_id' => '120200000001', 'form_id' => '880001', 'leadgen_id' => $leadgenId, 'created_time' => time(), 'page_id' => $page, 'adgroup_id' => '120200000002',
    ]]]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
    ], $body);
}

function captureFakeLead(string $leadgenId, array $boxes = [['checkbox_key' => 'optin_whatsapp', 'is_checked' => '1']]): void
{
    Http::fake(["graph.test/v27.0/{$leadgenId}?*" => Http::response([
        'id' => $leadgenId, 'created_time' => '2026-11-30T14:55:00+0000', 'form_id' => '880001', 'platform' => 'ig', 'is_organic' => false,
        'ad_id' => '120200000001', 'ad_name' => 'Video sonrisa', 'adset_id' => '120200000002', 'adset_name' => 'Manizales 25-45',
        'campaign_id' => '120200000003', 'campaign_name' => 'Valoración gratis noviembre',
        'field_data' => [
            ['name' => 'full_name', 'values' => ['Luisa Fernanda Gómez']], ['name' => 'phone_number', 'values' => ['+573104445566']],
            ['name' => 'email', 'values' => ['luisa@example.com']], ['name' => 'que_servicio_te_interesa', 'values' => ['Ortodoncia']],
            ['name' => 'numero_de_cedula', 'values' => ['1053000000']],
        ],
        'custom_disclaimer_responses' => $boxes,
    ])]);
}

it('sin conexión o sin permisos el estado lo dice en palabras y no deja conectar la página', function () {
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('meta.status'))->toBe('not_connected')->and($state->json('meta.message'))->toContain('Conecta Meta en Conexiones')
        ->and($state->json('google.status'))->toBe('not_configured');

    captureMetaConnection($this, ['ads_management', 'pages_show_list', 'pages_messaging']);
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('meta.status'))->toBe('missing_permissions')
        ->and($state->json('meta.message'))->toContain('leer los clientes potenciales de tus formularios')->toContain('vuelva a conectarla en Conexiones')
        ->and($state->json('meta.message'))->not->toContain('leads_retrieval')
        ->and(array_column($state->json('meta.missing_permissions'), 'scope'))->toContain('leads_retrieval');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/meta/pages", ['page_id' => '5555555'])
        ->assertUnprocessable()->assertJsonValidationErrors('page_id');
});

it('suscribe la página a leadgen sin quitarle los campos de Messenger y mapea la casilla de cada canal', function () {
    captureMetaConnection($this);
    $source = captureMetaPage($this);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '5555555/subscribed_apps')
        && $r['subscribed_fields'] === 'messages,messaging_postbacks,leadgen' && $r->hasHeader('Authorization', 'Bearer EAApagina-secreta'));
    $model = LeadAdSource::withoutWorkspaceScope()->sole();
    expect($model->status)->toBe('active')->and($model->secret)->toBe('EAApagina-secreta')
        ->and((string) DB::table('lead_ad_sources')->value('secret'))->not->toContain('EAApagina');
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('meta.status'))->toBe('active')->and($state->json('meta.pages.0'))->toMatchArray(['page_id' => '5555555', 'name' => 'Clínica Sonrisa'])
        ->and($state->getContent())->not->toContain('EAApagina');

    $forms = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads/sources/{$source}/forms")->assertOk();
    expect($forms->json('data.0.checkboxes.1'))->toMatchArray(['key' => 'optin_premarcada', 'checked_by_default' => true]);
    // Una casilla marcada por defecto no sirve como consentimiento; una que no existe, tampoco.
    $this->actingAs($this->owner)->putJson("{$this->api}/capture/lead-ads/sources/{$source}/forms/880001", ['consent' => [['key' => 'optin_premarcada', 'channel' => 'whatsapp']]])
        ->assertUnprocessable()->assertJsonValidationErrors('consent.0.key');
    $this->actingAs($this->owner)->putJson("{$this->api}/capture/lead-ads/sources/{$source}/forms/880001", ['consent' => [['key' => 'inventada', 'channel' => 'email']]])
        ->assertUnprocessable();
    $saved = $this->actingAs($this->owner)->putJson("{$this->api}/capture/lead-ads/sources/{$source}/forms/880001", [
        'consent' => [['key' => 'optin_whatsapp', 'channel' => 'whatsapp']], 'service_key' => 'que_servicio_te_interesa',
    ])->assertOk();
    expect($saved->json('mapping.consent.0'))->toMatchArray(['key' => 'optin_whatsapp', 'channel' => 'whatsapp', 'text' => 'Quiero recibir promociones de Clínica Sonrisa por WhatsApp']);
});

it('el aviso de Meta con la firma de la app crea el contacto y el lead con la campaña; la casilla asignada da el consentimiento con su prueba', function () {
    captureMetaConnection($this);
    $source = captureMetaPage($this);
    $this->actingAs($this->owner)->putJson("{$this->api}/capture/lead-ads/sources/{$source}/forms/880001", [
        'consent' => [['key' => 'optin_whatsapp', 'channel' => 'whatsapp']], 'service_key' => 'que_servicio_te_interesa',
    ])->assertOk();
    captureFakeLead('990001');
    $events = captureEvents();

    // Firma inválida: 403 y nada.
    captureLeadgen($this, '990001', 'otra-clave')->assertForbidden();
    expect(LeadAdLead::withoutWorkspaceScope()->count())->toBe(0);

    captureLeadgen($this, '990001')->assertOk();
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '/990001?') && $r->hasHeader('Authorization', 'Bearer EAApagina-secreta') && str_contains($r->url(), 'campaign_name'));
    $contact = captureIn($this->ws, fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573104445566'))->sole());
    expect($contact->first_name)->toBe('Luisa')->and($contact->last_name)->toBe('Fernanda Gómez')->and($contact->email)->toBe('luisa@example.com')
        ->and($contact->first_source)->toMatchArray(['channel' => 'paid_meta', 'utm_source' => 'instagram', 'via' => 'meta_lead_ads']);

    $consent = DB::table('contact_consents')->where('contact_id', $contact->id)->where('status', 'granted')->get();
    expect($consent->pluck('channel')->all())->toBe(['whatsapp'])->and($consent->first()->source)->toBe('meta_lead_ads');
    $evidence = json_decode((string) DB::table('consent_events')->where('contact_id', $contact->id)->value('evidence'), true);
    expect($evidence)->toMatchArray(['platform' => 'meta', 'form_id' => '880001', 'lead' => '990001', 'question' => 'optin_whatsapp', 'answer' => 'checked',
        'text' => 'Quiero recibir promociones de Clínica Sonrisa por WhatsApp', 'campaign_id' => '120200000003']);

    $lead = captureIn($this->ws, fn () => Lead::query()->sole());
    $row = captureIn($this->ws, fn () => LeadAdLead::query()->sole());
    expect($lead->origin)->toBe('meta_lead_ads')->and($lead->origin_ref)->toBe($row->publicId())->and($lead->service)->toBe('Ortodoncia')
        ->and($lead->source)->toMatchArray(['channel' => 'paid_meta', 'utm_source' => 'instagram', 'utm_medium' => 'paid_social', 'utm_campaign' => 'Valoración gratis noviembre',
            'campaign_id' => '120200000003', 'adset_id' => '120200000002', 'adset_name' => 'Manizales 25-45', 'ad_id' => '120200000001', 'ad_name' => 'Video sonrisa',
            'form_id' => 'meta:880001', 'form_name' => 'Valoración gratis'])
        ->and($row->status)->toBe('processed')->and($row->lead_id)->toBe($lead->id)->and($row->consents)->toBe(['whatsapp'])
        ->and($row->data['answers'])->not->toHaveKey('numero_de_cedula') // minimización
        ->and((string) DB::table('lead_ad_leads')->value('data'))->not->toContain('Luisa');
    $created = collect($events)->firstWhere('event', 'lead.created');
    expect($created->envelope['properties'])->toMatchArray(['origin' => 'meta_lead_ads', 'channel' => 'paid_meta', 'form_id' => 'meta:880001']);

    // Idempotente por leadgen_id: el mismo aviso otra vez no lee ni crea nada.
    $calls = count(Http::recorded());
    captureLeadgen($this, '990001')->assertOk();
    expect(count(Http::recorded()))->toBe($calls)->and(LeadAdLead::withoutWorkspaceScope()->count())->toBe(1)
        ->and(captureIn($this->ws, fn () => Lead::query()->count()))->toBe(1);
});

it('sin casilla asignada (o sin marcar) el lead entra sin consentimiento de marketing', function () {
    captureMetaConnection($this);
    captureMetaPage($this);
    captureFakeLead('990002');
    captureLeadgen($this, '990002')->assertOk();
    expect(captureIn($this->ws, fn () => Lead::query()->sole()->origin))->toBe('meta_lead_ads')
        ->and(DB::table('contact_consents')->count())->toBe(0);
});

it('un error de permisos de Meta deja el lead y la página con el mensaje en palabras, y se reintenta al reconectar', function () {
    captureMetaConnection($this);
    captureMetaPage($this);
    Http::fake(['graph.test/v27.0/990003?*' => Http::response(['error' => ['code' => 200, 'message' => '(#200) Requires leads_retrieval permission', 'type' => 'OAuthException']], 403)]);
    captureLeadgen($this, '990003')->assertOk();
    $row = LeadAdLead::withoutWorkspaceScope()->sole();
    expect($row->status)->toBe('failed')->and($row->error)->toContain('Meta no deja leer los clientes potenciales')->not->toContain('#200');
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('meta.status'))->toBe('error')->and($state->json('meta.message'))->toContain('Vuelve a conectar Meta');
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads/leads?status=failed")->assertOk();
    expect($list->json('data.0.error'))->toContain('Meta no deja leer');

    $this->refake(['graph.test/v27.0/990003?*' => Http::response(['id' => '990003', 'form_id' => '880001', 'field_data' => [['name' => 'email', 'values' => ['ana@example.com']]]])]);
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/leads/{$row->publicId()}/retry")->assertOk()->assertJsonPath('status', 'processed');
    expect(LeadAdSource::withoutWorkspaceScope()->sole()->status)->toBe('active');
});

it('al desconectar la página quita solo leadgen y los avisos siguientes se ignoran; Messenger conserva leadgen al suscribirse', function () {
    captureMetaConnection($this);
    $source = captureMetaPage($this);
    $this->refake(['graph.test/v27.0/5555555/subscribed_apps' => fn (Request $r) => $r->method() === 'GET'
        ? Http::response(['data' => [['id' => '1234', 'subscribed_fields' => ['messages', 'leadgen']]]]) : Http::response(['success' => true])]);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/capture/lead-ads/meta/pages/{$source}")->assertOk()->assertJsonPath('status', 'disabled');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r['subscribed_fields'] === 'messages');
    captureLeadgen($this, '990009')->assertOk();
    expect(LeadAdLead::withoutWorkspaceScope()->count())->toBe(0);

    // Conectar Messenger después no borra la suscripción a leadgen de la página.
    $this->refake(['graph.test/v27.0/5555555/subscribed_apps' => fn (Request $r) => $r->method() === 'GET'
        ? Http::response(['data' => [['id' => '1234', 'subscribed_fields' => ['leadgen']]]]) : Http::response(['success' => true])]);
    expect(app(MetaMessengerDriver::class)->subscribePage('5555555', 'EAApagina'))->toBeNull();
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_starts_with((string) $r['subscribed_fields'], 'leadgen,messages'));
});

it('Google: genera la URL y la clave, valida la clave, crea el lead con el gclid y es idempotente por lead_id', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/google")->assertCreated();
    $url = (string) $res->json('webhook_url');
    $key = (string) $res->json('key');
    expect($url)->toMatch('#^https://growth\.test/webhooks/google/lead-forms/glf_[A-Za-z0-9]{32}$#')->and(strlen($key))->toBe(40)
        ->and((string) DB::table('lead_ad_sources')->value('secret'))->not->toContain($key);
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('google.status'))->toBe('active')->and($state->getContent())->not->toContain($key)->and($state->json('google.key_hint'))->toBe('••••'.substr($key, -4));
    $path = (string) parse_url($url, PHP_URL_PATH);
    $payload = ['lead_id' => 'TeSter-123-ABCDEFGHIJKL', 'api_version' => '1.0', 'form_id' => 40000000001, 'campaign_id' => 20000000001, 'adgroup_id' => 30000000001,
        'creative_id' => 50000000001, 'gcl_id' => 'EAIaIQobChMI-gclid', 'google_key' => $key, 'is_test' => false, 'user_column_data' => [
            ['column_name' => 'Full Name', 'string_value' => 'Pedro Pérez', 'column_id' => 'FULL_NAME'],
            ['column_name' => 'User Email', 'string_value' => 'pedro@example.com', 'column_id' => 'EMAIL'],
            ['column_name' => 'User Phone', 'string_value' => '+573157778899', 'column_id' => 'PHONE_NUMBER'],
            ['column_name' => '¿Qué servicio necesitas?', 'string_value' => 'Implantes', 'column_id' => 'SERVICIO'],
            ['column_name' => 'Número de cédula', 'string_value' => '79000000', 'column_id' => 'CEDULA'],
        ]];

    $this->postJson($path, ['google_key' => 'mala'] + $payload)->assertForbidden();
    $this->postJson('/webhooks/google/lead-forms/glf_'.str_repeat('x', 32), $payload)->assertNotFound();
    expect(LeadAdLead::withoutWorkspaceScope()->count())->toBe(0);

    $this->postJson($path, $payload)->assertOk()->assertJsonPath('duplicate', false);
    $lead = captureIn($this->ws, fn () => Lead::query()->sole());
    expect($lead->origin)->toBe('google_lead_form')
        ->and($lead->source)->toMatchArray(['channel' => 'paid_google', 'utm_source' => 'google', 'utm_medium' => 'cpc', 'campaign_id' => '20000000001',
            'adset_id' => '30000000001', 'ad_id' => '50000000001', 'form_id' => 'google:40000000001', 'gclid' => 'EAIaIQobChMI-gclid']);
    $contact = captureIn($this->ws, fn () => Contact::query()->sole());
    expect($contact->first_name)->toBe('Pedro')->and($contact->phone)->toBe('+573157778899')->and(DB::table('contact_consents')->count())->toBe(0);
    $row = captureIn($this->ws, fn () => LeadAdLead::query()->sole());
    expect($row->data['answers'])->toHaveKey('servicio')->not->toHaveKey('cedula')->and($row->data)->not->toHaveKey('payload');

    // El mismo lead_id otra vez: 200 sin duplicar.
    $this->postJson($path, $payload)->assertOk()->assertJsonPath('duplicate', true);
    expect(LeadAdLead::withoutWorkspaceScope()->count())->toBe(1)->and(captureIn($this->ws, fn () => Lead::query()->count()))->toBe(1);

    // Rotar la clave: la vieja deja de servir.
    $new = (string) $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/google")->assertOk()->json('key');
    $this->postJson($path, ['lead_id' => 'otro-1'] + $payload)->assertForbidden();
    $this->postJson($path, ['lead_id' => 'otro-1', 'google_key' => $new] + $payload)->assertOk();
    // Apagado: 404.
    $this->actingAs($this->owner)->deleteJson("{$this->api}/capture/lead-ads/google")->assertOk()->assertJsonPath('status', 'not_configured');
    $this->postJson($path, ['lead_id' => 'otro-2', 'google_key' => $new] + $payload)->assertNotFound();
});

it('Google: los de prueba se marcan y no crean contacto ni lead ni cuentan; la pregunta asignada da el consentimiento', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/google")->assertCreated();
    $path = (string) parse_url((string) $res->json('webhook_url'), PHP_URL_PATH);
    $key = (string) $res->json('key');
    $columns = [['column_name' => 'User Email', 'string_value' => 'test@example.com', 'column_id' => 'EMAIL'],
        ['column_name' => '¿Quieres recibir novedades por correo?', 'string_value' => 'Sí', 'column_id' => 'NOVEDADES']];
    $this->postJson($path, ['lead_id' => 'TeSter-1', 'form_id' => 41, 'google_key' => $key, 'is_test' => true, 'user_column_data' => $columns])->assertOk();
    $this->postJson($path, ['lead_id' => 'TeSter-1', 'form_id' => 41, 'google_key' => $key, 'is_test' => true, 'user_column_data' => $columns])->assertOk();
    $row = LeadAdLead::withoutWorkspaceScope()->sole();
    expect($row->is_test)->toBeTrue()->and($row->status)->toBe('test')->and(Contact::withoutWorkspaceScope()->count())->toBe(0)
        ->and(LeadAdSource::withoutWorkspaceScope()->sole()->test_received_at)->not->toBeNull();
    $state = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->assertOk();
    expect($state->json('google.source.leads_30d'))->toBe(0)->and($state->json('google.source.test_received_at'))->not->toBeNull();

    // La pregunta del formulario 41 se asigna al correo; un lead real que dice «Sí» queda con consentimiento.
    $source = (string) $state->json('google.source.id');
    $forms = $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads/sources/{$source}/forms")->assertOk();
    expect(array_column($forms->json('data.0.questions'), 'key'))->toContain('novedades');
    $this->actingAs($this->owner)->putJson("{$this->api}/capture/lead-ads/sources/{$source}/forms/41", ['consent' => [['key' => 'NOVEDADES', 'channel' => 'email']]])->assertOk();
    $this->postJson($path, ['lead_id' => 'real-1', 'form_id' => 41, 'google_key' => $key, 'user_column_data' => [
        ['column_name' => 'User Email', 'string_value' => 'maria@example.com', 'column_id' => 'EMAIL'],
        ['column_name' => '¿Quieres recibir novedades por correo?', 'string_value' => 'Sí, por favor', 'column_id' => 'NOVEDADES'],
    ]])->assertOk();
    $consent = DB::table('contact_consents')->where('status', 'granted')->first();
    expect($consent->channel)->toBe('email')->and($consent->source)->toBe('google_lead_form')
        ->and(json_decode((string) DB::table('consent_events')->value('evidence'), true))->toMatchArray(['question' => 'novedades', 'answer' => 'Sí, por favor', 'platform' => 'google']);
});

it('los leads de anuncios de un espacio de trabajo no se ven ni se tocan desde otro', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/lead-ads/google")->assertCreated();
    $path = (string) parse_url((string) $res->json('webhook_url'), PHP_URL_PATH);
    $this->postJson($path, ['lead_id' => 'x-1', 'google_key' => $res->json('key'), 'user_column_data' => [['column_id' => 'EMAIL', 'string_value' => 'a@example.com']]])->assertOk();
    $source = (string) $this->actingAs($this->owner)->getJson("{$this->api}/capture/lead-ads")->json('google.source.id');
    $row = LeadAdLead::withoutWorkspaceScope()->sole();
    $row->forceFill(['status' => 'failed'])->save();

    $other = $this->userWith2fa();
    $wsB = $this->workspaceFor($other, 'Otro negocio');
    $apiB = "/api/w/{$wsB->publicId()}";
    $state = $this->actingAs($other)->getJson("{$apiB}/capture/lead-ads")->assertOk();
    expect($state->json('google.status'))->toBe('not_configured')->and($state->json('google.source'))->toBeNull();
    expect($this->actingAs($other)->getJson("{$apiB}/capture/lead-ads/leads")->json('data'))->toBe([]);
    $this->actingAs($other)->getJson("{$apiB}/capture/lead-ads/sources/{$source}/forms")->assertNotFound();
    $this->actingAs($other)->putJson("{$apiB}/capture/lead-ads/sources/{$source}/forms/1", ['consent' => []])->assertNotFound();
    $this->actingAs($other)->postJson("{$apiB}/capture/lead-ads/leads/{$row->publicId()}/retry")->assertNotFound();
    $this->actingAs($other)->deleteJson("{$apiB}/capture/lead-ads/meta/pages/{$source}")->assertNotFound();
    // Y la clave del otro espacio de trabajo no abre este webhook.
    $keyB = (string) $this->actingAs($other)->postJson("{$apiB}/capture/lead-ads/google")->assertCreated()->json('key');
    $this->postJson($path, ['lead_id' => 'x-2', 'google_key' => $keyB, 'user_column_data' => []])->assertForbidden();
    // Un rol sin permisos de servicios no cambia nada.
    $viewer = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($viewer)->postJson("{$this->api}/capture/lead-ads/google")->assertForbidden();
    $this->actingAs($viewer)->getJson("{$this->api}/capture/lead-ads")->assertOk();
});
