<?php

use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Services\Leads\Assistant\TodayList;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Strategies\Support\StrategyFixtures;

uses(StrategyFixtures::class);

/*
 * Las páginas de entrada de las secciones (docs/diseno/rediseno-v2.md §3): `GET sections/{section}` da las cifras que
 * importan (solo números y claves; los textos son de la SPA) y lo primero que conviene hacer, según los datos, el tipo
 * de negocio y el rol. También lo nuevo del Inicio y del asistente para la primera vez (bienvenida, lo aprendido sin
 * tienda, la línea de tiempo sin web y lo que crea cada estrategia).
 */

beforeEach(function () {
    FakeAiGateway::install();
    $this->travelTo(CarbonImmutable::parse('2026-11-20 10:00', 'America/Bogota'));
});

function sectionSummary($test, string $section, $user = null): array
{
    return $test->actingAs($user ?? $test->owner)->getJson("/api/w/{$test->ws->publicId()}/sections/{$section}")->assertOk()->json();
}

function sectionContact($test, array $extra = []): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('contacts')->insert(['id' => $id, 'workspace_id' => $test->ws->id, 'created_at' => now(), 'updated_at' => now()] + $extra);

    return $id;
}

function sectionFigures(array $res): array
{
    return array_column($res['figures'], 'value', 'key');
}

it('Clientes de una tienda: personas, clientes, nuevos y en riesgo, y empieza por recuperar a los que se van', function () {
    $this->strategyWorld();
    sectionContact($this, ['orders_count' => 2, 'first_order_at' => now()->subDays(2), 'rfm_segment' => 'loyal']);
    sectionContact($this, ['orders_count' => 1, 'first_order_at' => now()->subYear(), 'rfm_segment' => 'at_risk']);
    sectionContact($this);
    $res = sectionSummary($this, 'customers');
    expect($res['business_type'])->toBe('store')
        ->and(sectionFigures($res))->toBe(['people' => 3, 'customers' => 2, 'new_customers' => 1, 'at_risk' => 1])
        ->and($res['next'])->toBe(['kind' => 'win_back', 'to' => 'estrategias/winback', 'params' => ['count' => 1]])
        ->and(collect($res['figures'])->firstWhere('key', 'people')['to'])->toBe('contactos');
});

it('Clientes sin tienda: contactos abiertos, los de hoy, citas y ganados, sin pedidos', function () {
    $this->strategyWorld();
    DB::table('sites')->where('id', $this->site->id)->update(['platform' => 'wordpress']);
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/onboarding", ['vertical' => 'health'])->assertOk();
    $contact = sectionContact($this);
    $pipeline = strtoupper((string) Str::ulid());
    DB::table('lead_pipelines')->insert(['id' => $pipeline, 'workspace_id' => $this->ws->id, 'name' => 'Pacientes', 'stages' => json_encode([['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open']]),
        'is_default' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('leads')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'pipeline_id' => $pipeline, 'contact_id' => $contact, 'stage' => 'new',
        'temperature' => 'hot', 'origin' => 'form', 'created_at' => now(), 'updated_at' => now(), 'last_activity_at' => now()]);
    $res = sectionSummary($this, 'customers');
    expect($res['business_type'])->toBe('services')
        ->and(array_keys(sectionFigures($res)))->not->toContain('customers')
        ->and(sectionFigures($res))->toMatchArray(['open_leads' => 1, 'today' => 1, 'won_month' => 0])
        ->and($res['next'])->toMatchArray(['kind' => 'attend_today', 'to' => 'hoy']);
    // Lo mismo que cuenta la lista «Hoy» (sin armar las tarjetas).
    $counts = $this->inWs(fn () => app(TodayList::class)->counts($this->ws->refresh(), null));
    expect($counts)->toBe($this->inWs(fn () => app(TodayList::class)->handle($this->ws, null)['counts']));
});

it('Mensajes, Conversaciones, En tu web, Resultados y Configuración dicen sus cifras y lo primero que conviene', function () {
    $this->strategyWorld(channels: []);
    $msg = sectionSummary($this, 'messages');
    expect(array_keys(sectionFigures($msg)))->toBe(['active_flows', 'campaigns_month', 'sent_month', 'money'])
        ->and($msg['next']['kind'])->toBe('connect_channel');

    $conv = sectionSummary($this, 'conversations');
    expect(array_keys(sectionFigures($conv)))->toBe(['waiting', 'open', 'unassigned', 'money'])->and($conv['next'])->toMatchArray(['kind' => 'connect_channel', 'to' => 'canales']);

    $web = sectionSummary($this, 'onsite');
    expect(sectionFigures($web)['active_widgets'])->toBe(0)->and($web['next'])->toMatchArray(['kind' => 'first_widget', 'to' => 'estrategias/welcome_popup']);

    $results = sectionSummary($this, 'results');
    expect(array_keys(sectionFigures($results)))->toBe(['money_month', 'sales_month', 'share', 'open_insights'])
        ->and(collect($results['figures'])->firstWhere('key', 'money_month')['unit'])->toBe('money');

    $settings = sectionSummary($this, 'settings');
    expect(sectionFigures($settings))->toBe(['sites' => 1, 'channels' => 0, 'members' => 1])->and($settings['next']['kind'])->toBe('connect_channel');
    $this->channel('email');
    expect(sectionSummary($this, 'settings')['next'])->toMatchArray(['kind' => 'invite', 'to' => 'equipo?invitar=1'])
        ->and(sectionSummary($this, 'messages')['next']['kind'])->toBe('first_automation');
});

it('sin web: cada sección empieza por conectarla', function () {
    $this->strategyWorld(site: false, channels: []);
    expect(sectionSummary($this, 'customers')['next']['kind'])->toBe('connect_site')
        ->and(sectionSummary($this, 'onsite')['next']['kind'])->toBe('connect_site')
        ->and(sectionSummary($this, 'results')['next']['kind'])->toBe('connect_site')
        ->and(sectionSummary($this, 'settings')['next'])->toMatchArray(['kind' => 'connect_site', 'to' => 'sitios?conectar=1']);
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/sections/otra")->assertNotFound();
});

it('permisos y aislamiento: cada cifra pide su permiso y nada se mezcla entre espacios', function () {
    $this->strategyWorld();
    sectionContact($this, ['orders_count' => 1]);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    // Un agente no ve los tableros: sin cifras de mensajes ni de resultados, pero sí sus conversaciones.
    expect(sectionSummary($this, 'messages', $agent)['figures'])->toBe([])
        ->and(sectionSummary($this, 'results', $agent)['next'])->toBeNull()
        ->and(sectionSummary($this, 'conversations', $agent)['figures'])->not->toBe([])
        ->and(sectionSummary($this, 'settings', $agent)['next'])->toBeNull();

    $mine = [$this->ws, $this->owner];
    $this->strategyWorld();
    $other = $this->ws;
    [$this->ws, $this->owner] = $mine;
    expect(sectionFigures(sectionSummary($this, 'customers'))['people'])->toBe(1);
    $this->actingAs($this->owner)->getJson("/api/w/{$other->publicId()}/sections/customers")->assertNotFound();
});

it('el Inicio dice si la persona ya vio la bienvenida', function () {
    $this->strategyWorld();
    $home = fn () => $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/home")->assertOk()->json('setup');
    expect($home()['welcome_seen'])->toBeFalse();
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/onboarding", ['welcome_seen' => true])->assertOk();
    expect($home()['welcome_seen'])->toBeTrue();
});

it('el asistente sin tienda aprende contactos, citas y de dónde llegan; sin web, la línea de tiempo no promete datos de la web', function () {
    $this->strategyWorld();
    DB::table('sites')->where('id', $this->site->id)->update(['platform' => 'wordpress']);
    $this->actingAs($this->owner)->putJson("/api/w/{$this->ws->publicId()}/onboarding", ['vertical' => 'professional'])->assertOk();
    $contact = sectionContact($this);
    foreach (['form', 'form', 'whatsapp'] as $origin) {
        DB::table('leads')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $contact, 'stage' => 'new',
            'origin' => $origin, 'created_at' => now(), 'updated_at' => now()]);
    }
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/onboarding")->assertOk()->json();
    $learned = collect($res['steps'])->firstWhere('key', 'history')['learned'];
    expect($learned)->toMatchArray(['contacts' => 1, 'leads' => 3, 'orders' => 0, 'top_products' => []])
        ->and($learned['sources'][0])->toBe(['origin' => 'form', 'label' => 'Formularios de tu web', 'count' => 2]);

    $this->strategyWorld(site: false, channels: []);
    $res = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/onboarding")->assertOk()->json();
    expect($res['timeline'][0]['text'])->not->toContain('tu web ya nos cuenta');
});

it('el detalle de una estrategia dice lo que va a crear', function () {
    $this->strategyWorld();
    $parts = $this->actingAs($this->owner)->getJson($this->strategies('/abandoned_cart'))->assertOk()->json('parts');
    expect($parts[0])->toMatchArray(['kind' => 'flow', 'key' => 'abandoned_cart', 'primary' => true])
        ->and($parts[0]['name'])->toStartWith('Automatización «');
    $loyalty = $this->actingAs($this->owner)->getJson($this->strategies('/loyalty_points'))->assertOk()->json('parts');
    expect($loyalty[0]['name'])->toBe('Tu programa de puntos y niveles');
});
