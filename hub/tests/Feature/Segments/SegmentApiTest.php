<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Actions\EvaluateContactSegments;
use App\Domain\Segments\Jobs\EvaluateContactSegmentsJob;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Ingest\Events\EventIngested;
use App\Support\Pii\BlindIndex;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->useIngestInfrastructure();
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
    $this->base = "/api/w/{$this->ws->publicId()}/segments";
    $ws = $this->ws;
    $mk = fn (array $a) => app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create($a + ['workspace_id' => $ws->id, 'lifecycle_stage' => 'customer', 'last_seen_at' => now()]));
    $this->ana = $mk(['first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana@ejemplo.com', 'email_bidx' => BlindIndex::email($ws->id, 'ana@ejemplo.com'),
        'phone' => '+573001112233', 'phone_bidx' => BlindIndex::phone($ws->id, '+573001112233'), 'city' => 'Manizales', 'orders_count' => 3, 'revenue_total_ws' => 300000]);
    $this->luis = $mk(['first_name' => 'Luis', 'city' => 'Pereira', 'orders_count' => 1, 'revenue_total_ws' => 50000]);
});

function segDefinition(string $city): array
{
    return ['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => $city]]];
}

it('cada espacio de trabajo nace con la biblioteca de segmentos del sistema', function () {
    $res = $this->actingAs($this->owner)->getJson($this->base)->assertOk();
    $keys = collect($res->json('data'))->pluck('system_key')->filter()->values();
    expect($keys)->toHaveCount(24)->toContain('rfm_champions', 'rfm_lost', 'abandoned_cart_7d', 'discount_only', 'leads_hot', 'appointment_upcoming');
    $this->actingAs($this->owner)->getJson($this->base.'/library')->assertOk()->assertJsonCount(24, 'system')->assertJsonPath('templates.0.key', 'cross_sell_categories');
    // Cada uno dice para qué negocio es: los de compras para tiendas, los de leads y citas para servicios.
    $audience = collect($res->json('data'))->pluck('audience', 'system_key');
    expect($audience['rfm_champions'])->toBe('store')->and($audience['abandoned_cart_7d'])->toBe('store')->and($audience['leads_hot'])->toBe('services');
});

it('vista previa: conteo, muestra enmascarada según el rol, canales y valor', function () {
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $body = ['definition' => segDefinition('Manizales')];
    $res = $this->actingAs($marketing)->postJson($this->base.'/preview', $body)->assertOk()
        ->assertJsonPath('count', 1)
        ->assertJsonPath('sample.0.email', 'a**@e******.com')
        ->assertJsonPath('value.revenue_ws', '300000.00')
        ->assertJsonPath('channels.whatsapp', 0);
    expect($res->json('took_ms'))->toBeInt();
    $this->actingAs($this->owner)->postJson($this->base.'/preview', $body)->assertJsonPath('sample.0.email', 'ana@ejemplo.com');

    $this->actingAs($this->owner)->postJson($this->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'inventado', 'operator' => 'eq']]]])
        ->assertUnprocessable()->assertJsonPath('errors', fn ($e) => str_contains(json_encode($e, JSON_UNESCAPED_UNICODE), 'Dato de citas desconocido'));
    $this->actingAs($this->owner)->postJson($this->base.'/preview', ['definition' => ['op' => 'and', 'conditions' => [['type' => 'loyalty', 'field' => 'points_balance', 'operator' => 'gte', 'value' => 1]]]])
        ->assertOk()->assertJsonPath('count', 0);
    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->postJson($this->base.'/preview', $body)->assertForbidden();
});

it('crea, calcula, recalcula con segment.entered/exited, y protege los del sistema', function () {
    Event::fake([EventIngested::class]);
    $viewer = $this->addMember($this->ws, WorkspaceRole::Viewer);
    $this->actingAs($viewer)->postJson($this->base, ['name' => 'X', 'definition' => segDefinition('Manizales')])->assertForbidden();

    $res = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Manizales', 'folder' => 'Geo', 'definition' => segDefinition('Manizales')])->assertCreated();
    $id = $res->json('id');
    // Cola síncrona en pruebas: ya quedó calculado (la primera vez sin eventos).
    $this->actingAs($this->owner)->getJson("{$this->base}/{$id}")->assertJsonPath('member_count', 1)->assertJsonPath('definition.conditions.0.value', 'Manizales');
    Event::assertNotDispatched(EventIngested::class);

    $this->actingAs($this->owner)->patchJson("{$this->base}/{$id}", ['definition' => segDefinition('Pereira')])->assertOk();
    Event::assertDispatched(EventIngested::class, fn (EventIngested $e) => $e->event === 'segment.entered' && $e->contactId === $this->luis->id);
    Event::assertDispatched(EventIngested::class, fn (EventIngested $e) => $e->event === 'segment.exited' && $e->contactId === $this->ana->id);
    $rows = $this->chRows($this->ws, 'SELECT event, contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} ORDER BY event');
    expect(collect($rows)->pluck('event')->all())->toBe(['segment.entered', 'segment.exited']);

    $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/members")->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'Luis');

    $system = Segment::withoutWorkspaceScope()->where('workspace_id', $this->ws->id)->where('system_key', 'rfm_champions')->firstOrFail();
    $this->actingAs($this->owner)->patchJson("{$this->base}/{$system->publicId()}", ['definition' => segDefinition('X')])->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson("{$this->base}/{$system->publicId()}")->assertUnprocessable();
    $this->actingAs($this->owner)->deleteJson("{$this->base}/{$id}")->assertOk();
    expect(DB::table('segment_memberships')->where('segment_id', substr($id, 3))->count())->toBe(0);
});

it('exporta CSV solo con permiso y lo audita', function () {
    $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Manizales', 'definition' => segDefinition('Manizales')])->json('id');
    $marketing = $this->addMember($this->ws, WorkspaceRole::Marketing);
    $this->actingAs($marketing)->get("{$this->base}/{$id}/export")->assertForbidden();

    $csv = $this->actingAs($this->owner)->get("{$this->base}/{$id}/export")->assertOk()->streamedContent();
    expect($csv)->toContain('id,nombre,apellido,correo')->toContain('ana@ejemplo.com')->toContain('+573001112233')->not->toContain('Luis');
    expect(DB::table('audit_logs')->where('action', 'segment.exported')->count())->toBe(1);
});

it('crea estáticos desde correos o teléfonos y como foto de un dinámico', function () {
    $res = $this->actingAs($this->owner)->postJson($this->base.'/static', [
        'name' => 'Lista feria', 'values' => ['ANA@ejemplo.com', '300 111 2233', 'nadie@x.co', 'no-sirve', ''],
    ])->assertCreated();
    expect($res->json('matched'))->toBe(1)->and($res->json('unmatched'))->toBe(3)->and($res->json('segment.kind'))->toBe('static')->and($res->json('segment.member_count'))->toBe(1);

    $dyn = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Pereira', 'definition' => segDefinition('Pereira')])->json('id');
    $snap = $this->actingAs($this->owner)->postJson("{$this->base}/{$dyn}/snapshot", ['name' => 'Pereira hoy'])->assertCreated();
    expect($snap->json('kind'))->toBe('static')->and($snap->json('member_count'))->toBe(1);
    $this->actingAs($this->owner)->patchJson("{$this->base}/{$snap->json('id')}", ['definition' => segDefinition('X')])->assertUnprocessable();
});

it('crea desde una plantilla de la biblioteca', function () {
    $res = $this->actingAs($this->owner)->postJson($this->base.'/library/cross_sell_categories', ['params' => ['bought' => ['12'], 'never' => ['15']]])->assertCreated();
    expect($res->json('definition.conditions.1'))->toMatchArray(['type' => 'purchase', 'scope' => 'category', 'ids' => ['15'], 'operator' => 'never']);
});

it('reinstala desde la biblioteca un segmento del sistema que falte', function () {
    app(CurrentWorkspace::class)->run($this->ws, fn () => Segment::query()->where('system_key', 'rfm_lost')->forceDelete());
    $res = $this->actingAs($this->owner)->postJson($this->base.'/library/rfm_lost')->assertCreated();
    expect($res->json('system_key'))->toBe('rfm_lost')->and($res->json('is_system'))->toBeTrue();
    $this->actingAs($this->owner)->getJson($this->base.'/library')->assertOk()->assertJsonPath('system.10.segment_id', $res->json('id'));
});

it('no permite que un segmento dependa de sí mismo', function () {
    $a = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Base A', 'definition' => segDefinition('Manizales')])->json('id');
    $b = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Depende de A', 'definition' => ['op' => 'and', 'conditions' => [['type' => 'segment', 'segment_id' => $a, 'operator' => 'in']]]])->assertCreated()->json('id');
    $this->actingAs($this->owner)->patchJson("{$this->base}/{$a}", ['definition' => ['op' => 'and', 'conditions' => [['type' => 'segment', 'segment_id' => $b, 'operator' => 'in']]]])
        ->assertUnprocessable()->assertJsonValidationErrors('definition.conditions.0.segment_id');
});

it('en tiempo real: product.viewed mete al contacto en el segmento y emite segment.entered', function () {
    $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Vio el 88', 'definition' => ['op' => 'and', 'conditions' => [
        ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'eq', 'value' => '88']], 'operator' => 'ever'],
    ]]])->json('id');
    $this->actingAs($this->owner)->getJson("{$this->base}/{$id}")->assertJsonPath('member_count', 0);

    DB::table('contact_identities')->insert(['id' => strtoupper((string) str()->ulid()), 'workspace_id' => $this->ws->id, 'contact_id' => $this->ana->id,
        'type' => 'email_hash', 'value' => BlindIndex::email($this->ws->id, 'ana@ejemplo.com')]);

    // Llega el evento por el pipeline real. En producción el job espera 15 s (el evento ya está en
    // ClickHouse); la cola síncrona de las pruebas ignora la espera, así que se corre después del worker.
    Queue::fake([EvaluateContactSegmentsJob::class]);
    [$site, $secret] = $this->connectedSite($this->ws);
    $this->postSigned($site, $secret, [$this->envelope($site, 'product.viewed', ['product_id' => '88', 'name' => 'Pintura', 'price' => '1.00', 'currency' => 'COP'], [
        'identity' => ['email' => 'ana@ejemplo.com'],
    ])])->assertJsonPath('accepted', 1);
    $this->runWorker();
    Queue::assertPushed(EvaluateContactSegmentsJob::class, fn (EvaluateContactSegmentsJob $job) => $job->contactId === $this->ana->id && $job->delay !== null);
    Queue::pushed(EvaluateContactSegmentsJob::class)->each(
        fn (EvaluateContactSegmentsJob $job) => app(CurrentWorkspace::class)->run($this->ws, fn () => $job->handle(app(EvaluateContactSegments::class))),
    );

    $this->actingAs($this->owner)->getJson("{$this->base}/{$id}")->assertJsonPath('member_count', 1);
    $this->actingAs($this->owner)->getJson("{$this->base}/{$id}/members")->assertJsonPath('data.0.name', 'Ana Ruiz');
    $events = collect($this->chRows($this->ws, "SELECT event, contact_id FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'segment.entered'"));
    expect($events)->toHaveCount(1)->and($events->first()['contact_id'])->toBe($this->ana->id);
});

it('la exportación CSV neutraliza las fórmulas (S10)', function () {
    $ws = $this->ws;
    app(CurrentWorkspace::class)->run($ws, fn () => Contact::query()->create([
        'workspace_id' => $ws->id, 'lifecycle_stage' => 'customer', 'last_seen_at' => now(), 'city' => 'Manizales',
        'first_name' => '=HYPERLINK("https://malo.example","clic")', 'last_name' => '@SUM(1)',
    ]));
    $id = $this->actingAs($this->owner)->postJson($this->base, ['name' => 'Manizales', 'definition' => segDefinition('Manizales')])->json('id');

    $csv = $this->actingAs($this->owner)->get("{$this->base}/{$id}/export")->assertOk()->streamedContent();

    expect($csv)->toContain("'=HYPERLINK")->toContain("'@SUM(1)")->not->toMatch('/,=HYPERLINK/');
});
