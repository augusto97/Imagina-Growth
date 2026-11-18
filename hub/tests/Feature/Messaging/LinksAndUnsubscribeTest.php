<?php

use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ContactPreference;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Models\TrackedLink;
use App\Domain\Messaging\Support\ContactLinkToken;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Messaging\Support\MessagingFixtures;

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.L1']]])]);
    $this->flowId = strtoupper((string) Str::ulid());
    DB::table('flows')->insert(['id' => $this->flowId, 'workspace_id' => $this->ws->id, 'name' => 'Carrito abandonado', 'status' => 'active', 'trigger' => '{}', 'created_at' => now(), 'updated_at' => now()]);
});

function dispatchTo($test, Channel $channel = Channel::WhatsApp, ?string $template = 'tpl_wa', ?string $flow = null)
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => app(MessageDispatcher::class)->dispatch(new OutboundMessage(
        $test->ws->id, $test->contact->id, $channel, MessageKind::Marketing, 'flow', templateId: $template, flowId: $flow,
    )));
}

function chEvents($test, string $event): array
{
    return $test->chRows($test->ws, 'SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = {e:String}', ['e' => $event]);
}

it('el clic redirige con utm_* y _igr, cuenta una vez y no cuenta las vistas previas de WhatsApp', function () {
    $result = dispatchTo($this, flow: $this->flowId);
    $link = TrackedLink::withoutWorkspaceScope()->where('message_id', $result->messageId)->where('url', 'https://tienda.co/carrito')->firstOrFail();

    $this->get('/c/'.$link->code, ['User-Agent' => 'WhatsApp/2.23.20.0 A'])->assertRedirect();
    expect(TrackedLink::withoutWorkspaceScope()->find($link->code)->clicks)->toBe(0);

    $response = $this->get('/c/'.$link->code, ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)']);
    $response->assertStatus(302)->assertHeader('Cache-Control', 'no-store, private');
    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($response->headers->get('Location'))->toStartWith('https://tienda.co/carrito?')
        ->and($query)->toMatchArray(['utm_source' => 'igr', 'utm_medium' => 'whatsapp', 'utm_campaign' => 'carrito-abandonado', '_igr' => $link->code]);
    $this->get('/c/'.$link->code, ['User-Agent' => 'Mozilla/5.0 (Linux; Android 14)'])->assertStatus(302);

    expect(TrackedLink::withoutWorkspaceScope()->find($link->code)->clicks)->toBe(2)
        ->and(Message::withoutWorkspaceScope()->find($result->messageId)->clicked_at)->not->toBeNull();
    $clicked = chEvents($this, 'message.clicked');
    expect($clicked)->toHaveCount(1)->and($clicked[0]['p'])->toContain('"link_code":"'.$link->code.'"');
    $this->get('/c/NoExiste99')->assertNotFound()->assertSee('Este enlace ya no funciona');
});

it('baja de un clic (RFC 8058): sin cookies ni CSRF, aplicada en todo el sistema al instante', function () {
    $result = dispatchTo($this, Channel::Email, 'tpl_email_baja');
    $message = Message::withoutWorkspaceScope()->find($result->messageId);
    $token = ContactLinkToken::make($this->ws->id, $this->contact->id, 'email', $message->publicId());

    $this->get('/u/'.$token)->assertOk()->assertSee('Darme de baja')->assertSee('Pinturas La 14');
    $this->call('POST', '/u/'.$token, [], [], [], ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'], 'List-Unsubscribe=One-Click')->assertOk()->assertContent('');

    app(CurrentWorkspace::class)->run($this->ws, fn () => expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'email')->value('status'))->toBe('revoked'));
    // El siguiente correo de marketing ya no sale; WhatsApp sí.
    $this->travelTo(CarbonImmutable::parse('2026-11-18 10:00:00', 'America/Bogota'));
    expect(dispatchTo($this, Channel::Email, 'tpl_email')->reason)->toBe('no_consent')
        ->and(dispatchTo($this)->status)->toBe('queued');
    expect(chEvents($this, 'message.unsubscribed')[0]['p'])->toContain('"via":"one_click"')->toContain('"scope":"channel"');

    $this->get('/u/'.$token.'x')->assertNotFound();
    $this->get('/u/otro.'.str_repeat('a', 32))->assertNotFound();
});

it('el centro de preferencias cambia canales, temas y frecuencia, o da de baja de todo', function () {
    $token = ContactLinkToken::make($this->ws->id, $this->contact->id, 'email');
    $this->get('/preferencias/'.$token)->assertOk()->assertSee('Elige qué quieres recibir')->assertSee('WhatsApp');

    $this->post('/preferencias/'.$token, ['channels' => ['email'], 'topics' => ['ofertas'], 'frequency' => 'weekly'])->assertOk()->assertSee('Guardamos tus preferencias');
    app(CurrentWorkspace::class)->run($this->ws, function () {
        $statuses = ContactConsent::query()->where('contact_id', $this->contact->id)->pluck('status', 'channel')->all();
        expect($statuses)->toMatchArray(['email' => 'granted', 'whatsapp' => 'revoked', 'sms' => 'revoked'])
            ->and(ContactPreference::query()->find($this->contact->id)?->frequency)->toBe('weekly')
            ->and(ContactPreference::query()->find($this->contact->id)?->topics)->toBe(['ofertas']);
    });
    // «Semanal»: un correo esta semana; el segundo, a la siguiente.
    expect(dispatchTo($this, Channel::Email, 'tpl_email')->status)->toBe('queued');
    $this->travelTo(CarbonImmutable::parse('2026-11-19 10:00:00', 'America/Bogota'));
    $r = dispatchTo($this, Channel::Email, 'tpl_email');
    expect($r->reason)->toBe('frequency_preference');

    // Volver a suscribirse a WhatsApp desde las preferencias es un consentimiento nuevo.
    $this->post('/preferencias/'.$token, ['channels' => ['email', 'whatsapp'], 'frequency' => 'normal'])->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, fn () => expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('channel', 'whatsapp')->first()?->only(['status', 'source']))->toBe(['status' => 'granted', 'source' => 'preference_center']));

    $this->post('/preferencias/'.$token, ['all' => '1'])->assertOk();
    app(CurrentWorkspace::class)->run($this->ws, fn () => expect(ContactConsent::query()->where('contact_id', $this->contact->id)->where('status', 'granted')->count())->toBe(0));
});
