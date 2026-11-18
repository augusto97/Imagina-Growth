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
