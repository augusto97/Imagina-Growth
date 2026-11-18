<?php

use App\Domain\Agency\Models\AgencyTemplate;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Models\Flow;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Segments\Library\SegmentLibrary;
use App\Domain\Segments\Models\Segment;
use App\Domain\Templates\Models\Template;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Agency\AgencyWorld;

/** En el cliente A: plantilla de correo, segmento propio (y otro que depende de él), flujo, campaña y widget. */
function agencySeedSource(AgencyWorld $w): array
{
    app(SegmentLibrary::class)->install($w->a);
    app(SegmentLibrary::class)->install($w->b);

    return app(CurrentWorkspace::class)->run($w->a, function () use ($w): array {
        $tpl = Template::query()->create(['workspace_id' => $w->a->id, 'channel' => 'email', 'name' => 'Bienvenida', 'category' => 'marketing',
            'subject' => 'Hola {{nombre}}', 'body_html' => '<p>Bienvenida a la tienda</p>', 'status' => 'ready']);
        $city = Segment::query()->create(['workspace_id' => $w->a->id, 'name' => 'Compradores de Manizales', 'kind' => 'dynamic', 'is_system' => false,
            'definition' => ['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Manizales']]]]);
        $nested = Segment::query()->create(['workspace_id' => $w->a->id, 'name' => 'Manizales VIP', 'kind' => 'dynamic', 'is_system' => false,
            'definition' => ['op' => 'and', 'conditions' => [['type' => 'segment', 'segment_id' => $city->publicId(), 'operator' => 'in'],
                ['type' => 'attribute', 'field' => 'email', 'operator' => 'eq', 'value' => 'carla@cliente.co']]]]);
        $static = Segment::query()->create(['workspace_id' => $w->a->id, 'name' => 'Lista VIP', 'kind' => 'static', 'is_system' => false]);
        $system = Segment::query()->where('system_key', 'rfm_at_risk')->firstOrFail();

        $flow = app(SaveFlow::class)->handle(null, [
            'name' => 'Bienvenida Manizales',
            'trigger' => ['type' => 'segment', 'segment_id' => $nested->publicId(), 'on' => 'entered'],
            'graph' => ['nodes' => [
                ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
                ['id' => 'mail', 'type' => 'send_email', 'config' => ['template_id' => 'tpl_'.$tpl->id]],
                ['id' => 'w', 'type' => 'wait', 'config' => ['duration' => ['value' => 2, 'unit' => 'days']]],
                ['id' => 'other', 'type' => 'tag', 'config' => ['add' => ['vip'], 'flow_id' => 'fl_'.$tpl->id]],
            ], 'edges' => [['from' => 'trigger', 'to' => 'mail'], ['from' => 'mail', 'to' => 'w'], ['from' => 'w', 'to' => 'other']]],
        ], $w->clientOwnerA->id)['flow'];
        $flow->forceFill(['test_recipients' => ['email' => ['carla@cliente.co']]])->save();

        $campaign = Campaign::query()->create(['workspace_id' => $w->a->id, 'name' => 'Black Friday', 'channel' => 'email', 'status' => 'scheduled',
            'audience' => ['segments_include' => [$city->publicId(), $system->publicId()], 'segments_exclude' => [$static->publicId()], 'all_contacts' => false, 'rules_include' => [], 'rules_exclude' => []],
            'template_id' => $tpl->id, 'send_mode' => 'scheduled', 'send_at' => now()->addDays(5)]);
        $widget = OnsiteWidget::query()->create(['workspace_id' => $w->a->id, 'site_id' => strtoupper((string) Str::ulid()), 'name' => 'Popup 10%', 'kind' => 'popup', 'status' => 'active',
            'targeting' => ['segments' => [$city->publicId()], 'pages' => ['/tienda']], 'content' => ['title' => '10% en tu primera compra']]);

        return compact('tpl', 'city', 'nested', 'static', 'system', 'flow', 'campaign', 'widget');
    });
}

function agencyIn(Workspace $ws, Closure $fn): mixed
{
    return app(CurrentWorkspace::class)->run($ws, $fn);
}

beforeEach(function () {
    $this->w = AgencyWorld::make($this);
    $this->src = agencySeedSource($this->w);
});

it('guarda un flujo en la biblioteca sin IDs del cliente ni datos personales', function () {
    $w = $this->w;
    $res = $this->actingAs($w->member)->postJson($w->api($w->a, 'agency/templates'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'flow', 'id' => $this->src['flow']->publicId(), 'description' => 'Para tiendas nuevas',
    ])->assertCreated();
    expect($res->json('template.includes'))->toBe(['templates' => 1, 'segments' => 2]);

    $tpl = AgencyTemplate::query()->firstOrFail();
    $json = json_encode($tpl->payload);
    expect($tpl->account_id)->toBe($w->agency->id)
        ->and($json)->not->toContain($w->a->id)
        ->and($json)->not->toContain($this->src['tpl']->id)
        ->and($json)->not->toContain($this->src['city']->id)
        ->and($json)->not->toContain($this->src['nested']->id)
        ->and($json)->not->toContain('carla@cliente.co')
        ->and($tpl->payload['warnings'])->not->toBeEmpty();

    $list = $this->actingAs($w->owner)->getJson($w->api($w->b, 'agency/templates?kind=flow'))->assertOk();
    expect($list->json('templates.0.source_workspace'))->toBe('Pinturas La 14');
    // La otra agencia no la ve.
    $this->actingAs($w->foreignOwner)->getJson($w->api($w->c, 'agency/templates'))->assertOk()->assertJsonCount(0, 'templates');
    $this->actingAs($w->foreignOwner)->getJson($w->api($w->c, 'agency/templates/'.$tpl->publicId()))->assertNotFound();
});

it('aplica la plantilla a otro cliente: flujo en borrador con plantilla y segmentos remapeados, sin duplicar', function () {
    $w = $this->w;
    $id = $this->actingAs($w->member)->postJson($w->api($w->a, 'agency/templates'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'flow', 'id' => $this->src['flow']->publicId(),
    ])->json('template.id');

    $res = $this->actingAs($w->member)->postJson($w->api($w->a, "agency/templates/{$id}/apply"), ['workspaces' => [$w->b->publicId()]])->assertOk();
    expect($res->json('results.0.ok'))->toBeTrue()->and($res->json('results.0.kind'))->toBe('flow');

    agencyIn($w->b, function () use ($w) {
        $flow = Flow::query()->firstOrFail();
        $graph = $flow->draftVersion()?->graph;
        $tpl = Template::query()->firstOrFail();
        $nested = Segment::query()->where('name', 'Manizales VIP')->firstOrFail();
        $city = Segment::query()->where('name', 'Compradores de Manizales')->firstOrFail();
        expect($flow->status)->toBe('draft')
            ->and($flow->workspace_id)->toBe($w->b->id)
            ->and($flow->test_recipients)->toBeNull()
            ->and($graph['nodes'][1]['config']['template_id'])->toBe('tpl_'.$tpl->id)
            ->and(array_key_exists('flow_id', $graph['nodes'][3]['config']) ? $graph['nodes'][3]['config']['flow_id'] : null)->toBeNull()
            ->and($tpl->subject)->toBe('Hola {{nombre}}')
            ->and($flow->trigger['segment_id'])->toBe($nested->publicId())
            ->and($nested->definition['conditions'][0]['segment_id'])->toBe($city->publicId())
            ->and(json_encode($nested->definition))->not->toContain('carla@cliente.co');
    });

    // Otra vez: un segundo flujo, pero reutiliza la plantilla y los segmentos idénticos.
    $this->actingAs($w->member)->postJson($w->api($w->a, "agency/templates/{$id}/apply"), ['workspaces' => [$w->b->publicId()], 'name' => 'Copia 2'])->assertOk();
    agencyIn($w->b, function () {
        expect(Flow::query()->count())->toBe(2)
            ->and(Flow::query()->where('name', 'Copia 2')->exists())->toBeTrue()
            ->and(Template::query()->count())->toBe(1)
            ->and(Segment::query()->where('is_system', false)->count())->toBe(2);
    });
    expect(DB::table('audit_logs')->where('action', 'agency.template_applied')->where('workspace_id', $w->b->id)->count())->toBe(2);
});

it('copia campañas como borrador y widgets sin sitio, con la audiencia remapeada', function () {
    $w = $this->w;
    $res = $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/copy'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'campaign', 'id' => $this->src['campaign']->publicId(), 'workspaces' => [$w->b->publicId()],
    ])->assertOk();
    expect($res->json('results.0.ok'))->toBeTrue()->and($res->json('results.0.warnings'))->toBeArray();
    agencyIn($w->b, function () {
        $c = Campaign::query()->firstOrFail();
        $system = Segment::query()->where('system_key', 'rfm_at_risk')->firstOrFail();
        $city = Segment::query()->where('name', 'Compradores de Manizales')->firstOrFail();
        expect($c->status)->toBe('draft')->and($c->send_at)->toBeNull()->and($c->send_mode)->toBe('now')
            ->and($c->audience['segments_include'])->toBe([$city->publicId(), $system->publicId()])
            ->and($c->audience['segments_exclude'])->toBe([]) // el estático no existe en el destino
            ->and($c->template_id)->toBe(Template::query()->value('id'));
    });

    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/copy'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'widget', 'id' => $this->src['widget']->publicId(), 'workspaces' => [$w->b->publicId()],
    ])->assertOk()->assertJsonPath('results.0.ok', true);
    agencyIn($w->b, function () {
        $widget = OnsiteWidget::query()->firstOrFail();
        expect($widget->status)->toBe('draft')->and($widget->site_id)->toBeNull()
            ->and($widget->targeting['segments'])->toBe([Segment::query()->where('name', 'Compradores de Manizales')->firstOrFail()->publicId()])
            ->and($widget->targeting['pages'])->toBe(['/tienda']);
    });
});

it('no copia segmentos estáticos ni entre agencias distintas', function () {
    $w = $this->w;
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/templates'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'segment', 'id' => $this->src['static']->publicId(),
    ])->assertUnprocessable();
    // Quien no es de la otra agencia no llega a su cliente.
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/copy'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'flow', 'id' => $this->src['flow']->publicId(), 'workspaces' => [$w->c->publicId()],
    ])->assertNotFound();
    // Aunque sea de las dos agencias, no se cruza información entre cuentas.
    $w->foreign->users()->attach($w->owner->id, ['role' => 'agency_member']);
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/copy'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'flow', 'id' => $this->src['flow']->publicId(), 'workspaces' => [$w->c->publicId()],
    ])->assertUnprocessable();
    agencyIn($w->c, fn () => expect(Flow::query()->count())->toBe(0));
    // Un ID de otro cliente como origen no se encuentra (el exportador busca dentro del cliente de origen).
    $this->actingAs($w->owner)->postJson($w->api($w->a, 'agency/templates'), [
        'source_workspace' => $w->b->publicId(), 'kind' => 'flow', 'id' => $this->src['flow']->publicId(),
    ])->assertNotFound();
});

it('solo el dueño de la agencia o quien la creó borra una plantilla', function () {
    $w = $this->w;
    $other = $this->userWith2fa();
    $w->agency->users()->attach($other->id, ['role' => 'agency_member']);
    $id = $this->actingAs($w->member)->postJson($w->api($w->a, 'agency/templates'), [
        'source_workspace' => $w->a->publicId(), 'kind' => 'segment', 'id' => $this->src['city']->publicId(),
    ])->assertCreated()->json('template.id');

    $this->actingAs($other->refresh())->deleteJson($w->api($w->a, "agency/templates/{$id}"))->assertForbidden();
    $this->actingAs($w->owner)->deleteJson($w->api($w->a, "agency/templates/{$id}"))->assertOk();
    expect(AgencyTemplate::query()->count())->toBe(0);
});
