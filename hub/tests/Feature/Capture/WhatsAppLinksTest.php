<?php

use App\Domain\Capture\Models\WhatsAppLink;
use App\Domain\Capture\Models\WhatsAppLinkHit;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Jobs\ExtractLeadBrief;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Models\Lead;
use App\Support\Pii\BlindIndex;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

require_once __DIR__.'/CaptureFixtures.php';

/*
 * Chats de WhatsApp con atribución (rediseño v2 §11.3): el QR para imprimir abre wa.me con el número conectado y un
 * mensaje de alta con el código; cuando el mensaje llega por el webhook de Meta, el chat queda atribuido al QR (UTM
 * `qr`), crea el lead y pregunta con botones si quiere recibir novedades; el «Sí» registra el consentimiento con su
 * prueba (cumplimiento.md §3.1).
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging(); // WhatsApp oficial +57 300 0000000 y Ana (+57 300 123 4567) con consentimiento
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00', 'America/Bogota'));
    Queue::fake([ExtractLeadBrief::class, QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    $this->api = "/api/w/{$this->ws->publicId()}";
    $this->sent = FakeMessageDispatcher::install();
});

/** Un mensaje que llega por el webhook de la Cloud API. */
function captureWaWebhook(object $test, array $message, string $from): void
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
        'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Luisa']]],
        'messages' => [['from' => $from, 'id' => 'wamid.IN'.Str::random(10), 'timestamp' => (string) now()->getTimestamp()] + $message],
    ]]]]]]);
    $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, 'meta-app-secret'),
    ], (string) $body)->assertOk();
}

function captureWaSays(object $test, string $text, string $from = '573009998877'): void
{
    captureWaWebhook($test, ['type' => 'text', 'text' => ['body' => $text]], $from);
}

function captureWaTaps(object $test, string $id, string $title, string $from = '573009998877'): void
{
    captureWaWebhook($test, ['type' => 'interactive', 'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $id, 'title' => $title]]], $from);
}

function captureNewQr(object $test, array $data = []): array
{
    return $test->actingAs($test->owner)->postJson("{$test->api}/capture/whatsapp-links", $data + ['name' => 'Mostrador', 'message' => 'Hola, quiero recibir novedades'])
        ->assertCreated()->json();
}

it('el QR abre wa.me con el número conectado y el mensaje de alta con el código, y cuenta el escaneo', function () {
    $qr = captureNewQr($this);
    expect($qr['code'])->toMatch('/^Q[A-Z0-9]{6}$/')->and($qr['kind'])->toBe('qr')->and($qr['number'])->toBe('+573000000000')
        ->and($qr['url'])->toBe('http://localhost/p/w/'.$qr['code'])->and($qr['warnings'])->toBe([])
        ->and($qr['wa_me_url'])->toBe('https://wa.me/573000000000?text='.rawurlencode('Hola, quiero recibir novedades (ref: '.$qr['code'].')'));

    $this->get('/p/w/'.$qr['code'])->assertStatus(302)->assertRedirect($qr['wa_me_url'])->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/p/w/'.strtolower($qr['code']))->assertStatus(302);
    $this->get('/p/w/QZZZZZZ')->assertNotFound();
    $row = DB::table('capture_daily')->where('subject_type', 'wa_link')->first();
    expect($row->via)->toBe('qr')->and($row->views)->toBe(2)->and($row->visitors)->toBe(1);

    $svg = $this->actingAs($this->owner)->get("{$this->api}/capture/whatsapp-links/{$qr['id']}/qr?format=svg")->assertOk()->assertHeader('Content-Type', 'image/svg+xml');
    expect(simplexml_load_string((string) $svg->getContent()))->not->toBeFalse();
    $png = $this->actingAs($this->owner)->get("{$this->api}/capture/whatsapp-links/{$qr['id']}/qr?format=png&download=1")->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="whatsapp-'.strtolower($qr['code']).'.png"');
    expect(imagecreatefromstring((string) $png->getContent()))->not->toBeFalse();
});

it('el chat que llega con el código queda atribuido al QR, crea el lead y pregunta con botones; el «Sí» registra el consentimiento con su prueba', function () {
    $qr = captureNewQr($this, ['settings' => ['service' => 'Limpieza dental']]);
    captureWaSays($this, 'Hola, quiero recibir novedades (ref: '.$qr['code'].')');

    $contact = captureIn($this->ws, fn () => Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573009998877'))->sole());
    $hit = captureIn($this->ws, fn () => WhatsAppLinkHit::query()->sole());
    expect($hit->contact_id)->toBe($contact->id)->and($hit->was_new)->toBeTrue()->and($hit->consent_asked_at)->not->toBeNull()
        ->and($contact->first_source)->toMatchArray(['channel' => 'whatsapp', 'utm_source' => 'qr', 'utm_medium' => 'qr', 'via' => 'whatsapp_link']);
    $lead = captureIn($this->ws, fn () => Lead::query()->where('contact_id', $contact->id)->sole());
    expect($lead->origin)->toBe('whatsapp')->and($lead->service)->toBe('Limpieza dental')->and($lead->id)->toBe($hit->lead_id)
        ->and($lead->source)->toMatchArray(['utm_source' => 'qr', 'utm_medium' => 'qr', 'wa_link' => $qr['id'], 'via' => 'qr']);
    // El mensaje prellenado no es consentimiento.
    expect(DB::table('contact_consents')->where('contact_id', $contact->id)->count())->toBe(0);

    // La pregunta: mensaje de servicio con dos botones y el texto legal vigente.
    $ask = collect($this->sent->dispatched)->last();
    expect($ask->kind)->toBe(MessageKind::Service)->and($ask->contactId)->toBe($contact->id)
        ->and($ask->context['interactive']['type'])->toBe('button')
        ->and($ask->context['interactive']['body']['text'])->toContain('Quiero recibir ofertas y novedades de')->toContain('por WhatsApp');
    $buttons = $ask->context['interactive']['action']['buttons'];
    expect($buttons)->toHaveCount(2)->and($buttons[0]['reply']['id'])->toBe('igr_optin:y:'.$hit->id)->and($buttons[1]['reply']['title'])->toBe('No, gracias');

    // El toque «Sí, quiero»: consentimiento de marketing por WhatsApp con su prueba y la confirmación.
    captureWaTaps($this, 'igr_optin:y:'.$hit->id, 'Sí, quiero');
    $consent = DB::table('contact_consents')->where('contact_id', $contact->id)->where('channel', 'whatsapp')->first();
    expect($consent->status)->toBe('granted')->and($consent->source)->toBe('whatsapp_button')->and($consent->legal_text_version)->toBe('v1');
    $evidence = json_decode((string) DB::table('consent_events')->where('contact_id', $contact->id)->value('evidence'), true);
    expect($evidence)->toMatchArray(['button_payload' => 'igr_optin:y:'.$hit->id, 'prompt' => 'whatsapp_link', 'wa_link' => $qr['id']])
        ->and($evidence['message_id'])->toStartWith('msg_')->and($evidence['legal_text'])->toContain('por WhatsApp');
    expect(collect($this->sent->dispatched)->last()->context['text'])->toContain('responde «baja»')
        ->and(captureIn($this->ws, fn () => WhatsAppLinkHit::query()->sole()->consent_granted_at))->not->toBeNull();

    $stats = $this->actingAs($this->owner)->getJson("{$this->api}/capture/whatsapp-links/{$qr['id']}/stats")->assertOk();
    expect($stats->json('totals'))->toMatchArray(['chats' => 1, 'new_contacts' => 1, 'leads' => 1, 'consents' => 1]);
    $list = $this->actingAs($this->owner)->getJson("{$this->api}/capture/whatsapp-links")->assertOk();
    expect($list->json('data.0.stats'))->toMatchArray(['chats' => 1, 'leads' => 1]);
});

it('«No, gracias» no da consentimiento, y el toque de otra persona no vale', function () {
    $qr = captureNewQr($this);
    captureWaSays($this, 'Hola (ref: '.$qr['code'].')');
    $hit = captureIn($this->ws, fn () => WhatsAppLinkHit::query()->sole());
    captureWaTaps($this, 'igr_optin:y:'.$hit->id, 'Sí, quiero', '573005550000'); // otra persona
    captureWaTaps($this, 'igr_optin:n:'.$hit->id, 'No, gracias');
    expect(DB::table('contact_consents')->where('channel', 'whatsapp')->where('source', 'whatsapp_button')->count())->toBe(0)
        ->and(collect($this->sent->dispatched)->last()->context['text'])->toContain('Solo te escribiremos para responder');
});

it('a quien ya tiene consentimiento no le pregunta; sin pedir el lead no lo crea; un QR archivado deja de funcionar', function () {
    $qr = captureNewQr($this, ['settings' => ['create_lead' => false]]);
    $before = count($this->sent->dispatched);
    captureWaSays($this, 'Hola (ref: '.$qr['code'].')', '573001234567'); // Ana, que ya aceptó
    expect(count($this->sent->dispatched))->toBe($before)
        ->and(captureIn($this->ws, fn () => WhatsAppLinkHit::query()->sole()->was_new))->toBeFalse()
        ->and(captureIn($this->ws, fn () => Lead::query()->count()))->toBe(0);

    $this->actingAs($this->owner)->deleteJson("{$this->api}/capture/whatsapp-links/{$qr['id']}")->assertOk()->assertJsonPath('archived', true);
    $this->get('/p/w/'.$qr['code'])->assertNotFound();
    captureWaSays($this, 'Otra vez (ref: '.$qr['code'].')', '573007776655');
    expect(captureIn($this->ws, fn () => WhatsAppLinkHit::query()->count()))->toBe(1);
});

it('el botón de WhatsApp de una página es un chat con atribución a la página', function () {
    $this->ws->forceFill(['privacy_policy_url' => 'https://pinturas.co/privacidad'])->save();
    $page = capturePublishedPage($this, ['content' => ['whatsapp' => ['enabled' => true, 'message' => 'Hola, vengo de la página']]], 'Pinturas Express');
    $link = captureIn($this->ws, fn () => WhatsAppLink::query()->whereKey($page->wa_link_id)->sole());
    expect($link->kind)->toBe('page')->and($link->code)->toMatch('/^P[A-Z0-9]{6}$/')->and($link->message)->toBe('Hola, vengo de la página');
    $this->get('/p/'.$page->slug)->assertOk()->assertSee('/p/w/'.$link->code, false)->assertSee('Escríbenos por WhatsApp');

    captureWaSays($this, 'Hola, vengo de la página (ref: '.$link->code.')');
    $lead = captureIn($this->ws, fn () => Lead::query()->where('origin', 'whatsapp')->sole());
    expect($lead->source)->toMatchArray(['utm_source' => 'landing', 'utm_medium' => 'whatsapp', 'utm_campaign' => $page->slug, 'page' => '/p/'.$page->slug]);
    // Las páginas no se editan desde la lista de QR.
    $this->actingAs($this->owner)->patchJson("{$this->api}/capture/whatsapp-links/{$link->publicId()}", ['name' => 'X'])->assertStatus(409);
});

it('sin número de WhatsApp el QR lo advierte y el enlace no abre', function () {
    captureIn($this->ws, fn () => DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->update(['status' => 'disconnected']));
    $qr = captureNewQr($this);
    expect($qr['number'])->toBeNull()->and($qr['warnings'][0]['code'])->toBe('no_whatsapp_number');
    $this->get('/p/w/'.$qr['code'])->assertNotFound();
    // Con el WhatsApp del perfil del negocio abre, pero avisa que no llega a la bandeja.
    $this->ws->forceFill(['settings' => array_merge((array) $this->ws->settings, ['business_profile' => ['whatsapp' => '310 555 1234']])])->save();
    $again = $this->actingAs($this->owner)->getJson("{$this->api}/capture/whatsapp-links/{$qr['id']}")->assertOk();
    expect($again->json('number'))->toBe('+573105551234')->and($again->json('warnings.0.code'))->toBe('whatsapp_not_connected');
    $this->get('/p/w/'.$qr['code'])->assertStatus(302);
});

it('los chats de un espacio de trabajo no se ven ni se editan desde otro', function () {
    $qr = captureNewQr($this);
    $other = $this->userWith2fa();
    $wsB = $this->workspaceFor($other, 'Otro');
    $apiB = "/api/w/{$wsB->publicId()}";
    foreach (['', '/stats', '/qr?format=png'] as $path) {
        $this->actingAs($other)->getJson("{$apiB}/capture/whatsapp-links/{$qr['id']}{$path}")->assertNotFound();
    }
    $this->actingAs($other)->patchJson("{$apiB}/capture/whatsapp-links/{$qr['id']}", ['name' => 'Mío'])->assertNotFound();
    $this->actingAs($other)->deleteJson("{$apiB}/capture/whatsapp-links/{$qr['id']}")->assertNotFound();
    expect($this->actingAs($other)->getJson("{$apiB}/capture/whatsapp-links")->json('data'))->toBe([]);
    // Un número de otro espacio de trabajo tampoco se puede elegir.
    $this->actingAs($other)->postJson("{$apiB}/capture/whatsapp-links", ['name' => 'Mostrador', 'message' => 'Hola', 'channel_account_id' => $this->wa->publicId()])
        ->assertUnprocessable()->assertJsonValidationErrors('channel_account_id');
});
