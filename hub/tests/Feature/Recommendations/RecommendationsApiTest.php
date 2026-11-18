<?php

use App\Domain\Recommendations\Jobs\BuildRecommendationData;
use App\Domain\Recommendations\Models\OnsiteBanner;
use App\Domain\Recommendations\Models\ProductWatch;
use App\Domain\Recommendations\Stats\AssociationRulesBuilder;
use App\Domain\Recommendations\Stats\ProductStatsBuilder;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Library\TemplateLibrary;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Recommendations\Support\CommerceFixtures;

uses(CommerceFixtures::class);

// M16 en el panel: recomendaciones, avisos y banners.
beforeEach(function () {
    $this->commerceSetUp();
    $this->api = '/api/w/'.$this->ws->publicId().'/onsite-commerce';
    foreach (['A' => '12', 'B' => '12', 'C' => '30'] as $id => $cat) {
        $this->commerceProduct($id, "Producto {$id}", [$cat]);
    }
    foreach (range(1, 3) as $i) {
        $this->commerceOrder(null, ['A' => 1, 'B' => 2]);
    }
    $this->commerceOrder(null, ['C' => 1]);
    $this->commerceOrder(null, ['C' => 1]);
    $this->commerceRun(function () {
        app(ProductStatsBuilder::class)->build($this->ws, $this->site);
        app(AssociationRulesBuilder::class)->build($this->ws, $this->site);
    });
});

function commerceWatchRow($test, string $contactId, string $product, string $status, ?string $notifiedAt = null, string $kind = 'back_in_stock'): ProductWatch
{
    return $test->commerceRun(fn () => ProductWatch::query()->create([
        'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'contact_id' => $contactId, 'external_product_id' => $product, 'kind' => $kind,
        'channel' => 'email', 'status' => $status, 'notified_at' => $notifiedAt, 'threshold' => $kind === 'price_drop' ? '10000.00' : null,
    ]));
}

it('muestra los bloques, el estado del cálculo y la vista previa con un producto o contacto real', function () {
    $overview = $this->actingAs($this->owner)->getJson("{$this->api}/recommendations")->assertOk();
    expect($overview->json('site_id'))->toBe($this->site->publicId())
        ->and($overview->json('kinds.*.kind'))->toBe(['para_ti', 'bought_together', 'recently_viewed', 'best_sellers_category', 'trending', 'complete_cart'])
        ->and($overview->json('kinds.1.shortcode'))->toBe('[igr_reco type="bought_together"]')
        ->and($overview->json('stats'))->toMatchArray(['products' => 3, 'in_stock' => 3, 'with_sales_90d' => 3, 'rules' => 2, 'products_with_rules' => 2])
        ->and($overview->json('stats.rules_computed_at'))->not->toBeNull();

    $preview = $this->actingAs($this->owner)->getJson("{$this->api}/recommendations/preview?kind=bought_together&product_id=A")->assertOk();
    expect($preview->json('products.*.id'))->toBe(['B'])->and($preview->json('fallback'))->toBeFalse();

    $buyer = $this->commerceContact(['first_name' => 'Ana']);
    $this->commerceOrder($buyer->id, ['C' => 1]);
    $forAna = $this->actingAs($this->owner)->getJson("{$this->api}/recommendations/preview?kind=para_ti&contact_id={$buyer->publicId()}")->assertOk();
    // La categoría 30 solo tiene lo que ya compró: los más vendidos, sin lo suyo.
    expect($forAna->json('fallback'))->toBeTrue()->and($forAna->json('products.*.id'))->toBe(['B', 'A']);
    $this->actingAs($this->owner)->getJson("{$this->api}/recommendations/preview?kind=para_ti&contact_id=ct_01JD0000000000000000000000")->assertStatus(422);
    $this->actingAs($this->owner)->getJson("{$this->api}/recommendations/preview?kind=otra")->assertStatus(422);

    $rules = $this->actingAs($this->owner)->getJson("{$this->api}/recommendations/associations?product_id=A")->assertOk();
    expect($rules->json('total'))->toBe(1)
        ->and($rules->json('data.0'))->toMatchArray(['pair_orders' => 3, 'support' => 0.6, 'confidence' => 1.0, 'lift' => 1.6667])
        ->and($rules->json('data.0.related.name'))->toBe('Producto B');

    $products = $this->actingAs($this->owner)->getJson("{$this->api}/products?q=Producto")->assertOk();
    expect($products->json('data.0'))->toMatchArray(['id' => 'B', 'sold_90d' => 6, 'in_stock' => true]);
});

it('guarda qué bloques se activan y solo con permiso de marketing; reconstruir encola el cálculo', function () {
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->api}/recommendations")->assertOk();
    $this->actingAs($viewer)->patchJson("{$this->api}/recommendations/settings", ['kinds' => ['trending' => false]])->assertForbidden();
    $this->actingAs($viewer)->postJson("{$this->api}/recommendations/rebuild")->assertForbidden();

    $saved = $this->actingAs($this->owner)->patchJson("{$this->api}/recommendations/settings", ['kinds' => ['trending' => false]])->assertOk();
    expect($saved->json('settings.kinds.trending'))->toBeFalse()->and($saved->json('settings.kinds.para_ti'))->toBeTrue()->and($saved->json('settings.enabled'))->toBeTrue();
    $this->actingAs($this->owner)->patchJson("{$this->api}/recommendations/settings", ['kinds' => ['otra' => true]])->assertStatus(422);
    expect(DB::table('audit_logs')->where('action', 'onsite_commerce.recommendations_updated')->count())->toBe(1);

    Bus::fake();
    $this->actingAs($this->owner)->postJson("{$this->api}/recommendations/rebuild")->assertStatus(202);
    Bus::assertDispatched(fn (BuildRecommendationData $job) => $job->workspaceId() === $this->ws->id);
});

it('resume los avisos con las ventas de los 7 días siguientes y lista las suscripciones', function () {
    $ana = $this->commerceContact(['first_name' => 'Ana', 'last_name' => 'Ruiz']);
    $leo = $this->commerceContact(['first_name' => 'Leo']);
    commerceWatchRow($this, $ana->id, 'A', 'notified', now()->subDays(5)->toDateTimeString());
    commerceWatchRow($this, $leo->id, 'B', 'notified', now()->subDays(20)->toDateTimeString());
    commerceWatchRow($this, $leo->id, 'C', 'active');
    commerceWatchRow($this, $leo->id, 'C', 'active', null, 'price_drop');
    commerceWatchRow($this, $ana->id, 'B', 'cancelled');
    $this->commerceOrder($ana->id, ['A' => 1], 3);       // dentro de los 7 días del aviso: cuenta
    $this->commerceOrder($leo->id, ['B' => 1], 2);       // 18 días después del aviso: no cuenta
    $this->commerceOrder($ana->id, ['C' => 1], 3);       // otro producto: no cuenta

    $summary = $this->actingAs($this->owner)->getJson("{$this->api}/watches/summary")->assertOk();
    expect($summary->json('totals'))->toMatchArray([
        'active' => 2, 'active_back_in_stock' => 1, 'active_price_drop' => 1, 'notified' => 2, 'notified_in_range' => 2, 'cancelled' => 1,
        'orders_after' => 1, 'buyers_after' => 1, 'revenue_after' => '10000.00', 'conversion_rate' => 0.5,
    ])->and($summary->json('top_products.0'))->toMatchArray(['id' => 'C', 'name' => 'Producto C', 'back_in_stock' => 1, 'price_drop' => 1])
        ->and(array_sum(array_column($summary->json('weekly'), 'created')))->toBe(5)
        ->and($summary->json('settings.enabled'))->toBeTrue();

    $list = $this->actingAs($this->owner)->getJson("{$this->api}/watches?status=active")->assertOk();
    expect($list->json('total'))->toBe(2)->and($list->json('data.0.contact.name'))->toBe('Leo')->and($list->json('data.0.product.name'))->toBe('Producto C');
    // Quien no ve contactos ve los avisos sin nombres.
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    expect($this->actingAs($viewer)->getJson("{$this->api}/watches")->json('data.0.contact'))->toBeNull();
});

it('elige la plantilla de cada aviso y cancela suscripciones con permiso', function () {
    $wa = $this->commerceRun(fn () => Template::query()->create(['workspace_id' => $this->ws->id, 'name' => 'Volvió (WA)', 'channel' => 'whatsapp', 'category' => 'marketing']));
    $email = TemplateLibrary::install($this->ws, 'price_drop');
    $saved = $this->actingAs($this->owner)->patchJson("{$this->api}/watches/settings", [
        'channels' => ['whatsapp', 'email'], 'templates' => ['back_in_stock' => ['whatsapp' => $wa->publicId()], 'price_drop' => ['email' => $email->publicId()]],
    ])->assertOk();
    expect($saved->json('settings.channels'))->toBe(['whatsapp', 'email'])
        ->and($saved->json('settings.templates.back_in_stock.whatsapp'))->toBe($wa->publicId())
        ->and($saved->json('settings.available_templates.*.name'))->toContain('Volvió (WA)');
    // Una plantilla de correo no sirve para WhatsApp.
    $this->actingAs($this->owner)->patchJson("{$this->api}/watches/settings", ['templates' => ['back_in_stock' => ['whatsapp' => $email->publicId()]]])->assertStatus(422);

    $watch = commerceWatchRow($this, $this->commerceContact()->id, 'A', 'active');
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->postJson("{$this->api}/watches/{$watch->publicId()}/cancel")->assertForbidden();
    $this->actingAs($this->owner)->postJson("{$this->api}/watches/{$watch->publicId()}/cancel")->assertOk()->assertJsonPath('status', 'cancelled');
});

it('crea, cambia y borra banners; el texto sin sesión no puede llevar nombre ni puntos', function () {
    $segment = $this->commerceRun(fn () => Segment::query()->where('system_key', 'rfm_champions')->first());
    $created = $this->actingAs($this->owner)->postJson("{$this->api}/banners", [
        'name' => 'Campeones', 'audience' => 'segment', 'segment_id' => $segment?->publicId(), 'text' => 'Hola, {{nombre}}', 'text_public' => 'Gracias por volver', 'url' => 'https://tienda.co/vip',
    ])->assertCreated();
    expect($created->json('segment.name'))->toBe('Campeones')->and($created->json('live'))->toBeTrue()->and($created->json('placement'))->toBe('top');

    $this->actingAs($this->owner)->postJson("{$this->api}/banners", ['name' => 'X', 'audience' => 'points', 'text' => 'Hola', 'text_public' => 'Tienes {{puntos}} puntos'])
        ->assertStatus(422)->assertJsonValidationErrors('text_public');
    $this->actingAs($this->owner)->postJson("{$this->api}/banners", ['name' => 'X', 'audience' => 'segment', 'text' => 'Hola'])->assertStatus(422)->assertJsonValidationErrors('segment_id');

    $id = $created->json('id');
    $this->actingAs($this->owner)->patchJson("{$this->api}/banners/{$id}", ['status' => 'paused', 'priority' => 5])->assertOk()->assertJsonPath('live', false);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->getJson("{$this->api}/banners")->assertOk()->assertJsonCount(1, 'data');
    $this->actingAs($viewer)->deleteJson("{$this->api}/banners/{$id}")->assertForbidden();
    $this->actingAs($this->owner)->deleteJson("{$this->api}/banners/{$id}")->assertOk();
    expect($this->commerceRun(fn () => OnsiteBanner::query()->count()))->toBe(0);
});

it('no deja ver ni tocar avisos, banners ni reglas de otro espacio de trabajo', function () {
    $watch = commerceWatchRow($this, $this->commerceContact()->id, 'A', 'active');
    $banner = $this->commerceRun(fn () => OnsiteBanner::query()->create(['name' => 'Mío', 'audience' => 'at_risk', 'text' => 'Hola']));
    $intruder = $this->userWith2fa();
    $other = $this->workspaceFor($intruder, 'Otra tienda');
    $otherApi = '/api/w/'.$other->publicId().'/onsite-commerce';

    $this->actingAs($intruder)->getJson("{$this->api}/watches")->assertNotFound();
    $this->actingAs($intruder)->postJson("{$otherApi}/watches/{$watch->publicId()}/cancel")->assertNotFound();
    $this->actingAs($intruder)->patchJson("{$otherApi}/banners/{$banner->publicId()}", ['name' => 'Ajeno'])->assertNotFound();
    expect($this->actingAs($intruder)->getJson("{$otherApi}/watches")->json('total'))->toBe(0)
        ->and($this->actingAs($intruder)->getJson("{$otherApi}/banners")->json('data'))->toBe([])
        ->and($this->actingAs($intruder)->getJson("{$otherApi}/recommendations")->json('stats'))->toBeNull()
        ->and($this->actingAs($intruder)->getJson("{$otherApi}/recommendations/associations?site_id={$this->site->publicId()}")->json('total'))->toBe(0);
    expect($watch->fresh()->status)->toBe('active');
});
