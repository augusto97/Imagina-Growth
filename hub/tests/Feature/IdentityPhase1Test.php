<?php

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Actions\RevertContactMerge;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Contacts\Models\ContactIdentity;
use App\Domain\Contacts\Models\ContactMerge;
use App\Domain\Identity\SiteToken;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->ws = $this->workspaceFor($this->userWith2fa());
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
});

function inWorkspace($test, callable $fn)
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

function browserEvent($test, string $event, array $props, string $anon, array $identity = []): array
{
    return $test->envelope($test->site, $event, $props, [
        'schema_version' => 2,
        'source' => ['type' => 'browser', 'sdk' => 'tracker/0.1.0'],
        'identity' => ['anonymous_id' => $anon] + $identity,
    ]);
}

function postBrowser($test, array $events)
{
    $body = json_encode(['k' => $test->site->public_key, 'events' => $events]);

    return $test->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], $body)->assertStatus(202);
}

it('vincula al visitante con el cliente solo con un token firmado por el sitio', function () {
    $this->postSigned($this->site, $this->secret, [$this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:77', 'email' => 'ana@ejemplo.com', 'first_name' => 'Ana'])]);
    $this->runWorker();

    // Sin token: el navegador dice ser wc:77 pero no se le cree.
    postBrowser($this, [browserEvent($this, 'page.viewed', ['page_type' => 'home'], 'anon-1', ['customer_ref' => 'wc:77'])]);
    // Token falso y token vencido.
    $forged = SiteToken::sign('sk_otro', ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() + 600]);
    $expired = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() - 1]);
    postBrowser($this, [
        browserEvent($this, 'identify', ['method' => 'login', 'token' => $forged], 'anon-1'),
        browserEvent($this, 'identify', ['method' => 'login', 'token' => $expired], 'anon-1'),
    ]);
    $this->runWorker();
    expect(inWorkspace($this, fn () => ContactIdentity::query()->where('type', 'anonymous_id')->count()))->toBe(0);

    // Token válido: ahora sí.
    $valid = SiteToken::sign($this->secret, ['ref' => 'wc:77', 'site' => $this->site->publicId(), 'exp' => time() + 600]);
    postBrowser($this, [browserEvent($this, 'identify', ['method' => 'login', 'token' => $valid], 'anon-1')]);
    $this->runWorker();
    $contact = inWorkspace($this, fn () => Contact::query()->firstOrFail());
    expect(inWorkspace($this, fn () => ContactIdentity::query()->where('type', 'anonymous_id')->value('contact_id')))->toBe($contact->id);

    // Las vistas siguientes del mismo anónimo quedan con el contacto (vía la caché del mapa).
    postBrowser($this, [browserEvent($this, 'page.viewed', ['page_type' => 'product'], 'anon-1')]);
    $this->runWorker();
    $rows = $this->chRows($this->ws, "SELECT contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'page.viewed' ORDER BY received_at");
    expect(array_column($rows, 'contact_id'))->toBe(['', $contact->id])
        ->and($this->chRows($this->ws, 'SELECT contact_id FROM identity_map WHERE workspace_id = {workspace_id:UInt32}')[0]['contact_id'])->toBe($contact->id);
});

it('fusiona dos contactos que comparten un identificador fuerte y revierte la fusión sin perder nada', function () {
    // Un invitado compra con teléfono; otro contacto se registra con correo; luego un pedido trae ambos.
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1', 'customer_ref' => null, 'billing' => ['email' => '', 'phone' => '3001234567']])]),
        $this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:9', 'email' => 'luis@ejemplo.com', 'first_name' => 'Luis']),
    ]);
    $this->runWorker();
    expect(inWorkspace($this, fn () => Contact::query()->count()))->toBe(2);

    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '2', 'customer_ref' => 'wc:9', 'billing' => ['email' => 'luis@ejemplo.com', 'phone' => '300 123 4567']])]),
    ]);
    $this->runWorker();

    inWorkspace($this, function () {
        $merge = ContactMerge::query()->firstOrFail();
        $winner = Contact::query()->findOrFail($merge->winner_id);
        $loser = Contact::query()->findOrFail($merge->loser_id);
        expect($loser->getAttribute('merged_into_id'))->toBe($winner->id)
            ->and(Order::query()->distinct()->pluck('contact_id')->all())->toBe([$winner->id])
            ->and($winner->fresh()->orders_count)->toBe(2)
            ->and(Contact::query()->whereNull('merged_into_id')->count())->toBe(1)
            ->and(DB::table('audit_logs')->where('action', 'contact.merged')->exists())->toBeTrue();

        app(RevertContactMerge::class)->handle($merge);
        expect($loser->fresh()->getAttribute('merged_into_id'))->toBeNull()
            ->and(Order::query()->where('external_id', '1')->value('contact_id'))->toBe($loser->id)
            ->and(Order::query()->where('external_id', '2')->value('contact_id'))->toBe($winner->id)
            ->and($winner->fresh()->orders_count)->toBe(1)
            ->and($loser->fresh()->orders_count)->toBe(1)
            ->and($loser->fresh()->phone)->toBe('+573001234567');
    });
});

it('no fusiona dos cuentas distintas del mismo sitio aunque compartan el teléfono', function () {
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:1', 'email' => 'mama@ejemplo.com', 'phone' => '+573001112233']),
        $this->envelope($this->site, 'customer.registered', ['customer_ref' => 'wc:2', 'email' => 'hijo@ejemplo.com', 'phone' => '+573001112233']),
    ]);
    $this->runWorker();
    expect(inWorkspace($this, fn () => Contact::query()->whereNull('merged_into_id')->count()))->toBe(2)
        ->and(inWorkspace($this, fn () => ContactMerge::query()->count()))->toBe(0);
});

it('un dispositivo compartido pasa al cliente que se identificó, sin fusionar perfiles', function () {
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '1', 'customer_ref' => 'wc:1', 'billing' => ['email' => 'a@ejemplo.com', 'phone' => '']])], ['identity' => ['anonymous_id' => 'pc-casa']]),
    ]);
    $this->runWorker();
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot(['order_id' => '2', 'customer_ref' => 'wc:2', 'billing' => ['email' => 'b@ejemplo.com', 'phone' => '']])], ['identity' => ['anonymous_id' => 'pc-casa']]),
    ]);
    $this->runWorker();

    inWorkspace($this, function () {
        $b = ContactIdentity::query()->where('value', $this->site->id.':wc:2')->value('contact_id');
        expect(Contact::query()->whereNull('merged_into_id')->count())->toBe(2)
            ->and(ContactIdentity::query()->where('type', 'anonymous_id')->value('contact_id'))->toBe($b);
    });
});
