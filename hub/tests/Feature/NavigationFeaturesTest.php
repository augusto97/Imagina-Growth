<?php

use App\Domain\Billing\Plans\PlanResolver;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * U2 (docs/auditoria-2026-11.md): /api/me dice qué módulos del plan están activos y qué tipo de negocio es cada
 * espacio de trabajo, para que el menú oculte lo que no aplica.
 */

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner, 'Pinturas La 14');
});

function meWorkspace(object $test, string $id): array
{
    $ws = collect($test->actingAs($test->owner->fresh())->getJson('/api/me')->assertOk()->json('workspaces'))->firstWhere('id', $id);
    expect($ws)->not->toBeNull();

    return $ws;
}

it('sin plan ni sitios: todos los módulos y el tipo de negocio sin datos', function () {
    $ws = meWorkspace($this, $this->ws->publicId());

    expect($ws['modules'])->toContain('loyalty', 'inbox', 'services', 'bookings', 'quotes')
        ->and($ws['capabilities'])->toBe(['store' => null, 'services' => null]);
});

it('con el plan de tienda de inicio solo salen sus módulos', function () {
    DB::table('accounts')->where('id', $this->ws->account_id)->update(['plan_id' => 'tienda_inicio']);
    app(PlanResolver::class)->refresh($this->ws);

    $ws = meWorkspace($this, $this->ws->publicId());

    expect($ws['modules'])->toEqualCanonicalizing(['onsite', 'reviews', 'ai']);
});

it('una tienda WooCommerce es tienda y no servicios; un WordPress sin tienda es servicios', function () {
    $this->connectedSite($this->ws, 'tienda.co');
    expect(meWorkspace($this, $this->ws->publicId())['capabilities'])->toBe(['store' => true, 'services' => false]);

    $other = $this->workspaceFor($this->owner, 'Consultorio Ruiz');
    [$site] = $this->connectedSite($other, 'consultorio.co');
    app(CurrentWorkspace::class)->run($other, fn () => Site::query()->whereKey($site->id)->update(['platform' => 'wordpress']));

    expect(meWorkspace($this, $other->publicId())['capabilities'])->toBe(['store' => false, 'services' => true])
        // El otro espacio de trabajo no se contagia.
        ->and(meWorkspace($this, $this->ws->publicId())['capabilities'])->toBe(['store' => true, 'services' => false]);
});

it('una tienda que ya usa leads o es de una industria de servicios también muestra servicios', function () {
    $this->connectedSite($this->ws, 'tienda.co');
    DB::table('leads')->insert([
        'id' => (string) Str::ulid(), 'workspace_id' => $this->ws->id, 'contact_id' => (string) Str::ulid(),
        'origin' => 'form', 'stage' => 'new', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(meWorkspace($this, $this->ws->publicId())['capabilities'])->toBe(['store' => true, 'services' => true]);

    $spa = $this->workspaceFor($this->owner, 'Spa Bienestar');
    $spa->forceFill(['industry' => 'salud_bienestar'])->save();
    $this->connectedSite($spa, 'spa.co');
    expect(meWorkspace($this, $spa->publicId())['capabilities'])->toBe(['store' => true, 'services' => true]);
});

it('un sitio desconectado no cuenta', function () {
    [$site] = $this->connectedSite($this->ws, 'tienda.co');
    app(CurrentWorkspace::class)->run($this->ws, fn () => Site::query()->whereKey($site->id)->update(['status' => Site::STATUS_DISCONNECTED]));

    expect(meWorkspace($this, $this->ws->publicId())['capabilities'])->toBe(['store' => null, 'services' => null]);
});
