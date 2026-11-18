<?php

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\IngestStream;
use App\Support\Tenancy\BelongsToWorkspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\MissingWorkspaceContext;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Finder\Finder;

/*
 * hub/CLAUDE.md §4: recorre todos los modelos y endpoints con dos espacios de
 * trabajo y verifica que no hay fugas. Obligatorias en CI.
 */

/** @return list<class-string<Model>> */
function tenantModels(): array
{
    $models = [];
    foreach ((new Finder)->files()->in(app_path('Domain'))->name('*.php')->path('Models') as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getRealPath(), strlen(app_path()) + 1));
        if (class_exists($class) && in_array(BelongsToWorkspace::class, class_uses_recursive($class), true)) {
            $models[] = $class;
        }
    }
    sort($models);

    return $models;
}

beforeEach(function () {
    $this->useIngestInfrastructure();
    // Dos tiendas, cada una con un pedido, contacto, producto e identidades.
    foreach (['a', 'b'] as $k) {
        $owner = $this->userWith2fa();
        $ws = $this->workspaceFor($owner, "Tienda {$k}");
        [$site, $secret] = $this->connectedSite($ws, "tienda-{$k}.co");
        $this->postSigned($site, $secret, [
            $this->envelope($site, 'catalog.product_upserted', ['product_id' => '88', 'type' => 'simple', 'name' => 'P', 'price' => '1.00', 'stock_status' => 'instock', 'status' => 'publish', 'hash' => 'abcdefabcdefabcd']),
            $this->envelope($site, 'catalog.category_upserted', ['category_id' => '12', 'name' => 'Pinturas']),
            $this->envelope($site, 'order.paid', ['order' => $this->orderSnapshot()], ['identity' => ['anonymous_id' => "anon-{$k}"]]),
        ]);
        $this->{$k} = compact('owner', 'ws', 'site', 'secret');
        $this->actingAs($owner)->postJson("/api/w/{$ws->publicId()}/invitations", ['email' => "x{$k}@y.co", 'role' => 'viewer']);
        $this->actingAs($owner)->postJson("/api/w/{$ws->publicId()}/sites/connect-codes", []);
    }
    $this->runWorker();
});

it('descubre los modelos de negocio con BelongsToWorkspace', function () {
    expect(tenantModels())->toContain(Order::class)->and(count(tenantModels()))->toBeGreaterThanOrEqual(10);
});

it('ningún modelo devuelve filas de otro espacio de trabajo', function () {
    foreach (tenantModels() as $model) {
        foreach (['a' => 'b', 'b' => 'a'] as $mine => $theirs) {
            $rows = app(CurrentWorkspace::class)->run($this->{$mine}['ws'], fn () => $model::query()->get());
            $foreign = $rows->filter(fn (Model $m) => $m->getAttribute('workspace_id') !== $this->{$mine}['ws']->id);
            expect($foreign)->toBeEmpty("{$model} filtró filas de {$theirs} hacia {$mine}");
        }
    }
});

it('ningún modelo consulta sin espacio de trabajo activo', function () {
    app()->forgetScopedInstances();  // como al empezar una petición o un job nuevo
    foreach (tenantModels() as $model) {
        expect(fn () => $model::query()->count())->toThrow(MissingWorkspaceContext::class);
    }
});

it('los endpoints de un espacio de trabajo no son accesibles desde otro', function () {
    $a = $this->a;
    $b = $this->b;
    $wsB = $b['ws']->publicId();
    foreach (['', '/overview', '/members', '/sites', '/contacts', '/audit-logs', "/sites/{$b['site']->publicId()}"] as $path) {
        $this->actingAs($a['owner'])->getJson("/api/w/{$wsB}{$path}")->assertNotFound();
    }
    // Un ID de sitio de B dentro de la ruta de A tampoco existe para A.
    $this->actingAs($a['owner'])->getJson("/api/w/{$a['ws']->publicId()}/sites/{$b['site']->publicId()}")->assertNotFound();
    $this->actingAs($a['owner'])->postJson("/api/w/{$a['ws']->publicId()}/sites/{$b['site']->publicId()}/disconnect")->assertNotFound();
    expect($b['site']->fresh()->status)->toBe('connected');

    $list = $this->actingAs($a['owner'])->getJson("/api/w/{$a['ws']->publicId()}/contacts")->assertOk();
    expect($list->json('meta.total'))->toBe(1);
});

it('un sitio no puede escribir con la identidad de otro', function () {
    $a = $this->a;
    $b = $this->b;
    $event = $this->envelope($b['site'], 'catalog.product_deleted', ['product_id' => '88']);
    $this->postSigned($a['site'], $a['secret'], [$event])->assertJsonPath('rejected.0.reason', 'site_mismatch');
    $this->signedJson('GET', '/v1/s/catalog/hashes', $b['site'], $a['secret'])->assertUnauthorized();
    $hashes = $this->signedJson('GET', '/v1/s/catalog/hashes', $a['site'], $a['secret'])->assertOk();
    expect($hashes->json('items'))->toHaveCount(1);
});

it('ClickHouse solo se consulta con el filtro de workspace y no mezcla datos', function () {
    expect(fn () => Ch::forWorkspace($this->a['ws'])->select('SELECT count() FROM events'))->toThrow(InvalidArgumentException::class);
    expect(fn () => Ch::forWorkspace($this->a['ws'])->select('SELECT 1 WHERE {workspace_id:UInt32} > 0', ['workspace_id' => 9]))->toThrow(InvalidArgumentException::class);

    foreach (['a', 'b'] as $k) {
        /** @var Workspace $ws */
        $ws = $this->{$k}['ws'];
        $ids = collect(Ch::forWorkspace($ws)->select('SELECT DISTINCT workspace_id FROM events WHERE workspace_id = {workspace_id:UInt32}'))->pluck('workspace_id')->all();
        expect($ids)->toBe([$ws->ch_id]);
        expect((int) Ch::forWorkspace($ws)->value('SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32}'))->toBe(3);
    }
});

it('el dedupe de Redis lleva el prefijo del espacio de trabajo', function () {
    $keys = app(IngestStream::class)->connection()->command('keys', ['ws:*:evt:*']);
    expect($keys)->toHaveCount(6);
    foreach ($keys as $key) {
        expect($key)->toMatch('/^ws:('.$this->a['ws']->id.'|'.$this->b['ws']->id.'):evt:/');
    }
});
