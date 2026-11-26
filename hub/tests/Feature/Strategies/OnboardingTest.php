<?php

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * El asistente de configuración (docs/diseno/rediseno-v2.md §4): el estado vive en el servidor y los pasos hechos
 * salen de los datos reales; la bienvenida es de cada persona; el paso 5 prende las recomendadas con un POST.
 */

beforeEach(function () {
    FakeAiGateway::install();
});

function strategyOnboarding($test, $user = null): array
{
    return $test->actingAs($user ?? $test->owner)->getJson("/api/w/{$test->ws->publicId()}/onboarding")->assertOk()->json();
}

function strategyPutOnboarding($test, array $body, $user = null, int $status = 200): array
{
    return $test->actingAs($user ?? $test->owner)->putJson("/api/w/{$test->ws->publicId()}/onboarding", $body)->assertStatus($status)->json();
}

it('un espacio de trabajo nuevo empieza por el negocio, con lo demás pendiente', function () {
    $this->strategyWorld(site: false, channels: []);
    $res = strategyOnboarding($this);
    expect($res)->toMatchArray(['status' => 'not_started', 'business_type' => null, 'suggested_business_type' => 'store', 'current_step' => 'business', 'welcome_seen' => false])
        ->and(array_column($res['steps'], 'key'))->toBe(['business', 'connect', 'history', 'channels', 'strategies', 'done'])
        ->and(array_column($res['steps'], 'done'))->toBe([false, false, false, false, false, false])
        ->and($res['progress'])->toBe(['completed' => 0, 'total' => 5])
        ->and($res['workspace'])->toMatchArray(['country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota'])
        ->and(array_column($res['recommended'], 'key'))->toBe(['abandoned_cart', 'payment_pending', 'second_purchase'])
        ->and($res['recommended'][0]['steps'])->toHaveCount(3)
        ->and($res['recommended'][0]['preview']['kind'])->toBe('messages')
        ->and(array_column($res['timeline'], 'when'))->toBe(['today', 'week', 'month']);
});

it('los pasos se marcan con los datos reales y muestra lo aprendido del historial', function () {
    $this->strategyWorld();
    DB::table('orders')->insert(['id' => $order = strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'site_id' => $this->site->id, 'external_id' => '1',
        'status' => 'completed', 'is_paid' => true, 'paid_at' => now(), 'currency' => 'COP', 'total' => 282000, 'total_ws' => 282000, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'order_id' => $order, 'external_item_id' => '1',
        'external_product_id' => '88', 'name' => 'Pintura Azul 1 galón', 'qty' => 2, 'unit_price' => 141000, 'line_total' => 282000]);
    $res = strategyOnboarding($this);
    $steps = collect($res['steps'])->keyBy('key');
    expect($steps['connect']['done'])->toBeTrue()->and($steps['history']['done'])->toBeTrue()
        ->and($steps['history']['learned'])->toMatchArray(['orders' => 1, 'revenue' => '282000.00', 'top_products' => ['Pintura Azul 1 galón']])
        ->and($steps['channels'])->toMatchArray(['done' => true, 'channels' => ['whatsapp' => null, 'email' => 'active']])
        ->and($steps['strategies']['done'])->toBeFalse()
        ->and($res['current_step'])->toBe('business')
        ->and($res['progress']['completed'])->toBe(3);
});

it('guarda el tipo de negocio, el paso, saltar, retomar y terminar; la bienvenida es de cada persona', function () {
    $this->strategyWorld();
    $admin = $this->addMember($this->ws, WorkspaceRole::Admin, true);
    $res = strategyPutOnboarding($this, ['business_type' => 'services', 'current_step' => 'connect']);
    expect($res)->toMatchArray(['status' => 'in_progress', 'business_type' => 'services', 'current_step' => 'connect'])
        ->and(array_column($res['recommended'], 'key'))->toBe(['lead_response', 'appointment_reminders', 'chat_qualification']);
    // Con el negocio de servicios, los textos y el Inicio hablan de servicios.
    expect($this->actingAs($this->owner)->getJson($this->strategies('/welcome_popup'))->json('promise'))->toContain('para recibir información');

    expect(strategyPutOnboarding($this, ['action' => 'skip'])['status'])->toBe('skipped')
        ->and(strategyPutOnboarding($this, ['action' => 'resume'])['status'])->toBe('in_progress');
    $done = strategyPutOnboarding($this, ['action' => 'complete']);
    expect($done)->toMatchArray(['status' => 'completed', 'current_step' => 'done'])
        ->and($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/home")->json('setup.visible'))->toBeFalse()
        ->and(DB::table('audit_logs')->where('workspace_id', $this->ws->id)->where('action', 'onboarding.updated')->count())->toBe(4);

    strategyPutOnboarding($this, ['welcome_seen' => true]);
    expect(strategyOnboarding($this)['welcome_seen'])->toBeTrue()->and(strategyOnboarding($this, $admin)['welcome_seen'])->toBeFalse();
    strategyPutOnboarding($this, ['welcome_seen' => false]);
    expect(strategyOnboarding($this)['welcome_seen'])->toBeFalse();
});

it('el paso 5 prende las recomendadas de una vez y dice cuáles faltan por configurar', function () {
    $this->strategyWorld();
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/onboarding/strategies")->assertOk()->json();
    expect(array_column($res['results'], 'result', 'key'))->toBe(['abandoned_cart' => 'activated', 'payment_pending' => 'activated', 'second_purchase' => 'activated'])
        ->and($this->inWs(fn () => Flow::query()->where('status', 'active')->pluck('template_key')->sort()->values()->all()))->toBe(['abandoned_cart', 'payment_pending', 'second_purchase'])
        ->and(collect($res['onboarding']['steps'])->firstWhere('key', 'strategies')['done'])->toBeTrue();

    // Repetir no duplica; elegir una sola solo toca esa.
    $again = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/onboarding/strategies", ['keys' => ['abandoned_cart']])->assertOk()->json();
    expect($again['results'])->toBe([['key' => 'abandoned_cart', 'result' => 'already_active']])
        ->and($this->inWs(fn () => Flow::query()->count()))->toBe(3);
});

it('servicios sin agenda: prende lo que puede y explica lo que falta', function () {
    $this->strategyWorld();
    DB::table('sites')->where('id', $this->site->id)->update(['platform' => 'wordpress']);
    strategyPutOnboarding($this, ['business_type' => 'services']);
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/onboarding/strategies")->assertOk()->json();
    $results = collect($res['results'])->keyBy('key');
    expect($results['lead_response']['result'])->toBe('activated')
        ->and($results['appointment_reminders']['result'])->toBe('needs_setup')
        ->and($results['appointment_reminders']['requirements'][0]['key'])->toBe('booking_service')
        ->and($results['chat_qualification']['result'])->toBe('needs_setup');
});

it('permisos y aislamiento: cada quien lo suyo', function () {
    $this->strategyWorld();
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    // Marketing ve el asistente y marca su bienvenida, pero no cambia el negocio.
    strategyOnboarding($this, $marketing);
    strategyPutOnboarding($this, ['welcome_seen' => true], $marketing);
    strategyPutOnboarding($this, ['business_type' => 'both'], $marketing, 403);
    $this->actingAs($agent)->postJson("/api/w/{$this->ws->publicId()}/onboarding/strategies")->assertForbidden();
    strategyOnboarding($this, $agent);

    $mine = [$this->ws, $this->owner];
    $this->strategyWorld();
    strategyPutOnboarding($this, ['business_type' => 'services']);
    $other = $this->ws;
    [$this->ws, $this->owner] = $mine;
    expect(strategyOnboarding($this)['business_type'])->toBeNull();
    $this->actingAs($this->owner)->getJson("/api/w/{$other->publicId()}/onboarding")->assertNotFound();
});
