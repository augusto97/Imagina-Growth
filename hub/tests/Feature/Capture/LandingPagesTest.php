<?php

use App\Domain\Capture\Models\LandingPage;
use App\Domain\Capture\Models\LandingSubmission;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Privacy\Actions\DeleteContactData;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/CaptureFixtures.php';

/*
 * Páginas de captura alojadas en el hub (rediseño v2 §11.3, modulos.md M12): la API del panel, la página pública en
 * Blade (sin la SPA, noindex, casillas sin marcar con el texto legal, campo trampa, token firmado y límites por IP con
 * hash) y el envío: contacto cifrado, consentimiento con su prueba, lead `landing` con la atribución (UTM `qr`, `ref`,
 * gclid) y los eventos de siempre.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    Queue::fake([QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'America/Bogota'));
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Clínica Sonrisa');
    $this->ws->forceFill(['country' => 'CO', 'timezone' => 'America/Bogota', 'privacy_policy_url' => 'https://sonrisa.co/privacidad'])->save();
    $this->api = "/api/w/{$this->ws->publicId()}";
    $this->messages = FakeMessageDispatcher::install();
});

it('crea la página con valores por defecto, la edita, la publica y la despublica', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Clínica Sonrisa'])->assertCreated();
    expect($res->json())->toMatchArray(['slug' => 'clinica-sonrisa', 'status' => 'draft', 'url' => 'http://localhost/p/clinica-sonrisa',
        'qr_url' => 'http://localhost/p/clinica-sonrisa?utm_source=qr&utm_medium=qr&utm_campaign=clinica-sonrisa'])
        ->and(array_column($res->json('fields'), 'key'))->toBe(['name', 'phone', 'email', 'message'])
        ->and($res->json('consent_channels'))->toBe(['whatsapp', 'email'])
        ->and($res->json('settings.indexable'))->toBeFalse()
        ->and($res->json('preview_url'))->toContain('vista_previa=1')->toContain('signature=');
    $id = $res->json('id');
    // El mismo nombre en otra página toma otra dirección; una dirección propia se valida.
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Clínica Sonrisa'])->assertCreated()->assertJsonPath('slug', 'clinica-sonrisa-2');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Otra', 'slug' => 'reservar'])->assertUnprocessable()->assertJsonValidationErrors('slug');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['slug' => 'Con Espacios'])->assertUnprocessable()->assertJsonValidationErrors('slug');

    // Sin correo ni WhatsApp no se puede responder; una casilla sin su campo se quita; un canal sin texto legal no pasa.
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['fields' => [['key' => 'name', 'label' => 'Nombre']]])
        ->assertUnprocessable()->assertJsonValidationErrors('fields');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['consent_channels' => ['sms']])->assertUnprocessable()->assertJsonValidationErrors('consent_channels');
    $edited = $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", [
        'slug' => 'valoracion-gratis',
        'content' => ['title' => 'Valoración dental gratis', 'body' => "Agenda tu valoración.\n\nTe escribimos hoy.", 'color' => '#0E7490', 'button' => 'Quiero mi valoración'],
        'fields' => [
            ['key' => 'name', 'label' => 'Nombre', 'required' => true],
            ['key' => 'phone', 'label' => 'WhatsApp', 'required' => true],
            ['key' => 'service', 'label' => '¿Qué te interesa?', 'options' => ['Limpieza', 'Ortodoncia']],
            ['key' => 'custom_horario', 'label' => '¿A qué hora te llamamos?', 'type' => 'select', 'options' => ['Mañana', 'Tarde']],
        ],
        'consent_channels' => ['whatsapp', 'email'],
    ])->assertOk();
    expect($edited->json('slug'))->toBe('valoracion-gratis')->and($edited->json('consent_channels'))->toBe(['whatsapp']) // sin campo de correo
        ->and($edited->json('content.title'))->toBe('Valoración dental gratis')->and($edited->json('fields.2.type'))->toBe('select');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$id}", ['content' => ['color' => 'rojo']])->assertUnprocessable();

    // Borrador: no se ve; publicada: se ve; despublicada: deja de verse.
    $this->get('/p/valoracion-gratis')->assertNotFound();
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/publish")->assertOk()->assertJsonPath('status', 'published');
    $this->get('/p/valoracion-gratis')->assertOk()->assertSee('Valoración dental gratis')->assertSee('Quiero mi valoración');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/unpublish")->assertOk()->assertJsonPath('status', 'draft');
    $this->get('/p/valoracion-gratis')->assertNotFound();
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages")->assertOk();
    expect($list->json('data'))->toHaveCount(2)->and(collect($list->json('data'))->sum('stats.views'))->toBe(1)
        ->and($list->json('data.0.stats'))->toHaveKeys(['views', 'submissions', 'qr_views']);
    $audit = DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'like', 'landing_page.%')->pluck('action')->all();
    expect($audit)->toContain('landing_page.created')->toContain('landing_page.published')->toContain('landing_page.unpublished');
});

it('la vista previa firmada muestra el borrador sin contar la visita', function () {
    $res = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Borrador'])->assertCreated();
    $this->get('/p/borrador?vista_previa=1')->assertNotFound();
    $this->get((string) $res->json('preview_url'))->assertOk()->assertSee('Vista previa')->assertSee('disabled', false);
    expect(DB::table('capture_daily')->count())->toBe(0);
});

it('en México no se publica sin el aviso de privacidad', function () {
    $this->ws->forceFill(['country' => 'MX', 'privacy_policy_url' => null])->save();
    $id = $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Consultorio'])->assertCreated()->json('id');
    $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$id}")->assertOk()->assertJsonPath('warnings.0.code', 'no_privacy_url');
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages/{$id}/publish")->assertUnprocessable()->assertJsonValidationErrors('privacy_policy_url');
});

it('la página pública: sin la SPA, noindex, CSP, casillas sin marcar con el texto legal, campo trampa y token', function () {
    $page = capturePublishedPage($this);
    $res = $this->get('/p/'.$page->slug.'?utm_source=qr&utm_medium=qr&utm_campaign=local&gclid=abc&ref=ANA10')->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    $html = (string) $res->getContent();
    expect($res->headers->get('Content-Security-Policy'))->toContain("default-src 'none'")->toContain("script-src 'nonce-")
        ->and($html)->toContain('<meta name="robots" content="noindex, nofollow">')
        ->toContain('<html lang="es-CO">')
        ->not->toContain('/build/assets') // sin la SPA ni Vite
        ->toContain('name="consent[whatsapp]" value="1"')->toContain('name="consent[email]" value="1"')
        ->not->toContain('checked')
        ->toContain('Quiero recibir ofertas y novedades de Clínica Sonrisa por WhatsApp')
        ->toContain('name="website"')->toContain('name="t" value="')
        ->toContain('<input type="hidden" name="utm_source" value="qr">')->toContain('<input type="hidden" name="gclid" value="abc">')
        ->toContain('<input type="hidden" name="via" value="qr">')
        ->toContain('<label class="field" for="f-phone">WhatsApp</label>')
        ->toContain('autocomplete="tel"')->toContain('inputmode="tel"')
        ->toContain('https://sonrisa.co/privacidad')
        ->and(strlen($html))->toBeLessThan(20000);
    // La visita cuenta como del QR, sin guardar la IP.
    $row = DB::table('capture_daily')->where('subject_id', $page->id)->first();
    expect($row->via)->toBe('qr')->and($row->views)->toBe(1)->and($row->visitors)->toBe(1);
    $this->get('/p/'.$page->slug.'?utm_source=qr')->assertOk();
    expect(DB::table('capture_daily')->where('subject_id', $page->id)->value('visitors'))->toBe(1)
        ->and(DB::table('capture_daily')->where('subject_id', $page->id)->value('views'))->toBe(2);

    // Pedir indexarse cambia el noindex.
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$page->publicId()}", ['settings' => ['indexable' => true]])->assertOk();
    $this->get('/p/'.$page->slug)->assertOk()->assertHeader('X-Robots-Tag', 'all')->assertSee('<meta name="robots" content="index, follow">', false);
});

it('el envío crea el contacto cifrado, el consentimiento con su prueba, el lead landing con la atribución y los eventos', function () {
    $page = capturePublishedPage($this, ['fields' => [
        ['key' => 'name', 'label' => 'Nombre', 'required' => true],
        ['key' => 'phone', 'label' => 'WhatsApp', 'required' => true],
        ['key' => 'email', 'label' => 'Correo'],
        ['key' => 'service', 'label' => 'Servicio', 'options' => ['Limpieza', 'Ortodoncia']],
        ['key' => 'message', 'label' => 'Mensaje'],
        ['key' => 'custom_horario', 'label' => 'Horario', 'type' => 'select', 'options' => ['Mañana', 'Tarde']],
    ]]);
    $events = captureEvents();
    $res = captureSubmit($this, $page, ['f' => ['name' => 'Ana María Ruiz', 'phone' => '300 123 4567', 'email' => 'Ana@Ejemplo.com', 'service' => 'Ortodoncia',
        'message' => 'Quiero saber el precio de los brackets', 'custom_horario' => 'Tarde'], 'consent' => ['whatsapp' => '1']],
        '?utm_source=qr&utm_medium=qr&utm_campaign=local&gclid=abc-123&ref=ANA10');
    $res->assertStatus(303)->assertRedirect('http://localhost/p/'.$page->slug.'/gracias');
    $this->get('/p/'.$page->slug.'/gracias')->assertOk()->assertSee('¡Gracias! Recibimos tus datos');

    // Contacto: cifrado en reposo con índice ciego, sin sitio (el negocio no tiene web conectada).
    $contact = captureIn($this->ws, fn () => Contact::query()->sole());
    expect($contact->first_name)->toBe('Ana')->and($contact->last_name)->toBe('María Ruiz')->and($contact->phone)->toBe('+573001234567')
        ->and($contact->email)->toBe('ana@ejemplo.com')->and($contact->email_bidx)->not->toBeNull()
        ->and($contact->first_source)->toMatchArray(['utm_source' => 'qr', 'via' => 'landing']);
    $raw = DB::table('contacts')->where('id', $contact->id)->first();
    expect((string) $raw->email)->not->toContain('ejemplo')->and((string) $raw->phone)->not->toContain('3001234567');
    expect(captureIn($this->ws, fn () => ContactIdentity::query()->where('contact_id', $contact->id)->pluck('site_id')->unique()->all()))->toBe([null]);

    // Consentimiento: solo el canal marcado, con su prueba (texto y versión, formulario, hash de la IP; nunca la IP).
    expect(DB::table('contact_consents')->where('contact_id', $contact->id)->where('status', 'granted')->pluck('channel')->all())->toBe(['whatsapp']);
    $proof = DB::table('consent_events')->where('contact_id', $contact->id)->first();
    $evidence = json_decode((string) $proof->evidence, true);
    expect($proof->source)->toBe('landing:'.$page->publicId())->and($proof->legal_text_version)->toBe('v1')
        ->and($evidence)->toHaveKeys(['page_url', 'ip_hash', 'ua_hash', 'form', 'form_snapshot'])
        ->and($evidence['ip_hash'])->not->toBe('127.0.0.1')->and((string) $proof->evidence)->not->toContain('127.0.0.1')
        ->and($evidence['form_snapshot']['checkboxes'][0])->toMatchArray(['channel' => 'whatsapp', 'version' => 'v1'])
        ->and($evidence['form_snapshot']['checkboxes'][0]['text'])->toContain('por WhatsApp');

    // Lead con origen landing y la atribución del QR impreso (con el gclid para la conversión offline).
    $lead = captureIn($this->ws, fn () => Lead::query()->sole());
    $submission = captureIn($this->ws, fn () => LandingSubmission::query()->sole());
    expect($lead->origin)->toBe('landing')->and($lead->origin_ref)->toBe($submission->publicId())->and($lead->service)->toBe('Ortodoncia')
        ->and($lead->source)->toMatchArray(['utm_source' => 'qr', 'utm_medium' => 'qr', 'utm_campaign' => 'local', 'gclid' => 'abc-123', 'referral_code' => 'ANA10',
            'via' => 'qr', 'page' => '/p/'.$page->slug, 'form_id' => 'landing:'.$page->publicId(), 'channel' => 'paid_google'])
        ->and($submission->lead_id)->toBe($lead->id)->and($submission->via)->toBe('qr')->and($submission->consents)->toBe(['whatsapp'])
        ->and($submission->data)->toMatchArray(['custom_horario' => 'Tarde', 'message' => 'Quiero saber el precio de los brackets']);
    expect((string) DB::table('landing_submissions')->where('id', $submission->id)->value('data'))->not->toContain('brackets'); // cifrado
    Queue::assertPushed(QualifyLeadWithAi::class);

    // Los eventos de siempre: form.submitted del hub (sin lead de origen form) y lead.created con origen landing.
    $form = collect($events)->firstWhere('event', 'form.submitted');
    $created = collect($events)->firstWhere('event', 'lead.created');
    expect($form->envelope['source']['type'])->toBe('hub')->and($form->contactId)->toBe($contact->id)
        ->and($form->envelope['properties'])->toMatchArray(['form_plugin' => 'igr_landing', 'form_id' => $page->publicId(), 'page_path' => '/p/'.$page->slug,
            'consents' => ['whatsapp_marketing' => true, 'email_marketing' => false]])
        ->and($form->envelope['context']['utm'])->toMatchArray(['source' => 'qr'])
        ->and($created->envelope['properties'])->toMatchArray(['origin' => 'landing', 'form_id' => 'landing:'.$page->publicId(), 'channel' => 'paid_google']);
    expect(captureIn($this->ws, fn () => Lead::query()->where('origin', 'form')->count()))->toBe(0);
    // En ClickHouse va sin datos personales, con la UTM del QR.
    $rows = $this->chRows($this->ws, "SELECT event, utm_source, toString(properties) AS props FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'form.submitted'");
    expect($rows)->toHaveCount(1)->and($rows[0]['utm_source'])->toBe('qr')->and($rows[0]['props'])->not->toContain('ejemplo.com')->not->toContain('3001234567');
    expect(DB::table('capture_daily')->where('subject_id', $page->id)->where('via', 'qr')->value('submissions'))->toBe(1);

    // Estadísticas y envíos en el panel.
    $stats = $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$page->publicId()}/stats")->assertOk();
    expect($stats->json('totals'))->toMatchArray(['views' => 1, 'submissions' => 1, 'leads' => 1, 'qr_leads' => 1, 'qr_views' => 1, 'new_contacts' => 1, 'with_consent' => 1]);
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$page->publicId()}/submissions")->assertOk();
    expect($list->json('data.0.contact.phone'))->toBe('+573001234567')->and($list->json('data.0.answers.custom_horario'))->toBe('Tarde');
});

it('el mismo correo o WhatsApp vuelve al mismo contacto y a su lead abierto', function () {
    [$site] = $this->connectedSite($this->ws);
    $page = capturePublishedPage($this);
    captureSubmit($this, $page, ['f' => ['name' => 'Ana', 'phone' => '3001234567']])->assertStatus(303);
    captureSubmit($this, $page, ['f' => ['name' => 'Ana Ruiz', 'phone' => '+57 300 123 4567', 'email' => 'ana@ejemplo.com']])->assertStatus(303);
    expect(captureIn($this->ws, fn () => Contact::query()->count()))->toBe(1)
        ->and(captureIn($this->ws, fn () => Lead::query()->count()))->toBe(1)
        ->and(captureIn($this->ws, fn () => LandingSubmission::query()->count()))->toBe(2)
        ->and(captureIn($this->ws, fn () => LandingSubmission::query()->orderByDesc('created_at')->first()?->was_known))->toBeTrue()
        // Con un sitio conectado, las identidades quedan con su sitio.
        ->and(captureIn($this->ws, fn () => ContactIdentity::query()->pluck('site_id')->unique()->all()))->toBe([$site->id]);
});

it('campo trampa, token repetido o vencido y envíos de robots no crean nada', function () {
    $page = capturePublishedPage($this);
    $form = ['f' => ['name' => 'Ana', 'phone' => '3001234567']];

    // Campo trampa: responde como si nada.
    captureSubmit($this, $page, $form + ['website' => 'http://spam.example'])->assertStatus(303)->assertRedirect('http://localhost/p/'.$page->slug.'/gracias');
    // Sin token (un robot que publica directo) o con uno inventado: vuelve al formulario.
    $this->post('/p/'.$page->slug, $form)->assertStatus(422)->assertSee('La página estuvo abierta mucho tiempo');
    $this->post('/p/'.$page->slug, $form + ['t' => '1764500000.AAAAAAAAAAAAAAAA.'.str_repeat('a', 40)])->assertStatus(422);
    // Demasiado rápido para una persona.
    captureSubmit($this, $page, $form, wait: 0)->assertStatus(303);
    // Vencido (más de 6 horas abierta).
    captureSubmit($this, $page, $form, wait: 7 * 3600)->assertStatus(422);
    expect(captureIn($this->ws, fn () => Contact::query()->count()))->toBe(0);

    // El mismo token dos veces (doble clic): un solo envío.
    $html = $this->get('/p/'.$page->slug)->assertOk();
    $this->travel(5)->seconds();
    $token = captureToken($html);
    $this->post('/p/'.$page->slug, $form + ['t' => $token])->assertStatus(303);
    $this->post('/p/'.$page->slug, $form + ['t' => $token])->assertStatus(303);
    expect(captureIn($this->ws, fn () => LandingSubmission::query()->count()))->toBe(1);
});

it('los errores vuelven al formulario junto a cada campo, con lo que escribió y un token nuevo', function () {
    $page = capturePublishedPage($this);
    $res = captureSubmit($this, $page, ['f' => ['name' => '', 'phone' => '12', 'email' => 'no-es-correo', 'message' => '<b>Hola</b>'], 'consent' => ['email' => '1']])->assertStatus(422);
    $html = (string) $res->getContent();
    expect($html)->toContain('Escribe tu nombre.')->toContain('Escribe un número de WhatsApp válido')->toContain('Escribe un correo válido.')
        ->toContain('aria-invalid="true" aria-describedby="f-phone-error"')->toContain('id="f-phone-error"')
        ->toContain('role="alert"')->toContain('value="no-es-correo"')
        ->toContain('name="consent[email]" value="1" checked')
        ->and(captureToken($res))->not->toBe('');
    // Ni correo ni WhatsApp: lo pide.
    captureSubmit($this, $page, ['f' => ['name' => 'Ana']])->assertStatus(422)->assertSee('Escribe tu número de WhatsApp.');
    expect(captureIn($this->ws, fn () => Contact::query()->count()))->toBe(0);
});

it('limita los envíos por IP (con hash) y las visitas', function () {
    config(['igr-capture.limits.submits_per_minute' => 2, 'igr-capture.limits.views_per_minute' => 6]);
    $page = capturePublishedPage($this);
    $form = ['f' => ['name' => 'Ana', 'phone' => '3001234567']];
    captureSubmit($this, $page, $form)->assertStatus(303);
    captureSubmit($this, $page, $form)->assertStatus(303);
    captureSubmit($this, $page, $form)->assertStatus(429)->assertSee('Recibimos muchos envíos seguidos');
    for ($i = 0; $i < 3; $i++) {
        $this->get('/p/'.$page->slug);
    }
    $this->get('/p/'.$page->slug)->assertStatus(429);
});

it('con redirección propia lleva a esa dirección después de enviar', function () {
    $page = capturePublishedPage($this, ['content' => ['redirect_url' => 'https://sonrisa.co/gracias']]);
    captureSubmit($this, $page, ['f' => ['name' => 'Ana', 'phone' => '3001234567']])->assertStatus(303)->assertRedirect('https://sonrisa.co/gracias');
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/pages/{$page->publicId()}", ['content' => ['redirect_url' => 'http://inseguro.co']])->assertUnprocessable();
});

it('sube el logo y la imagen (vueltos a codificar) y los sirve desde la página', function () {
    Storage::fake('local');
    $page = capturePublishedPage($this);
    $res = $this->actingAs($this->owner)->post("{$this->api}/capture/pages/{$page->publicId()}/images", [
        'kind' => 'logo', 'file' => UploadedFile::fake()->image('logo.png', 900, 300),
    ], ['Accept' => 'application/json'])->assertOk();
    $src = (string) $res->json('content.logo_src');
    expect($src)->toStartWith('http://localhost/p/'.$page->slug.'/i/logo-');
    $img = $this->get(parse_url($src, PHP_URL_PATH))->assertOk()->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $size = getimagesizefromstring((string) $img->getContent());
    expect($size[0])->toBeLessThanOrEqual(480);
    $this->get('/p/'.$page->slug)->assertSee($src, false);
    $this->actingAs($this->owner)->post("{$this->api}/capture/pages/{$page->publicId()}/images", [
        'kind' => 'image', 'file' => UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->get('/p/'.$page->slug.'/i/logo-'.str_repeat('a', 24).'.png')->assertNotFound();
});

it('el QR de la página sale en SVG y en PNG válidos con la UTM qr', function () {
    $page = capturePublishedPage($this);
    $svg = $this->actingAs($this->owner)->get("{$this->api}/capture/pages/{$page->publicId()}/qr?format=svg&download=1")->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')->assertHeader('Content-Disposition', 'attachment; filename="qr-'.$page->slug.'.svg"');
    $xml = simplexml_load_string((string) $svg->getContent());
    expect($xml)->not->toBeFalse()->and($xml->getName())->toBe('svg');
    $png = $this->actingAs($this->owner)->get("{$this->api}/capture/pages/{$page->publicId()}/qr?format=png&size=600")->assertOk()->assertHeader('Content-Type', 'image/png');
    $bytes = (string) $png->getContent();
    expect(substr($bytes, 0, 8))->toBe("\x89PNG\r\n\x1a\n")->and(imagecreatefromstring($bytes))->not->toBeFalse()
        ->and(getimagesizefromstring($bytes)[0])->toBe(600);
});

it('sin el módulo de captación del plan la página no se muestra ni se crean nuevas', function () {
    $page = capturePublishedPage($this);
    captureDenyModules(['onsite']);
    $this->get('/p/'.$page->slug)->assertNotFound()->assertSee('Esta página no está disponible');
    $this->post('/p/'.$page->slug, ['f' => ['phone' => '3001234567']])->assertNotFound();
    $this->actingAs($this->owner)->postJson("{$this->api}/capture/pages", ['name' => 'Otra'])->assertStatus(402)->assertJsonPath('module', 'onsite');
    $this->actingAs($this->owner)->getJson("{$this->api}/capture/pages/{$page->publicId()}")->assertOk(); // lo que existe se sigue viendo
});

it('sin el módulo de leads el envío deja el contacto y el consentimiento, sin lead', function () {
    captureDenyModules(['services']);
    $page = capturePublishedPage($this);
    captureSubmit($this, $page, ['f' => ['name' => 'Ana', 'phone' => '3001234567'], 'consent' => ['whatsapp' => '1']])->assertStatus(303);
    expect(captureIn($this->ws, fn () => Contact::query()->count()))->toBe(1)
        ->and(captureIn($this->ws, fn () => Lead::query()->count()))->toBe(0)
        ->and(DB::table('contact_consents')->where('status', 'granted')->count())->toBe(1);
});

it('una página con envíos se archiva; sin envíos se borra; la supresión borra los envíos', function () {
    $page = capturePublishedPage($this);
    $empty = capturePublishedPage($this, [], 'Vacía');
    captureSubmit($this, $page, ['f' => ['name' => 'Ana', 'phone' => '3001234567']])->assertStatus(303);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/capture/pages/{$page->publicId()}")->assertOk()->assertJsonPath('archived', true);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/capture/pages/{$empty->publicId()}")->assertOk()->assertJsonPath('deleted', true);
    $this->get('/p/'.$page->slug)->assertNotFound();
    expect(LandingPage::withoutWorkspaceScope()->count())->toBe(1);

    $contact = captureIn($this->ws, fn () => Contact::query()->sole());
    captureIn($this->ws, fn () => app(DeleteContactData::class)->handle($this->ws, $contact, $this->owner->id));
    expect(DB::table('landing_submissions')->count())->toBe(0);
});

it('los endpoints de un espacio de trabajo no ven las páginas de otro', function () {
    $page = capturePublishedPage($this);
    $other = $this->userWith2fa();
    $wsB = $this->workspaceFor($other, 'Otro negocio');
    $apiB = "/api/w/{$wsB->publicId()}";
    $id = $page->publicId();
    foreach (['', '/stats', '/submissions', '/qr?format=svg'] as $path) {
        $this->actingAs($other)->getJson("{$apiB}/capture/pages/{$id}{$path}")->assertNotFound();
    }
    $this->actingAs($other)->patchJson("{$apiB}/capture/pages/{$id}", ['name' => 'Mía'])->assertNotFound();
    $this->actingAs($other)->postJson("{$apiB}/capture/pages/{$id}/unpublish")->assertNotFound();
    $this->actingAs($other)->deleteJson("{$apiB}/capture/pages/{$id}")->assertNotFound();
    $this->actingAs($other)->postJson("{$apiB}/capture/pages/{$id}/images", ['kind' => 'logo'])->assertNotFound();
    expect($this->actingAs($other)->getJson("{$apiB}/capture/pages")->assertOk()->json('data'))->toBe([]);
    // Y la API de A no se abre con otra persona.
    $this->actingAs($other)->getJson("{$this->api}/capture/pages")->assertNotFound();
    expect($page->fresh()?->status)->toBe('published');
});

it('un rol sin permisos de marketing ve pero no edita, y los envíos le llegan enmascarados', function () {
    $page = capturePublishedPage($this);
    captureSubmit($this, $page, ['f' => ['name' => 'Ana', 'phone' => '3001234567']])->assertStatus(303);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->api}/capture/pages")->assertOk();
    $this->actingAs($viewer)->patchJson("{$this->api}/capture/pages/{$page->publicId()}", ['name' => 'X'])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->api}/capture/pages", ['name' => 'X'])->assertForbidden();
    $list = $this->actingAs($viewer)->getJson("{$this->api}/capture/pages/{$page->publicId()}/submissions")->assertOk();
    expect($list->json('data.0.contact.phone'))->not->toBe('+573001234567')->and($list->json('data.0.answers'))->toBeNull();
});
