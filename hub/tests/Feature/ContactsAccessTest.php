<?php

use App\Domain\Workspaces\Enums\WorkspaceRole;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    [$site, $secret] = $this->connectedSite($this->ws);
    $this->postSigned($site, $secret, [$this->envelope($site, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();
});

it('un agent no ve por la API contactos que no tiene asignados (M01)', function () {
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->getJson("/api/w/{$this->ws->publicId()}/contacts")->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
    $this->actingAs($agent)->getJson("/api/w/{$this->ws->publicId()}/overview")->assertForbidden();
});

it('marketing ve los contactos enmascarados; el dueño, completos', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->getJson("/api/w/{$this->ws->publicId()}/contacts")->assertOk()
        ->assertJsonPath('data.0.email', 'a**@e******.com')
        ->assertJsonPath('data.0.orders_count', 1);
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/contacts")->assertJsonPath('data.0.email', 'ana@ejemplo.com');
});

it('busca por correo exacto, teléfono y prefijo del nombre con índice ciego', function () {
    $url = "/api/w/{$this->ws->publicId()}/contacts?q=";
    $this->actingAs($this->owner)->getJson($url.urlencode('ANA@ejemplo.com'))->assertJsonPath('meta.total', 1);
    $this->actingAs($this->owner)->getJson($url.urlencode('3001234567'))->assertJsonPath('meta.total', 1);
    $this->actingAs($this->owner)->getJson($url.'an')->assertJsonPath('meta.total', 1);
    $this->actingAs($this->owner)->getJson($url.'luis')->assertJsonPath('meta.total', 0);
});

it('el resumen de inicio cuenta pedidos, ventas y eventos', function () {
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/overview")->assertOk()
        ->assertJsonPath('orders.paid', 1)
        ->assertJsonPath('revenue_ws', '282000.00')
        ->assertJsonPath('contacts.customers', 1)
        ->assertJsonPath('events_24h', 2);  // order.paid + consent.granted (correo) del pedido de ejemplo
});

it('⌘K busca contactos por nombre, correo y teléfono sin salirse del espacio de trabajo', function () {
    // Otro espacio de trabajo con la misma persona (mismo correo, teléfono y nombre).
    $owner2 = $this->userWith2fa();
    $other = $this->workspaceFor($owner2, 'Otra tienda');
    [$site2, $secret2] = $this->connectedSite($other, 'otra.co');
    $this->postSigned($site2, $secret2, [$this->envelope($site2, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();

    $search = fn ($user, $ws, string $q) => $this->actingAs($user)->getJson("/api/w/{$ws->publicId()}/contacts/search?q=".urlencode($q))->assertOk()->json('data');

    $mine = $search($this->owner, $this->ws, 'ana@ejemplo.com');
    $theirs = $search($owner2, $other, 'ana@ejemplo.com');
    expect($mine)->toHaveCount(1)->and($theirs)->toHaveCount(1)
        ->and($mine[0]['id'])->not->toBe($theirs[0]['id'])
        ->and($mine[0]['name'])->toBe('Ana Ruiz');

    expect($search($this->owner, $this->ws, '300 123 4567'))->toHaveCount(1)
        ->and($search($this->owner, $this->ws, 'Ána ru'))->toHaveCount(1)   // sin tildes y por el comienzo de cada palabra
        ->and($search($this->owner, $this->ws, 'ana pe'))->toHaveCount(0)
        ->and($search($this->owner, $this->ws, 'a'))->toBe([]);

    // Nadie busca en un espacio de trabajo ajeno, y el agente solo ve lo suyo (nada asignado).
    $this->actingAs($owner2)->getJson("/api/w/{$this->ws->publicId()}/contacts/search?q=ana")->assertNotFound();
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    expect($search($agent, $this->ws, 'ana@ejemplo.com'))->toBe([]);

    // Marketing ve los datos enmascarados.
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    expect($search($marketing, $this->ws, 'ana')[0]['email'])->toBe('a**@e******.com');
});
