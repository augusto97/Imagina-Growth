<?php

use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Engine\FlowEngine;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Ingest\HubEvents;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Flows\Support\FlowFixtures;
use Tests\Feature\Flows\Support\PolicyDispatcherStub;

uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    $this->fakeCoupons();
});

/** Crea, publica y activa un flujo con este grafo. */
function flowActive($test, array $graph, array $settings = []): Flow
{
    return app(CurrentWorkspace::class)->run($test->ws, function () use ($graph, $settings) {
        $flow = app(SaveFlow::class)->handle(null, $settings + ['name' => 'Prueba', 'trigger' => ['type' => 'manual'], 'reentry_policy' => ['mode' => 'always'], 'graph' => $graph])['flow'];
        app(PublishFlow::class)->handle($flow);
        app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ACTIVE);

        return $flow->refresh();
    });
}

function flowGraph(array $nodes, array $edges): array
{
    return ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ...$nodes], 'edges' => $edges];
}

function flowTick($test): void
{
    $test->artisan('igr:flows:tick')->assertSuccessful();
}

/** Corre dentro del contexto del espacio de trabajo de la prueba. */
function flowInWs($test, callable $fn): mixed
{
    return app(CurrentWorkspace::class)->run($test->ws, $fn);
}

function flowBogota(string $at): CarbonImmutable
{
    return CarbonImmutable::parse($at, 'America/Bogota');
}

it('carrito abandonado de punta a punta: WhatsApp a la hora, correo a las 4 h, WhatsApp con cupón a las 24 h y sale al comprar', function () {
    $this->travelTo(flowBogota('2026-11-17 09:00:00'));
    $fake = FakeMessageDispatcher::install();
    $created = app(CurrentWorkspace::class)->run($this->ws, fn () => app(CreateFlowFromLibrary::class)->handle('abandoned_cart', true));
    expect($created['activated'])->toBeTrue()->and($created['issues'])->toBe([]);

    // El job de carritos emite cart.abandoned: el TriggerRouter lo hace entrar.
    app(HubEvents::class)->emit($this->ws, 'cart.abandoned', $this->cartProps(), $this->contact->id, $this->site);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->sole());
    expect($run->status)->toBe('waiting')->and($run->current_node_id)->toBe('w1')->and($fake->dispatched)->toBe([]);
    expect($run->context['props'])->not->toHaveKey('email'); // sin datos personales en el run

    $this->travel(61)->minutes();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(1)
        ->and($fake->dispatched[0]->channel->value)->toBe('whatsapp')
        ->and($fake->dispatched[0]->kind->value)->toBe('marketing')
        ->and($fake->dispatched[0]->idempotencyKey)->toBe("run:{$run->id}:wa1:1")
        ->and($fake->dispatched[0]->context['carrito']['items'][0]['nombre'])->toBe('Pintura Azul 1 galón');

    $this->travel(3)->hours();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(2)->and($fake->dispatched[1]->channel->value)->toBe('email');

    $this->travel(20)->hours();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(3)
        ->and($fake->dispatched[2]->context['cupon']['codigo'])->toStartWith('VUELVE-')
        ->and($fake->dispatched[2]->context['cupon']['descuento'])->toBe('10%');
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh()->status))->toBe('completed');

    // Otro carrito, y esta vez compra antes del segundo mensaje: sale con la meta.
    DB::table('flow_runs')->update(['started_at' => now()->subDays(8)]); // fuera de la reentrada de 7 días
    app(HubEvents::class)->emit($this->ws, 'cart.abandoned', ['cart_token' => 'c_otro_carrito'] + $this->cartProps(), $this->contact->id, $this->site);
    $second = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->where('id', '!=', $run->id)->sole());
    $this->travel(61)->minutes();
    flowTick($this);
    $this->flowEvent('order.paid', ['order' => $this->orderSnapshot(['order_id' => '1234'])]);
    $second = app(CurrentWorkspace::class)->run($this->ws, fn () => $second->refresh());
    expect($second->status)->toBe('completed')->and($second->exit_reason)->toBe('goal_reached')->and($second->context['_goal']['event'])->toBe('order.paid');
    $this->travel(5)->hours();
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(4); // nada después de la compra

    $exits = $this->chRows($this->ws, "SELECT JSONExtractString(toJSONString(properties), 'reason') AS reason FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = 'flow.exited' ORDER BY occurred_at");
    expect(array_column($exits, 'reason'))->toBe(['completed', 'goal_reached']);
});

it('ningún paso de marketing sale fuera de la ventana legal: un envío que toca el domingo a las 10:00 sale el lunes a las 7:00', function () {
    $stub = PolicyDispatcherStub::install();
    // Sábado 21 de noviembre de 2026 a las 10:00: espera 1 día → domingo 10:00.
    $this->travelTo(flowBogota('2026-11-21 10:00:00'));
    $flow = flowActive($this, flowGraph([
        ['id' => 'w', 'type' => 'wait', 'config' => ['duration' => ['value' => 1, 'unit' => 'days']]],
        ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_birthday']],
    ], [['from' => 'trigger', 'to' => 'w'], ['from' => 'w', 'to' => 'wa']]));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));

    $this->travelTo(flowBogota('2026-11-22 10:00:30'));
    flowTick($this);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->sole());
    expect($stub->sent)->toBe([])
        ->and($run->status)->toBe('waiting')
        ->and($run->next_run_at->setTimezone('America/Bogota')->format('Y-m-d H:i'))->toBe('2026-11-23 07:00');
    $step = flowInWs($this, fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 'wa')->sole());
    expect($step->outcome)->toBe('deferred')->and($step->details['reason'])->toBe('sunday');

    // Aunque algo lo despierte antes (un reintento, un tick adelantado), la política lo vuelve a aplazar.
    flowInWs($this, fn () => app(FlowEngine::class)->advance($run->id));
    $this->travelTo(flowBogota('2026-11-23 06:59:00'));
    flowInWs($this, fn () => app(FlowEngine::class)->advance($run->id));
    expect($stub->sent)->toBe([]);

    $this->travelTo(flowBogota('2026-11-23 07:00:10'));
    flowTick($this);
    expect($stub->sentAtBogota())->toBe(['2026-11-23 07:00']);
    expect(flowInWs($this, fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 'wa')->value('outcome')))->toBe('sent');
});

it('festivos y sábados tarde: nunca sale un comercial en festivo ni después de las 15:00 del sábado', function (string $start, string $expected) {
    $stub = PolicyDispatcherStub::install();
    $this->travelTo(flowBogota($start));
    $flow = flowActive($this, flowGraph([['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_birthday']]], [['from' => 'trigger', 'to' => 'wa']]));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    // El scheduler pasa cada minuto durante 3 días: solo sale en la primera hora permitida.
    for ($i = 0; $i < 72 * 4; $i++) {
        $this->travel(15)->minutes();
        flowTick($this);
    }
    // El scheduler pasa cada 15 minutos en la prueba: sale en el primer tick desde la apertura.
    $sent = $stub->sentAtBogota();
    $open = flowBogota($expected.':00');
    expect($sent)->toHaveCount(1);
    $at = flowBogota($sent[0].':00');
    expect($at->greaterThanOrEqualTo($open) && $at->lessThan($open->addMinutes(15)))->toBeTrue();
})->with([
    'Inmaculada (martes 8 de diciembre)' => ['2026-12-08 09:00:00', '2026-12-09 07:00'],
    'sábado a las 15:01' => ['2026-11-21 15:01:00', '2026-11-23 07:00'],
    'Reyes trasladado (lunes 11 de enero de 2027)' => ['2027-01-10 18:00:00', '2027-01-12 07:00'],
]);

it('un paso nunca envía dos veces: ni por reintentos del job, ni por una caída después de encolar, ni por disparadores repetidos', function () {
    $stub = PolicyDispatcherStub::install();
    $stub->crashAfterQueue = 1;
    $this->travelTo(flowBogota('2026-11-17 10:00:00'));
    $flow = flowActive($this, flowGraph([
        ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_abandoned_cart_1']],
        ['id' => 'em', 'type' => 'send_email', 'config' => ['template_key' => 'email_abandoned_cart_1', 'kind' => 'transactional', 'reclassify_reason' => 'Confirmación de una acción del cliente']],
    ], [['from' => 'trigger', 'to' => 'wa'], ['from' => 'wa', 'to' => 'em']]), ['trigger' => ['type' => 'event', 'event' => 'cart.abandoned', 'filters' => []], 'reentry_policy' => ['mode' => 'always']]);

    // El mismo evento llega dos veces (reintento de la ingesta): un solo run.
    $props = $this->cartProps();
    $eventId = '0193a7b2-6c1e-7f3a-9a51-2f6d1c0e4b7a';
    try {
        $this->flowEvent('cart.abandoned', $props, eventId: $eventId);
    } catch (RuntimeException) {
        // la caída simulada después de encolar el WhatsApp
    }
    $this->flowEvent('cart.abandoned', $props, eventId: $eventId);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->sole());

    // El job se reintenta: el WhatsApp ya estaba en cola → duplicate, no se reenvía; sigue al correo.
    flowInWs($this, fn () => app(FlowEngine::class)->advance($run->id));
    flowInWs($this, fn () => app(FlowEngine::class)->advance($run->id));
    flowTick($this);
    expect(array_keys($stub->sent))->toBe(["run:{$run->id}:wa:1", "run:{$run->id}:em:1"])
        ->and(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh()->status))->toBe('completed');
    expect(flowInWs($this, fn () => FlowRunStep::query()->where('run_id', $run->id)->pluck('outcome', 'node_id')->all()))->toEqual(['wa' => 'sent', 'em' => 'sent']);
});

it('el grupo de control recorre el flujo sin recibir mensajes ni cupones', function () {
    $fake = FakeMessageDispatcher::install();
    $flow = flowActive($this, flowGraph([
        ['id' => 'c', 'type' => 'coupon', 'config' => ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 3]],
        ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_birthday']],
    ], [['from' => 'trigger', 'to' => 'c'], ['from' => 'c', 'to' => 'wa']]));
    flowInWs($this, fn () => $flow->forceFill(['holdout_pct' => 100])->save());
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->sole());
    expect($run->is_holdout)->toBeTrue()->and($run->status)->toBe('completed')->and($fake->dispatched)->toBe([])
        ->and(flowInWs($this, fn () => FlowRunStep::query()->where('run_id', $run->id)->pluck('outcome', 'node_id')->all()))->toBe(['c' => 'skipped_holdout', 'wa' => 'skipped_holdout'])
        ->and(DB::table('coupons_issued')->count())->toBe(0);
});

it('versionado: los runs en curso siguen en su versión; los nuevos entran con la publicada y migrar mueve los compatibles', function () {
    FakeMessageDispatcher::install();
    $graph = flowGraph([
        ['id' => 'w', 'type' => 'wait', 'config' => ['duration' => ['value' => 2, 'unit' => 'hours']]],
        ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_birthday']],
    ], [['from' => 'trigger', 'to' => 'w'], ['from' => 'w', 'to' => 'wa']]);
    $flow = flowActive($this, $graph);
    $other = $this->flowContact('luis@ejemplo.com', '+573009998877');
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    $v1 = $flow->current_version_id;

    $graph['nodes'][2]['config']['template_key'] = 'wa_replenishment';
    app(CurrentWorkspace::class)->run($this->ws, function () use ($flow, $graph) {
        app(SaveFlow::class)->handle($flow, ['graph' => $graph]);
        app(PublishFlow::class)->handle($flow->refresh());
    });
    $v2 = $flow->refresh()->current_version_id;
    expect($v2)->not->toBe($v1);
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$other->id]));
    $runs = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->pluck('flow_version_id', 'contact_id')->all());
    expect($runs[$this->contact->id])->toBe($v1)->and($runs[$other->id])->toBe($v2);

    app(CurrentWorkspace::class)->run($this->ws, function () use ($flow, $graph) {
        app(SaveFlow::class)->handle($flow, ['graph' => $graph]);
        app(PublishFlow::class)->handle($flow->refresh(), migrate: true);
    });
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->where('contact_id', $this->contact->id)->value('flow_version_id')))->toBe($flow->refresh()->current_version_id);
});

it('confirmar contraentrega: espera la respuesta por botón y sigue por la rama del evento o avisa al dueño a las 12 h', function () {
    $fake = FakeMessageDispatcher::install();
    $this->travelTo(flowBogota('2026-11-17 10:00:00'));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(CreateFlowFromLibrary::class)->handle('cod_confirm', true));
    $cod = $this->orderSnapshot(['payment_method' => ['id' => 'cod', 'title' => 'Contraentrega']]);
    $this->flowEvent('order.created', ['order' => $cod]);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->sole());
    expect($run->status)->toBe('waiting')->and($run->waiting_for)->toBe('message.replied')
        ->and($fake->dispatched[0]->kind->value)->toBe('utility');

    // Una respuesta que no es de este botón no lo despierta; «Confirmo» sí.
    app(HubEvents::class)->emit($this->ws, 'message.replied', ['message_id' => 'msg_01JD', 'channel' => 'whatsapp', 'kind' => 'utility', 'source_type' => 'flow', 'button_payload' => 'otra_cosa'], $this->contact->id);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh()->status))->toBe('waiting');
    app(HubEvents::class)->emit($this->ws, 'message.replied', ['message_id' => 'msg_01JD', 'channel' => 'whatsapp', 'kind' => 'utility', 'source_type' => 'flow', 'button_payload' => 'cod_confirm'], $this->contact->id);
    $run = app(CurrentWorkspace::class)->run($this->ws, fn () => $run->refresh());
    expect($run->status)->toBe('completed')->and($this->contact->refresh()->getAttribute('tags'))->toContain('contraentrega_confirmada');

    // Otro pedido sin respuesta: a las 12 h avisa a los dueños.
    $this->flowEvent('order.created', ['order' => $cod]);
    $this->travel(12 * 60 + 2)->minutes();
    flowTick($this);
    $alerts = array_values(array_filter($fake->dispatched, fn (OutboundMessage $m) => $m->to !== null));
    expect($alerts)->toHaveCount(1)->and($alerts[0]->to)->toBe($this->owner->email)->and($alerts[0]->context['aviso']['texto'])->toContain('12 horas');
});

it('la reentrada, la exclusión mutua y el filtro de entrada deciden quién entra', function () {
    FakeMessageDispatcher::install();
    $wait = flowGraph([['id' => 'w', 'type' => 'wait', 'config' => ['duration' => ['value' => 1, 'unit' => 'days']]]], [['from' => 'trigger', 'to' => 'w']]);
    $once = flowActive($this, $wait, ['reentry_policy' => ['mode' => 'once']]);
    $enroll = fn (Flow $f) => app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($f, [$this->contact->id]));
    expect($enroll($once))->toBe(1)->and($enroll($once))->toBe(0); // vivo: nunca dos a la vez
    DB::table('flow_runs')->update(['status' => 'completed']);
    expect($enroll($once))->toBe(0); // «una vez»

    $excluding = flowActive($this, $wait, ['reentry_policy' => ['mode' => 'always']]);
    flowInWs($this, fn () => $excluding->forceFill(['exclusions' => [$once->id]])->save());
    DB::table('flow_runs')->update(['status' => 'waiting']);
    expect($enroll($excluding))->toBe(0);
    DB::table('flow_runs')->update(['status' => 'completed']);
    expect($enroll($excluding))->toBe(1);

    // Con exit_if_no, salir por la condición no cuenta para la reentrada.
    $filtered = flowActive($this, flowGraph([
        ['id' => 'c', 'type' => 'condition', 'config' => ['mode' => 'segment', 'exit_if_no' => true, 'definition' => ['op' => 'and', 'conditions' => [['type' => 'attribute', 'field' => 'city', 'operator' => 'eq', 'value' => 'Manizales']]]]],
        ['id' => 't', 'type' => 'tag', 'config' => ['add' => ['manizales']]],
    ], [['from' => 'trigger', 'to' => 'c'], ['from' => 'c', 'to' => 't', 'branch' => 'yes']]), ['reentry_policy' => ['mode' => 'once']]);
    expect($enroll($filtered))->toBe(1);
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->where('flow_id', $filtered->id)->value('exit_reason')))->toBe('filter');
    flowInWs($this, fn () => $this->contact->forceFill(['city' => 'Manizales'])->save());
    expect($enroll($filtered))->toBe(1)->and($this->contact->refresh()->getAttribute('tags'))->toContain('manizales');
});

it('pausar deja los runs donde van y archivar los termina', function () {
    $fake = FakeMessageDispatcher::install();
    $flow = flowActive($this, flowGraph([
        ['id' => 'w', 'type' => 'wait', 'config' => ['duration' => ['value' => 1, 'unit' => 'hours']]],
        ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_birthday']],
    ], [['from' => 'trigger', 'to' => 'w'], ['from' => 'w', 'to' => 'wa']]));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeFlowStatus::class)->handle($flow, Flow::PAUSED));
    $this->travel(2)->hours();
    flowTick($this);
    expect($fake->dispatched)->toBe([]);
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ACTIVE));
    flowTick($this);
    expect($fake->dispatched)->toHaveCount(1);

    app(CurrentWorkspace::class)->run($this->ws, fn () => app(EnrollContacts::class)->handle($flow, [$this->contact->id]));
    app(CurrentWorkspace::class)->run($this->ws, fn () => app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ARCHIVED));
    expect(app(CurrentWorkspace::class)->run($this->ws, fn () => FlowRun::query()->whereIn('status', FlowRun::LIVE)->count()))->toBe(0);
});
