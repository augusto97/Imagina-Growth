<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$this->site, $this->secret] = $this->connectedSite($this->ws);
    $anon = ['identity' => ['anonymous_id' => 'anon-ana']];
    $browser = fn (string $event, array $props) => $this->envelope($this->site, $event, $props, ['schema_version' => 2, 'source' => ['type' => 'browser'], 'identity' => ['anonymous_id' => 'anon-ana']]);
    // Primero navega anónima; luego compra (el pedido trae el anonymous_id desde la cookie).
    $this->call('POST', '/v1/b', [], [], [], ['CONTENT_TYPE' => 'text/plain', 'HTTP_ORIGIN' => 'https://tienda.co'], json_encode(['k' => $this->site->public_key, 'events' => [
        $browser('product.viewed', ['product_id' => '88', 'name' => 'Pintura Azul', 'price' => '150000.00', 'currency' => 'COP']),
        $browser('search.performed', ['query' => 'pintura azul', 'engine' => 'wc']),
    ]]))->assertStatus(202);
    $this->postSigned($this->site, $this->secret, [
        $this->envelope($this->site, 'order.paid', ['order' => $this->orderSnapshot()], $anon + ['occurred_at' => now()->addSecond()->toIso8601String()]),
    ]);
    $this->runWorker();
    $this->contact = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->firstOrFail());
    $this->url = "/api/w/{$this->ws->publicId()}/contacts/{$this->contact->publicId()}";
});

it('muestra el encabezado del perfil con métricas, consentimientos y datos insuficientes donde corresponde', function () {
    $res = $this->actingAs($this->owner)->getJson($this->url)->assertOk();
    expect($res->json('name'))->toBe('Ana Ruiz')
        ->and($res->json('email'))->toBe('ana@ejemplo.com')
        ->and($res->json('stats.orders_count'))->toBe(1)
        ->and($res->json('clv.historic'))->toBe('282000.00')
        ->and($res->json('churn.band'))->toBeNull()
        ->and($res->json('churn.reason'))->toContain('Datos insuficientes')
        ->and($res->json('consents')['email.marketing']['status'])->toBe('granted')
        ->and($res->json('monthly'))->toHaveCount(1);

    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->getJson($this->url)->assertOk()->assertJsonPath('email', 'a**@e******.com')->assertJsonPath('pii_visible', false);
    $this->actingAs($this->addMember($this->ws, WorkspaceRole::Agent))->getJson($this->url)->assertNotFound();
});

it('une en la línea de tiempo la navegación anónima previa y el pedido, con filtros y paginación', function () {
    $res = $this->actingAs($this->owner)->getJson("{$this->url}/timeline")->assertOk();
    $events = collect($res->json('data'))->pluck('event')->all();
    expect($events)->toContain('order.paid', 'product.viewed', 'search.performed', 'consent.granted')
        ->and(collect($res->json('data'))->firstWhere('event', 'order.paid')['summary']['total'])->toBe('282000.00')
        ->and(collect($res->json('data'))->firstWhere('event', 'search.performed')['summary']['query'])->toBe('pintura azul');

    $only = $this->actingAs($this->owner)->getJson("{$this->url}/timeline?groups[]=navegacion&limit=10")->assertOk();
    expect(collect($only->json('data'))->pluck('event')->unique()->sort()->values()->all())->toBe(['product.viewed', 'search.performed']);
    $this->actingAs($this->owner)->getJson("{$this->url}/timeline?groups[]=inventado")->assertUnprocessable();
});

it('muestra pedidos, afinidad, identidades y privacidad del contacto', function () {
    $this->actingAs($this->owner)->getJson("{$this->url}/orders")->assertOk()->assertJsonPath('data.0.items.0.name', 'Pintura Azul 1 galón');
    $products = $this->actingAs($this->owner)->getJson("{$this->url}/products")->assertOk();
    expect($products->json('brands.0.name'))->toBe('Pintuco')
        ->and(collect($products->json('products'))->firstWhere('id', '88')['views'])->toBe(1);
    $ids = $this->actingAs($this->owner)->getJson("{$this->url}/identities")->assertOk();
    expect(collect($ids->json('identities'))->pluck('type')->sort()->values()->all())->toBe(['anonymous_id', 'customer_ref', 'email_hash', 'phone_hash']);
    $this->actingAs($this->owner)->getJson("{$this->url}/privacy")->assertOk()->assertJsonPath('consents.0.channel', 'email');
});

it('edita con auditoría, toma notas y registra consentimientos desde el panel', function () {
    $this->actingAs($this->owner)->patchJson($this->url, ['city' => 'Pereira', 'tags' => ['vip', 'vip', 'pintor']])->assertOk()
        ->assertJsonPath('city', 'Pereira')->assertJsonPath('tags', ['vip', 'pintor']);
    expect(DB::table('audit_logs')->where('action', 'contact.updated')->exists())->toBeTrue();

    $this->actingAs($this->owner)->postJson("{$this->url}/notes", ['body' => 'Prefiere entregas en la tarde'])->assertOk()->assertJsonPath('data.0.body', 'Prefiere entregas en la tarde');
    expect(DB::table('contact_notes')->value('body'))->not->toContain('Prefiere');

    $this->actingAs($this->owner)->postJson("{$this->url}/consents", ['channel' => 'whatsapp', 'action' => 'granted'])->assertUnprocessable();
    $this->actingAs($this->owner)->postJson("{$this->url}/consents", ['channel' => 'email', 'action' => 'revoked'])->assertOk();
    expect($this->actingAs($this->owner)->getJson($this->url)->json('consents')['email.marketing']['status'])->toBe('revoked');
});

it('fusiona a mano y revierte desde el panel', function () {
    $other = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana R.']));
    $res = $this->actingAs($this->owner)->postJson("{$this->url}/merge", ['other_id' => $other->publicId()])->assertOk();
    $list = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/contacts")->assertOk();
    expect($list->json('meta.total'))->toBe(1);
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/merges/{$res->json('merge_id')}/revert")->assertOk();
    expect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/contacts")->json('meta.total'))->toBe(2);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->postJson("{$this->url}/merge", ['other_id' => $other->publicId()])->assertForbidden();
});

it('filtra la lista de contactos', function () {
    $base = "/api/w/{$this->ws->publicId()}/contacts";
    $this->actingAs($this->owner)->getJson("{$base}?consent=email")->assertJsonPath('meta.total', 1);
    $this->actingAs($this->owner)->getJson("{$base}?consent=whatsapp")->assertJsonPath('meta.total', 0);
    $this->actingAs($this->owner)->getJson("{$base}?buyers=1&city=Manizales&sort=revenue")->assertJsonPath('meta.total', 1);
    $this->actingAs($this->owner)->getJson("{$base}?stage=lead")->assertJsonPath('meta.total', 0);
});

it('abre el perfil de un contacto con RFM calculado y apunta al que quedó tras una fusión', function () {
    DB::table('contacts')->where('id', $this->contact->id)->update(['rfm_r' => 5, 'rfm_f' => 1, 'rfm_m' => 3, 'rfm_segment' => 'new', 'rfm_computed_at' => now(), 'expected_next_order_at' => now()->addDays(20)]);
    $res = $this->actingAs($this->owner)->getJson($this->url)->assertOk();
    expect($res->json('rfm.segment'))->toBe('new')
        ->and($res->json('rfm.computed_at'))->not->toBeNull()
        ->and($res->json('expected_next_order_at'))->not->toBeNull()
        ->and($res->json('merged_into'))->toBeNull();

    $other = app(CurrentWorkspace::class)->run($this->ws, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana R.']));
    $this->actingAs($this->owner)->postJson("{$this->url}/merge", ['other_id' => $other->publicId()])->assertOk();
    $absorbed = "/api/w/{$this->ws->publicId()}/contacts/{$other->publicId()}";
    $this->actingAs($this->owner)->getJson($absorbed)->assertOk()->assertJsonPath('merged_into', $this->contact->publicId());
});

it('marca qué solicitudes de acceso se pueden descargar', function () {
    $api = "/api/w/{$this->ws->publicId()}";
    $req = $this->actingAs($this->owner)->postJson("{$this->url}/data-requests", ['kind' => 'access'])->assertCreated();
    expect($this->actingAs($this->owner)->getJson("{$this->url}/privacy")->json('requests.0.downloadable'))->toBeFalse();
    $this->actingAs($this->owner)->postJson("{$api}/data-requests/{$req->json('id')}/execute")->assertOk();
    expect($this->actingAs($this->owner)->getJson("{$this->url}/privacy")->json('requests.0.downloadable'))->toBeTrue();
});

it('muestra en la línea de tiempo la fidelización, los mensajes, las reseñas y lo que dejó en el sitio', function () {
    $events = app(HubEvents::class);
    $events->emit($this->ws, 'points.earned', ['ledger_id' => 'le_1', 'points' => 120, 'balance_after' => 120, 'status' => 'pending', 'reason' => 'order'], $this->contact->id);
    $events->emit($this->ws, 'tier.changed', ['from' => null, 'to' => 'tr_1', 'from_name' => null, 'to_name' => 'Plata', 'direction' => 'up'], $this->contact->id);
    $events->emit($this->ws, 'review.collected', ['review_id' => 'rv_1', 'product_id' => '88', 'rating' => 5, 'has_media' => true, 'media_count' => 2, 'source' => 'request', 'status' => 'pending'], $this->contact->id);
    $events->emit($this->ws, 'product_watch.created', ['watch_id' => 'pw_1', 'product_id' => '88', 'kind' => 'back_in_stock', 'price' => '150000.00'], $this->contact->id);

    $res = $this->actingAs($this->owner)->getJson("{$this->url}/timeline?groups[]=fidelizacion&groups[]=resenas&groups[]=sitio")->assertOk();
    $byEvent = collect($res->json('data'))->keyBy('event');
    expect($byEvent->keys()->sort()->values()->all())->toBe(['points.earned', 'product_watch.created', 'review.collected', 'tier.changed'])
        ->and($byEvent['points.earned']['summary']['points'])->toBe(120)
        ->and($byEvent['tier.changed']['summary'])->toMatchArray(['tier' => 'Plata', 'direction' => 'up'])
        ->and($byEvent['review.collected']['summary'])->toMatchArray(['rating' => 5, 'media' => 2])
        ->and($byEvent['product_watch.created']['summary']['watch_kind'])->toBe('back_in_stock');
});

it('el agente de la bandeja ve solo los contactos de las conversaciones que tiene asignadas', function () {
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $api = "/api/w/{$this->ws->publicId()}/contacts";
    $this->actingAs($agent)->getJson($this->url)->assertNotFound();
    expect($this->actingAs($agent)->getJson($api)->assertOk()->json('data'))->toBe([]);

    app(CurrentWorkspace::class)->run($this->ws, fn () => Conversation::query()->create([
        'workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => (string) Str::ulid(), 'status' => 'open', 'assignee_id' => $agent->id,
    ]));
    $this->actingAs($agent)->getJson($this->url)->assertOk()->assertJsonPath('name', 'Ana Ruiz');
    expect($this->actingAs($agent)->getJson($api)->json('data'))->toHaveCount(1);
});
