<?php

use App\Domain\Commerce\Actions\DetectAbandonedCarts;
use App\Domain\Commerce\Models\Cart;
use App\Domain\Consent\Policy\PolicyDecision;
use App\Domain\Consent\Policy\PolicyRequest;
use App\Domain\Consent\Policy\SendPolicy;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Measurement\Audiences\AudienceEligibility;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/*
 * Captura temprana del checkout (F4, eventos.md §4.2, cumplimiento.md §3.2): el plugin envía
 * `checkout.contact_captured` firmado por /v1/s con el correo o el teléfono en `identity`. Solo con
 * la opción encendida en el espacio de trabajo; une el carrito con el contacto, habilita el carrito
 * abandonado de ese invitado y no crea consentimiento de marketing.
 */

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function enableEarlyCapture(Workspace $ws, bool $on = true): void
{
    $settings = $ws->settings ?? [];
    $settings['early_capture'] = $on;
    $ws->forceFill(['settings' => $settings])->save();
}

function guestCart($test, string $token, string $at, string $anon = 'anon-invitada'): array
{
    return $test->envelope($test->site, 'cart.updated', ['cart_token' => $token, 'items' => [['product_id' => '88', 'qty' => 1, 'price' => '150000.00']], 'subtotal' => '150000.00', 'coupons' => [], 'currency' => 'COP'],
        ['schema_version' => 6, 'occurred_at' => CarbonImmutable::parse($at)->toIso8601String(), 'identity' => ['anonymous_id' => $anon]]);
}

function captured($test, array $identity, string $token, string $at, string $anon = 'anon-invitada', ?object $site = null): array
{
    return $test->envelope($site ?? $test->site, 'checkout.contact_captured', ['has_email' => isset($identity['email']), 'has_phone' => isset($identity['phone']), 'cart_token' => $token, 'checkout' => 'blocks'],
        ['schema_version' => 6, 'occurred_at' => CarbonImmutable::parse($at)->toIso8601String(), 'identity' => $identity + ['anonymous_id' => $anon], 'context' => ['page_type' => 'checkout', 'currency' => 'COP']]);
}

it('sin la opción del espacio de trabajo el evento se rechaza y no crea contactos ni toca el carrito', function () {
    $this->postSigned($this->site, $this->secret, [
        guestCart($this, 'c_tok_invitada', '2026-11-18 14:00:00'),
        captured($this, ['email' => 'invitada@ejemplo.com'], 'c_tok_invitada', '2026-11-18 14:01:00'),
    ])->assertStatus(202)->assertJson(['accepted' => 2]);
    $this->runWorker();

    app(CurrentWorkspace::class)->run($this->ws, function () {
        expect(Contact::query()->count())->toBe(0)
            ->and(Cart::query()->firstOrFail()->contact_id)->toBeNull()
            ->and(Cart::query()->firstOrFail()->contact_captured_at)->toBeNull();
    });
    $rejection = DB::table('ingest_rejections')->where('event', 'checkout.contact_captured')->first();
    expect($rejection->reason)->toBe('not_allowed')
        ->and((string) $rejection->sample)->not->toContain('invitada@ejemplo.com');
    expect($this->chRows($this->ws, "SELECT count() AS n FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'checkout.contact_captured'")[0]['n'])->toBe(0);
    expect(app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-11-18 15:00:00'))['abandoned'])->toBe(0); // anónimo puro: nadie a quién escribir
});

it('con la opción une el carrito del invitado con su contacto y habilita cart.abandoned, sin consentimiento de marketing', function () {
    enableEarlyCapture($this->ws);
    $this->postSigned($this->site, $this->secret, [
        guestCart($this, 'c_tok_invitada', '2026-11-18 14:00:00'),
        captured($this, ['email' => 'Invitada@Ejemplo.com'], 'c_tok_invitada', '2026-11-18 14:01:00'),
    ])->assertStatus(202);
    $this->runWorker();

    $contact = app(CurrentWorkspace::class)->run($this->ws, function () {
        $contact = Contact::query()->firstOrFail();
        $cart = Cart::query()->firstOrFail();
        expect($cart->contact_id)->toBe($contact->id)
            ->and($cart->contact_captured_at?->toDateTimeString())->toBe('2026-11-18 14:01:00')
            ->and($contact->email)->toBe('invitada@ejemplo.com')
            ->and($contact->early_capture_at)->not->toBeNull()
            ->and(DB::table('contact_consents')->where('contact_id', $contact->id)->count())->toBe(0);

        return $contact;
    });

    // En ClickHouse el evento queda sin el correo (ni en properties ni en ningún otro lado).
    $rows = $this->chRows($this->ws, "SELECT contact_id, toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'checkout.contact_captured'");
    expect($rows)->toHaveCount(1)->and($rows[0]['contact_id'])->toBe($contact->id)
        ->and($rows[0]['p'])->not->toContain('@')
        ->and(json_decode($rows[0]['p'], true))->toMatchArray(['has_email' => true, 'has_phone' => false, 'checkout' => 'blocks']);

    expect(app(DetectAbandonedCarts::class)->handle(CarbonImmutable::parse('2026-11-18 14:50:00'))['abandoned'])->toBe(1);
    $abandoned = $this->chRows($this->ws, "SELECT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'cart.abandoned'");
    expect($abandoned)->toHaveCount(1)->and($abandoned[0]['contact_id'])->toBe($contact->id);

    // El recordatorio es marketing: sin consentimiento del canal, SendPolicy no lo deja salir.
    app(CurrentWorkspace::class)->run($this->ws, function () use ($contact) {
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'ses', 'display_name' => 'Correo', 'status' => 'active', 'is_default' => true]);
        $decision = app(SendPolicy::class)->check(new PolicyRequest($this->ws, $contact->fresh(), Channel::Email, MessageKind::Marketing, at: CarbonImmutable::parse('2026-11-18 15:00:00')));
        expect($decision->allowed())->toBeFalse()->and($decision->reason)->toBe(PolicyDecision::NO_CONSENT);
    });
});

it('la captura más nueva gana (la persona corrigió el correo) y el pedido quita la marca de captura temprana', function () {
    enableEarlyCapture($this->ws);
    $this->postSigned($this->site, $this->secret, [
        guestCart($this, 'c_tok_invitada', '2026-11-18 14:00:00'),
        captured($this, ['email' => 'invitada@ejemplo.co'], 'c_tok_invitada', '2026-11-18 14:01:00'),
        captured($this, ['email' => 'invitada@ejemplo.com', 'phone' => '300 555 1234'], 'c_tok_invitada', '2026-11-18 14:03:00'),
        // Llega tarde una captura más vieja: no pisa la nueva.
        captured($this, ['email' => 'otra@ejemplo.com'], 'c_tok_invitada', '2026-11-18 14:00:30'),
    ]);
    $this->runWorker();

    $contactId = app(CurrentWorkspace::class)->run($this->ws, function () {
        $cart = Cart::query()->firstOrFail();
        $winner = Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'invitada@ejemplo.com'))->firstOrFail();
        expect($cart->contact_id)->toBe($winner->id)->and($winner->phone)->toBe('+573005551234');

        return $winner->id;
    });

    // Compra con el mismo correo: el carrito se convierte y el contacto deja de ser «solo captura».
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.created', ['order' => $this->orderSnapshot(['order_id' => '2001', 'number' => '2001', 'status' => 'pending', 'paid_at' => null, 'customer_ref' => null, 'cart_token' => 'c_tok_invitada',
            'billing' => ['email' => 'invitada@ejemplo.com', 'phone' => '300 555 1234']])], ['schema_version' => 6, 'occurred_at' => CarbonImmutable::parse('2026-11-18 14:10:00')->toIso8601String()]),
    ]);
    $this->runWorker();
    app(CurrentWorkspace::class)->run($this->ws, function () use ($contactId) {
        expect(Cart::query()->firstOrFail()->status)->toBe('converted')
            ->and(Contact::query()->findOrFail($contactId)->early_capture_at)->toBeNull();
    });
});

it('un contacto que ya era cliente no queda marcado y uno solo de captura no entra a las audiencias de anuncios', function () {
    enableEarlyCapture($this->ws);
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1000', 'number' => '1000', 'customer_ref' => null])]),
        captured($this, ['email' => 'ana@ejemplo.com'], 'c_tok_ana', '2026-11-18 14:01:00', 'anon-ana'),
        captured($this, ['phone' => '+573001112233'], 'c_tok_nuevo', '2026-11-18 14:01:00', 'anon-nuevo'),
    ]);
    $this->runWorker();

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $ana = Contact::query()->where('email_bidx', BlindIndex::email($this->ws->id, 'ana@ejemplo.com'))->firstOrFail();
        $nuevo = Contact::query()->where('phone_bidx', BlindIndex::phone($this->ws->id, '+573001112233'))->firstOrFail();
        expect($ana->early_capture_at)->toBeNull()
            ->and($nuevo->early_capture_at)->not->toBeNull()
            ->and(Cart::query()->where('token', 'c_tok_ana')->value('contact_id'))->toBe($ana->id);

        $eligible = AudienceEligibility::apply(DB::table('contacts as c')->where('c.workspace_id', $this->ws->id), $this->ws)->pluck('c.id')->all();
        expect($eligible)->toContain($ana->id)->not->toContain($nuevo->id);
    });
});

it('aislamiento: el mismo carrito y correo en dos espacios de trabajo no se cruzan, y cada uno decide su opción', function () {
    enableEarlyCapture($this->ws);
    $other = $this->workspaceFor($this->userWith2fa(['email' => 'otra@agencia.co']), 'Otra tienda');
    [$otherSite, $otherSecret] = $this->connectedSite($other, 'otra.co');

    $this->postSigned($this->site, $this->secret, [captured($this, ['email' => 'compartida@ejemplo.com'], 'c_tok_igual', '2026-11-18 14:01:00')]);
    $this->postSigned($otherSite, $otherSecret, [captured($this, ['email' => 'compartida@ejemplo.com'], 'c_tok_igual', '2026-11-18 14:01:00', 'anon-invitada', $otherSite)]);
    $this->runWorker();

    $mine = app(CurrentWorkspace::class)->run($this->ws, fn () => [Contact::query()->count(), Cart::query()->value('contact_id'), Contact::query()->value('id')]);
    expect($mine[0])->toBe(1)->and($mine[1])->toBe($mine[2]);
    // El otro espacio no activó la opción: nada se proyecta allí.
    app(CurrentWorkspace::class)->run($other, function () {
        expect(Contact::query()->count())->toBe(0)->and(Cart::query()->count())->toBe(0);
    });
    expect(Cart::withoutWorkspaceScope()->where('token', 'c_tok_igual')->count())->toBe(1);
    expect(DB::table('ingest_rejections')->where('workspace_id', $other->id)->where('reason', 'not_allowed')->count())->toBe(1)
        ->and(DB::table('ingest_rejections')->where('workspace_id', $this->ws->id)->count())->toBe(0);
});

it('el navegador no puede enviar la captura (solo llega firmada por el plugin)', function () {
    enableEarlyCapture($this->ws);
    $event = captured($this, [], 'c_tok_invitada', now()->toIso8601String());
    $event['source']['type'] = 'browser';
    $event['source']['sdk'] = 'tracker/0.1.0';
    $body = json_encode(['k' => $this->site->public_key, 'events' => [$event]]);
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co', 'HTTP_USER_AGENT' => 'Mozilla/5.0'], $body)
        ->assertStatus(202)->assertJson(['accepted' => 0, 'rejected' => [['reason' => 'not_allowed']]]);
});

it('/v1/s/config entrega la opción del espacio de trabajo (no el valor por defecto del sitio) y las versiones del plugin', function () {
    $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk()
        ->assertJsonPath('settings.early_capture', false)
        ->assertJsonPath('plugin.latest_version', config('igr.plugin.latest_version'))
        ->assertJsonPath('plugin.download_url', rtrim((string) config('igr.urls.app'), '/').'/downloads/imagina-growth-connector.zip');
    enableEarlyCapture($this->ws);
    $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk()->assertJsonPath('settings.early_capture', true);
});

it('activarla en Consentimiento exige aceptar la responsabilidad y queda en la auditoría', function () {
    $this->actingAs($this->owner);
    $url = '/api/w/'.$this->ws->publicId().'/consent/early-capture';
    $this->putJson($url, ['enabled' => true])->assertStatus(422)->assertJsonValidationErrors('accept_responsibility');
    expect($this->ws->fresh()->setting('early_capture'))->toBeFalsy();

    $this->putJson($url, ['enabled' => true, 'accept_responsibility' => true])->assertOk()
        ->assertJsonPath('early_capture.enabled', true)
        ->assertJsonPath('early_capture.accepted_by', $this->owner->name);
    $this->putJson($url, ['enabled' => false])->assertOk()->assertJsonPath('early_capture.enabled', false);

    $logs = DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'consent.early_capture_changed')->orderBy('id')->pluck('changes')->map(fn ($c) => json_decode((string) $c, true))->all();
    expect($logs)->toHaveCount(2)
        ->and($logs[0])->toMatchArray(['from' => false, 'to' => true, 'responsibility_accepted' => true])
        ->and($logs[1])->toMatchArray(['from' => true, 'to' => false]);

    // Un miembro sin permiso de administrar no la cambia.
    $member = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($member)->putJson($url, ['enabled' => true, 'accept_responsibility' => true])->assertForbidden();
});
