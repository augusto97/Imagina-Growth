<?php

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * Estados, potencial con datos reales, resultado del mes, permisos y aislamiento de las estrategias
 * (docs/diseno/rediseno-v2.md §6).
 */

beforeEach(function () {
    FakeAiGateway::install();
});

function strategyCatalog($test): array
{
    return $test->actingAs($test->owner)->getJson($test->strategies())->assertOk()->json();
}

function strategyStatus($test, string $key): string
{
    return (string) $test->strategyIn(strategyCatalog($test), $key)['status'];
}

/** @param list<string> $deny */
function strategyDenyModules(array $deny): void
{
    app()->instance(Entitlements::class, new class($deny) implements Entitlements
    {
        /** @param list<string> $deny */
        public function __construct(private array $deny) {}

        public function allows(Workspace $workspace, string $module): bool
        {
            return ! in_array($module, $this->deny, true);
        }

        public function limit(Workspace $workspace, string $key): ?int
        {
            return null;
        }

        public function planFor(Workspace $workspace): ?string
        {
            return 'tienda_inicio';
        }
    });
}

it('sin web conectada todo pide configurarse y enseña qué falta y dónde', function () {
    $this->strategyWorld(site: false, channels: []);
    $cart = $this->strategyIn(strategyCatalog($this), 'abandoned_cart');
    $requirements = collect($cart['requirements'])->keyBy('key');
    expect($cart['status'])->toBe('needs_setup')
        ->and($requirements['store']['met'])->toBeFalse()
        ->and($requirements['store']['to'])->toBe('sitios?conectar=1')
        ->and($requirements['store']['hint'])->toBe('Conecta tu tienda WooCommerce para activar esto.')
        ->and($requirements['channel']['met'])->toBeFalse()
        ->and($cart['potential'])->toBeNull()
        ->and($cart['name'])->toBe('Recupera carritos abandonados');
    $res = $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertStatus(422)->json();
    expect($res['error'])->toBe('needs_setup')->and(collect($res['requirements'])->pluck('key')->all())->toBe(['store', 'channel']);
});

it('fuera del plan muestra el plan que la incluye y no se activa (402)', function () {
    $this->strategyWorld();
    strategyDenyModules(['loyalty']);
    $loyalty = $this->strategyIn(strategyCatalog($this), 'loyalty_points');
    expect($loyalty['status'])->toBe('not_in_plan')
        ->and(collect($loyalty['plans'])->pluck('id')->all())->toContain('tienda_crece')
        ->and(collect($loyalty['requirements'])->firstWhere('key', 'module')['met'])->toBeFalse();
    $this->actingAs($this->owner)->postJson($this->strategies('/loyalty_points/activate'))->assertStatus(402)
        ->assertJsonPath('error', 'plan_required')->assertJsonPath('module', 'loyalty');
});

it('activa con un problema queda en atención: canal caído o plantilla rechazada por Meta', function () {
    $this->strategyWorld(channels: ['email', 'whatsapp']);
    $this->waTemplate('wa_abandoned_cart_1');
    $this->waTemplate('wa_abandoned_cart_coupon');
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    expect(strategyStatus($this, 'abandoned_cart'))->toBe('active');

    DB::table('wa_templates')->where('workspace_id', $this->ws->id)->where('name', 'wa_abandoned_cart_coupon')->update(['status' => 'REJECTED']);
    $cart = $this->strategyIn(strategyCatalog($this), 'abandoned_cart');
    expect($cart['status'])->toBe('attention')->and(collect($cart['problems'])->pluck('code')->all())->toBe(['template_rejected']);

    DB::table('wa_templates')->where('workspace_id', $this->ws->id)->update(['status' => 'APPROVED']);
    DB::table('channel_accounts')->where('workspace_id', $this->ws->id)->where('channel', 'whatsapp')->update(['status' => 'disconnected']);
    $cart = $this->strategyIn(strategyCatalog($this), 'abandoned_cart');
    expect($cart['status'])->toBe('attention')->and($cart['problems'][0]['code'])->toBe('channel_down')
        ->and($cart['problems'][0]['message'])->toContain('desconectado');
    // Activar otra vez no duplica nada: ya está prendida.
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk()->assertJsonPath('activated', false);
    expect($this->inWs(fn () => Flow::query()->where('template_key', 'abandoned_cart')->count()))->toBe(1);
});

it('un flujo de la biblioteca creado a mano también cuenta: la estrategia lo reconoce y no crea otro', function () {
    $this->strategyWorld();
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/flows/library/second_purchase", ['activate' => true])->assertCreated();
    expect(strategyStatus($this, 'second_purchase'))->toBe('active');
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/flows/".$this->inWs(fn () => Flow::query()->first())->publicId().'/pause')->assertOk();
    expect(strategyStatus($this, 'second_purchase'))->toBe('paused');
    $this->actingAs($this->owner)->postJson($this->strategies('/second_purchase/activate'))->assertOk();
    expect($this->inWs(fn () => Flow::query()->where('template_key', 'second_purchase')->where('status', 'active')->count()))->toBe(1)
        ->and($this->inWs(fn () => Flow::query()->count()))->toBe(1);
});

it('calcula el potencial con los datos reales: carritos, contraentregas y clientes de una compra', function () {
    $this->strategyWorld();
    foreach ([100000, 200000, 300000] as $i => $amount) {
        DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'c'.$i,
            'status' => 'abandoned', 'subtotal' => $amount, 'subtotal_ws' => $amount, 'abandoned_at' => now()->subDays(3), 'last_activity_at' => now()->subDays(3),
            'created_at' => now(), 'updated_at' => now()]);
    }
    // Uno viejo no cuenta.
    DB::table('carts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'token' => 'viejo',
        'status' => 'abandoned', 'subtotal' => 999000, 'subtotal_ws' => 999000, 'abandoned_at' => now()->subDays(60), 'last_activity_at' => now()->subDays(60),
        'created_at' => now(), 'updated_at' => now()]);
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => 'cod1',
        'status' => 'processing', 'is_paid' => false, 'is_cod' => true, 'currency' => 'COP', 'total' => 150000, 'total_ws' => 150000, 'created_at' => now()->subDay(), 'updated_at' => now()]);
    DB::table('contacts')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'orders_count' => 1, 'aov_ws' => 80000,
        'first_order_at' => now()->subDays(100), 'last_order_at' => now()->subDays(100), 'created_at' => now(), 'updated_at' => now()]);

    $catalog = strategyCatalog($this);
    $cart = $this->strategyIn($catalog, 'abandoned_cart')['potential'];
    expect($cart['count'])->toBe(3)->and($cart['value'])->toBe('600000.00')
        ->and($cart['rate'])->toBe(0.05)->and($cart['estimated'])->toBe('30000.00')
        ->and($cart['summary'])->toBe('En los últimos 30 días hubo 3 carritos abandonados por $600.000. Con una recuperación conservadora del 5 %, serían unos $30.000.')
        ->and($this->strategyIn($catalog, 'cod_confirm')['potential']['summary'])->toBe('1 pedido contraentrega por $150.000 espera confirmación.')
        ->and($this->strategyIn($catalog, 'second_purchase')['potential']['count'])->toBe(1)
        ->and($this->strategyIn($catalog, 'payment_pending')['potential'])->toBeNull()
        // Con contraentregas, el asistente recomienda confirmarlas en lugar del pago pendiente.
        ->and($catalog['recommended'])->toBe(['abandoned_cart', 'cod_confirm', 'second_purchase']);
});

it('el resultado del mes sale de MoneyGenerated: el dinero de su flujo, sus metas y sus mensajes', function () {
    $this->strategyWorld();
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    $flow = $this->inWs(fn () => Flow::query()->where('template_key', 'abandoned_cart')->first());
    DB::table('orders')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => 'r1',
        'status' => 'processing', 'is_paid' => true, 'paid_at' => now(), 'currency' => 'COP', 'total' => 282000, 'total_ws' => 282000,
        'attributed_flow_id' => $flow->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('messages')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'channel' => 'email', 'direction' => 'out',
        'kind' => 'marketing', 'source_type' => 'flow', 'flow_id' => $flow->id, 'status' => 'delivered', 'created_at' => now(), 'updated_at' => now()]);

    // La ingesta mueve la versión de la caché (el último evento del sitio).
    DB::table('sites')->where('id', $this->site->id)->update(['last_event_at' => now()->addMinutes(2)]);
    $cart = $this->strategyIn(strategyCatalog($this), 'abandoned_cart');
    expect($cart['result'])->toMatchArray(['revenue' => '282000.00', 'orders' => 1, 'messages' => 1, 'conversions' => ['kind' => 'goals', 'count' => 0]]);
});

it('permisos: verlas pide los tableros; activarlas, marketing y el permiso propio de cada una', function () {
    $this->strategyWorld(channels: ['email', 'whatsapp']);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);

    $this->actingAs($viewer)->getJson($this->strategies())->assertOk();
    $this->actingAs($viewer)->postJson($this->strategies('/abandoned_cart/activate'))->assertForbidden();
    $this->actingAs($agent)->getJson($this->strategies())->assertForbidden();
    $this->actingAs($marketing)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    // El agente y la bandeja también piden administrar la bandeja.
    $this->actingAs($marketing)->postJson($this->strategies('/ai_sales_agent/activate'))->assertForbidden();
    $this->actingAs($marketing)->postJson($this->strategies('/abandoned_cart/pause'))->assertOk();
    $this->actingAs($this->owner)->postJson($this->strategies('/no_existe/activate'))->assertNotFound();
    $this->actingAs($this->owner)->getJson($this->strategies('/no_existe'))->assertNotFound();
});

it('no mezcla espacios de trabajo', function () {
    $this->strategyWorld();
    $mine = $this->ws;
    $owner = $this->owner;
    // Otro negocio con su flujo activo y sus canales.
    $this->strategyWorld(channels: ['email', 'whatsapp']);
    $this->actingAs($this->owner)->postJson($this->strategies('/abandoned_cart/activate'))->assertOk();
    $other = $this->ws;

    $this->ws = $mine;
    $this->owner = $owner;
    $catalog = strategyCatalog($this);
    expect($this->strategyIn($catalog, 'abandoned_cart')['status'])->toBe('available')
        ->and($this->strategyIn($catalog, 'ai_sales_agent')['status'])->toBe('needs_setup');
    $this->actingAs($owner)->getJson("/api/w/{$other->publicId()}/strategies")->assertNotFound();
    $this->actingAs($owner)->postJson("/api/w/{$other->publicId()}/strategies/abandoned_cart/pause")->assertNotFound();
    expect(DB::table('flows')->where('workspace_id', $other->id)->value('status'))->toBe('active')
        ->and(DB::table('flows')->where('workspace_id', $mine->id)->count())->toBe(0);
});

it('«Recomendadas para ti» sigue la vertical del negocio o la que se pida, y lo de «Próximamente» no se activa', function () {
    $this->strategyWorld();
    $this->ws->forceFill(['vertical' => 'real_estate'])->save();
    $catalog = strategyCatalog($this);
    $interest = $this->strategyIn($catalog, 'property_interest');
    expect($catalog)->toMatchArray(['vertical' => 'real_estate', 'business_type' => 'services'])
        ->and($catalog['recommended'])->toBe(['service_lead', 'chat_qualification', 'appointment_reminders'])
        ->and($catalog['for_you'][0])->toBe('service_lead')
        ->and($interest)->toMatchArray(['status' => 'coming_soon', 'available' => false, 'unavailable_reason' => 'Próximamente',
            'recommended_rank' => 2, 'verticals' => ['real_estate'], 'business_types' => ['services'], 'relevant' => true])
        ->and($this->strategyIn($catalog, 'abandoned_cart'))->toMatchArray(['relevant' => false, 'recommended_rank' => null, 'verticals' => 'all', 'business_types' => ['store']])
        ->and($this->strategyIn($catalog, 'enrollment_followup')['relevant'])->toBeFalse();

    $health = $this->actingAs($this->owner)->getJson($this->strategies('?vertical=health'))->assertOk()->json();
    expect($health['vertical'])->toBe('health')
        ->and($this->strategyIn($health, 'appointment_confirmation')['recommended_rank'])->toBe(1)
        ->and($health['for_you'])->not->toContain('appointment_confirmation')
        ->and($health['recommended'][0])->toBe('appointment_reminders');
    $this->actingAs($this->owner)->getJson($this->strategies('?vertical=dentista'))->assertStatus(422);

    $res = $this->actingAs($this->owner)->postJson($this->strategies('/property_interest/activate'))->assertStatus(422)->json();
    expect($res['error'])->toBe('coming_soon')
        ->and($this->actingAs($this->owner)->postJson($this->strategies('/property_interest/pause'))->assertOk()->json('paused'))->toBeFalse()
        ->and($this->actingAs($this->owner)->getJson($this->strategies('/property_interest'))->assertOk()->json('preview'))->toBeNull();
});

it('una tienda + servicios intercala las recomendadas de la tienda y de su vertical', function () {
    $this->strategyWorld();
    $this->ws->forceFill(['vertical' => 'automotive'])->save();
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/onboarding", ['business_type' => 'both'])->assertOk();
    $catalog = strategyCatalog($this);
    expect($catalog['business_type'])->toBe('both')
        ->and($catalog['recommended'])->toBe(['abandoned_cart', 'service_lead', 'payment_pending'])
        ->and($this->strategyIn($catalog, 'periodic_service')['relevant'])->toBeTrue();
});
