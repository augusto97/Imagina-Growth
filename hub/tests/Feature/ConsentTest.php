<?php

use App\Domain\Consent\Actions\ConsentTexts;
use App\Domain\Consent\Actions\RecordConsent;
use App\Domain\Consent\Models\ConsentEvent;
use App\Domain\Consent\Models\ContactConsent;
use App\Domain\Contacts\Models\Contact;
use App\Support\Tenancy\CurrentWorkspace;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

it('registra el consentimiento del checkout por canal con evidencia y sin revocar por casilla vacía', function () {
    $order = $this->orderSnapshot(['consents' => ['email_marketing' => false, 'whatsapp_marketing' => true, 'text_version' => 'v3']]);
    $paid = $this->envelope($this->site, 'order.paid', ['order' => $order], ['context' => ['ip' => '190.24.1.10', 'user_agent' => 'Mozilla/5.0', 'page' => ['url' => 'https://tienda.co/finalizar-compra/']]]);
    $this->postSigned($this->site, $this->secret, [$paid]);
    $this->runWorker();

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $consents = ContactConsent::query()->get();
        expect($consents)->toHaveCount(1)
            ->and($consents[0]->channel)->toBe('whatsapp')
            ->and($consents[0]->status)->toBe('granted')
            ->and($consents[0]->source)->toBe('checkout')
            ->and($consents[0]->legal_text_version)->toBe('v3');
        $evidence = ConsentEvent::query()->firstOrFail()->evidence;
        expect($evidence['page_url'])->toBe('https://tienda.co/finalizar-compra/')
            ->and($evidence['ip_hash'])->toHaveLength(64)
            ->and(json_encode($evidence))->not->toContain('190.24.1.10');
    });

    // El mismo pedido otra vez (otro evento) no duplica la prueba.
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'order.completed', ['order_id' => '1234', 'order' => $order])]);
    $this->runWorker();
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => ConsentEvent::query()->count()))->toBe(1);

    $rows = $this->chRows($this->ws, "SELECT event FROM events WHERE workspace_id = {workspace_id:UInt32} AND event LIKE 'consent.%'");
    expect(array_column($rows, 'event'))->toBe(['consent.granted']);
});

it('registra consentimientos de formularios y nunca desde la importación histórica', function () {
    $form = $this->envelope($this->site, 'form.submitted', [
        'form_plugin' => 'cf7', 'form_id' => '12', 'form_name' => 'Contacto', 'page_path' => '/contacto/',
        'fields' => ['email' => 'ana@ejemplo.com', 'first_name' => 'Ana'], 'consents' => ['email_marketing' => true],
    ]);
    $imported = $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '9', 'consents' => ['whatsapp_marketing' => true, 'text_version' => 'v1']])], ['source' => ['type' => 'import']]);
    $this->postSigned($this->site, $this->secret, [$form, $imported]);
    $this->runWorker();

    app(CurrentWorkspace::class)->run($this->ws, function () {
        $consents = ContactConsent::query()->get();
        expect($consents->pluck('channel')->all())->toBe(['email'])
            ->and($consents[0]->source)->toBe('form:cf7:12');
    });
});

it('una acción vieja no pisa una más nueva y la revocación queda registrada', function () {
    $record = app(RecordConsent::class);
    app(CurrentWorkspace::class)->run($this->ws, function () use ($record) {
        $contact = Contact::query()->create(['workspace_id' => $this->ws->id]);
        $record->handle($this->ws, $contact->id, 'whatsapp', 'marketing', 'revoked', 'whatsapp_reply', null, now());
        expect($record->handle($this->ws, $contact->id, 'whatsapp', 'marketing', 'granted', 'checkout', 'v1', now()->subDay()))->toBeFalse()
            ->and(ContactConsent::query()->value('status'))->toBe('revoked');
        expect(fn () => ConsentEvent::query()->firstOrFail()->update(['action' => 'granted']))->not->toThrow(Throwable::class);
        expect(ConsentEvent::query()->value('action'))->toBe('revoked');  // solo inserción: la actualización se ignora
    });
});

it('entrega al plugin los textos vigentes de consentimiento, con v1 por defecto y versiones nuevas', function () {
    $config = $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk();
    expect($config->json('consent.texts'))->toHaveCount(2)
        ->and($config->json('consent.texts.0.channel'))->toBe('whatsapp')
        ->and($config->json('consent.texts.0.version'))->toBe('v1')
        ->and($config->json('consent.texts.0.label'))->toContain('Pinturas La 14');

    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ConsentTexts::class)->publish($this->ws, 'whatsapp', 'Acepto recibir ofertas por WhatsApp.'));
    $this->travel(1)->seconds();
    $config = $this->signedJson('GET', '/v1/s/config', $this->site, $this->secret)->assertOk();
    expect($config->json('consent.texts.0.version'))->toBe('v2')
        ->and($config->json('consent.texts.0.label'))->toBe('Acepto recibir ofertas por WhatsApp.');
});
