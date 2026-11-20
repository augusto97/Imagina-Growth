<?php

use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * F16 (docs/auditoria-2026-11.md): el Inicio guía hasta la primera venta recuperada con el estado real de cada paso.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Pinturas La 14');
    $this->url = "/api/w/{$this->ws->publicId()}/getting-started";
});

function stepsOf(array $json): array
{
    return collect($json['steps'])->keyBy('key')->all();
}

it('sin nada hecho: todos los pasos pendientes y el camino de tienda', function () {
    $res = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->json();
    $steps = stepsOf($res);

    expect($res['variant'])->toBe('store')
        ->and(array_keys($steps))->toBe(['connect_site', 'import', 'channel', 'flow', 'first_sale'])
        ->and($res['completed'])->toBe(0)
        ->and($steps['flow']['status'])->toBe('missing')
        ->and($steps['flow']['library_key'])->toBe('abandoned_cart')
        ->and($steps['first_sale']['count'])->toBe(0);
});

it('marca cada paso con los datos reales hasta la primera venta recuperada con su monto', function () {
    $this->useIngestInfrastructure();
    [$site, $secret] = $this->connectedSite($this->ws);
    app(CurrentWorkspace::class)->run($this->ws, fn () => Site::query()->whereKey($site->id)->update(['backfill_state' => json_encode([
        'status' => 'running', 'entities' => ['orders' => ['total' => 10, 'sent' => 5]],
    ])]));

    $steps = stepsOf($this->actingAs($this->owner)->getJson($this->url)->json());
    expect($steps['connect_site']['done'])->toBeTrue()
        ->and($steps['import']['done'])->toBeFalse()
        ->and($steps['import']['status'])->toBe('running')
        ->and($steps['import']['progress'])->toEqual(0.5);

    app(CurrentWorkspace::class)->run($this->ws, fn () => Site::query()->whereKey($site->id)->update(['backfill_state' => json_encode(['status' => 'completed', 'entities' => []])]));
    DB::table('channel_accounts')->insert([
        'id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'channel' => 'email', 'provider' => 'ses',
        'display_name' => 'Correo', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $flow = (string) Str::ulid();
    DB::table('flows')->insert([
        'id' => $flow, 'workspace_id' => $this->ws->id, 'name' => 'Carrito abandonado', 'status' => 'draft', 'template_key' => 'abandoned_cart',
        'trigger' => json_encode(['type' => 'event']), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $steps = stepsOf($this->actingAs($this->owner)->getJson($this->url)->json());
    expect($steps['import']['done'])->toBeTrue()
        ->and($steps['channel']['channels'])->toBe(['email'])
        ->and($steps['flow']['done'])->toBeFalse()
        ->and($steps['flow']['status'])->toBe('draft')
        ->and($steps['flow']['flow_id'])->toBe('fl_'.$flow);

    DB::table('flows')->where('id', $flow)->update(['status' => 'active']);
    $this->postSigned($site, $secret, [$this->envelope($site, 'order.paid', ['order' => $this->orderSnapshot()])]);
    $this->runWorker();
    DB::table('orders')->where('workspace_id', $this->ws->id)->update(['attributed_flow_id' => $flow]);

    $res = $this->actingAs($this->owner)->getJson($this->url)->json();
    $steps = stepsOf($res);
    expect($res['completed'])->toBe(5)
        ->and($steps['first_sale']['count'])->toBe(1)
        ->and($steps['first_sale']['revenue'])->toBe('282000.00')
        ->and($steps['first_sale']['first_revenue'])->toBe('282000.00');
});

it('un sitio de servicios sigue el camino del primer lead ganado', function () {
    [$site] = $this->connectedSite($this->ws, 'consultorio.co');
    app(CurrentWorkspace::class)->run($this->ws, fn () => Site::query()->whereKey($site->id)->update(['platform' => 'wordpress']));
    DB::table('leads')->insert([
        'id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'contact_id' => (string) Str::ulid(), 'origin' => 'form',
        'stage' => 'won', 'won_at' => now(), 'value_ws' => '1500000.00', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $res = $this->actingAs($this->owner)->getJson($this->url)->json();
    $steps = stepsOf($res);
    expect($res['variant'])->toBe('services')
        ->and($steps['flow']['library_key'])->toBe('service_lead')
        ->and($steps['first_sale']['count'])->toBe(1)
        ->and($steps['first_sale']['revenue'])->toBe('1500000.00');
});

it('no mezcla espacios de trabajo y respeta los permisos', function () {
    $other = $this->workspaceFor($owner2 = $this->userWith2fa(), 'Otra tienda');
    DB::table('channel_accounts')->insert([
        'id' => (string) Str::ulid(), 'workspace_id' => $other->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'display_name' => 'WhatsApp', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(stepsOf($this->actingAs($this->owner)->getJson($this->url)->json())['channel']['done'])->toBeFalse()
        ->and(stepsOf($this->actingAs($owner2)->getJson("/api/w/{$other->publicId()}/getting-started")->json())['channel']['done'])->toBeTrue();

    $this->actingAs($owner2)->getJson($this->url)->assertNotFound();
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->getJson($this->url)->assertForbidden();
});
