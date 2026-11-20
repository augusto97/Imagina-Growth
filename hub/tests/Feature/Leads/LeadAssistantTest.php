<?php

use App\Domain\Flows\Actions\ChangeFlowStatus;
use App\Domain\Flows\Actions\EnrollContacts;
use App\Domain\Flows\Actions\PublishFlow;
use App\Domain\Flows\Actions\SaveFlow;
use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowRunStep;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Flows\Support\FlowFixtures;
use Tests\Feature\Flows\Support\PolicyDispatcherStub;

/*
 * «Hoy», el asistente del vendedor (M18, auditoría §5.1 función 6), y los nodos de flujo
 * que lo alimentan: crear tarea, asignar a una persona, mover etapa y el seguimiento con IA.
 */
uses(FlowFixtures::class);

beforeEach(function () {
    $this->flowWorld();
    // Martes 17 de noviembre de 2026, 10:00 en Bogotá: horario legal.
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    $this->seller = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->wa = ($this->in)(fn () => ChannelAccount::query()->where('channel', 'whatsapp')->firstOrFail());
    $this->today = "/api/w/{$this->ws->publicId()}/leads/today";
});

/** @param array<string, mixed> $attrs */
function assistLead($test, array $attrs = [], $contact = null): Lead
{
    $contact ??= $test->contact;
    $createdAt = $attrs['created_at'] ?? null;
    unset($attrs['created_at']);
    $lead = ($test->in)(fn () => Lead::query()->create($attrs + [
        'workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'stage' => 'new', 'stage_changed_at' => now(), 'origin' => 'form', 'service' => 'Pintura de fachada',
        'score' => 30, 'temperature' => 'warm', 'score_reasons' => [], 'last_activity_at' => now(),
    ]));
    if ($createdAt !== null) {
        ($test->in)(fn () => Lead::query()->whereKey($lead->id)->update(['created_at' => $createdAt]));
    }

    return ($test->in)(fn () => $lead->fresh());
}

function assistConversation($test, $contact, bool $open = true, bool $awaiting = true): Conversation
{
    return ($test->in)(function () use ($test, $contact, $open, $awaiting) {
        $c = Conversation::query()->create(['workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id, 'status' => 'open',
            'window_expires_at' => $open ? now()->addHours(20) : now()->subHours(3), 'last_inbound_at' => $open ? now()->subHours(4) : now()->subDays(2),
            'last_message_at' => now()->subHours(4), 'awaiting_since' => $awaiting ? now()->subHours(4) : null, 'opened_at' => now()->subHours(4)]);
        Message::query()->forceCreate(['workspace_id' => $test->ws->id, 'contact_id' => $contact->id, 'channel_account_id' => $test->wa->id, 'channel' => 'whatsapp',
            'provider' => 'meta_cloud', 'provider_message_id' => 'wamid.'.Str::random(8), 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
            'payload' => ['text' => '¿Tienen disponibilidad para pintar 120 m² el sábado?'], 'conversation_id' => $c->id, 'created_at' => now()->subHours(4)]);

        return $c;
    });
}

function assistFlow($test, array $nodes, array $edges): Flow
{
    return ($test->in)(function () use ($nodes, $edges) {
        $graph = ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ...$nodes], 'edges' => $edges];
        $flow = app(SaveFlow::class)->handle(null, ['name' => 'Leads', 'trigger' => ['type' => 'manual'], 'reentry_policy' => ['mode' => 'always'], 'graph' => $graph])['flow'];
        app(PublishFlow::class)->handle($flow);
        app(ChangeFlowStatus::class)->handle($flow->refresh(), Flow::ACTIVE);

        return $flow->refresh();
    });
}

function assistRun($test, Flow $flow, $contact = null): FlowRun
{
    $contact ??= $test->contact;
    ($test->in)(fn () => app(EnrollContacts::class)->handle($flow, [$contact->id]));
    $test->artisan('igr:flows:tick')->assertSuccessful();

    return ($test->in)(fn () => FlowRun::query()->where('flow_id', $flow->id)->where('contact_id', $contact->id)->latest('created_at')->firstOrFail());
}

it('arma la lista del día: respuesta pendiente, caliente sin contactar, tarea, próximo paso y lead quieto', function () {
    $mine = assistLead($this, ['owner_id' => $this->seller->id]);
    assistConversation($this, $this->contact);
    $hotContact = $this->flowContact('luis@empresa.co', '+573005550001');
    $hot = assistLead($this, ['temperature' => 'hot', 'score' => 70], $hotContact);
    $nextContact = $this->flowContact('marta@empresa.co', '+573005550002');
    $next = assistLead($this, ['next_step' => 'Enviar propuesta', 'next_step_at' => now()->subDay(), 'owner_id' => $this->seller->id], $nextContact);
    $staleContact = $this->flowContact('pedro@empresa.co', '+573005550003');
    $stale = assistLead($this, ['stage' => 'proposal', 'stage_changed_at' => now()->subDays(9), 'last_activity_at' => now()->subDays(9)], $staleContact);
    $calmContact = $this->flowContact('sara@empresa.co', '+573005550004');
    assistLead($this, ['stage_changed_at' => now()->subDays(2)], $calmContact); // no sale: lleva 2 días
    $task = ($this->in)(fn () => LeadTask::query()->create(['workspace_id' => $this->ws->id, 'lead_id' => $stale->id, 'contact_id' => $staleContact->id, 'owner_id' => $this->seller->id,
        'kind' => 'call', 'title' => 'Llamar para cerrar', 'due_at' => now()->addHours(2), 'status' => 'open', 'source' => 'user']));

    $all = $this->actingAs($this->owner)->getJson($this->today)->assertOk();
    expect(collect($all->json('items'))->pluck('kind')->all())->toBe(['reply', 'first_contact', 'task', 'next_step', 'stale'])
        ->and($all->json('counts'))->toMatchArray(['reply' => 1, 'first_contact' => 1, 'task' => 1, 'next_step' => 1, 'stale' => 1])
        ->and($all->json('items.0.lead.id'))->toBe($mine->publicId())
        ->and($all->json('items.1.lead.id'))->toBe($hot->publicId())
        ->and($all->json('items.2.task.id'))->toBe($task->publicId())
        ->and($all->json('items.3.lead.id'))->toBe($next->publicId())->and($all->json('items.3.overdue'))->toBeTrue()
        ->and($all->json('items.4.days'))->toBe(9)->and($all->json('can_see_all'))->toBeTrue();

    // El vendedor (rol agente) ve solo lo suyo, aunque pida ver todo.
    $own = $this->actingAs($this->seller)->getJson($this->today.'?owner=all')->assertOk();
    expect(collect($own->json('items'))->pluck('kind')->all())->toBe(['reply', 'task', 'next_step'])->and($own->json('owner'))->toBe('me');
    // Y no toca los de otros.
    $this->actingAs($this->seller)->postJson($this->today.'/done', ['key' => 'hot:'.$hot->publicId()])->assertNotFound();

    // Otro espacio de trabajo no ve nada ni puede marcar.
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/leads/today")->assertOk()->assertJsonCount(0, 'items');
    $this->actingAs($other)->postJson("/api/w/{$otherWs->publicId()}/leads/today/done", ['key' => 'hot:'.$hot->publicId()])->assertNotFound();
});

it('marcar hecho lo saca hasta que la situación cambie; posponer lo devuelve en su fecha', function () {
    $stale = assistLead($this, ['stage' => 'proposal', 'stage_changed_at' => now()->subDays(9), 'last_activity_at' => now()->subDays(9)]);
    $key = $this->actingAs($this->owner)->getJson($this->today)->json('items.0.key');
    $this->actingAs($this->owner)->postJson($this->today.'/snooze', ['key' => $key, 'days' => 1])->assertOk();
    $this->actingAs($this->owner)->getJson($this->today)->assertJsonCount(0, 'items');
    $this->travelTo(CarbonImmutable::parse('2026-11-18 08:05:00', 'America/Bogota'));
    $this->actingAs($this->owner)->getJson($this->today)->assertJsonCount(1, 'items');

    $this->actingAs($this->owner)->postJson($this->today.'/done', ['key' => $key])->assertOk();
    $this->actingAs($this->owner)->getJson($this->today)->assertJsonCount(0, 'items');
    // Otro cambio de etapa es otra situación: si vuelve a quedarse quieto, vuelve a salir.
    ($this->in)(fn () => Lead::query()->whereKey($stale->id)->update(['stage' => 'qualified', 'stage_changed_at' => now()->subDays(8)]));
    $this->actingAs($this->owner)->getJson($this->today)->assertJsonCount(1, 'items');
});

it('sugiere el mensaje con IA y nunca deja pasar una cifra ni un enlace que no está en los hechos', function () {
    $lead = assistLead($this, ['brief' => ['quantity' => ['value' => '120 m²', 'quote' => 'pintar 120 m²', 'by' => 'ai']]]);
    assistConversation($this, $this->contact);
    $key = $this->actingAs($this->owner)->getJson($this->today)->json('items.0.key');
    $ai = FakeAiGateway::install();
    // Dos veces con un descuento inventado: la IA no lo puede citar, sale el texto sin IA.
    $ai->replyJson(['mensaje' => 'Hola Ana, para tus 120 m² te damos 20% de descuento.'])->replyJson(['mensaje' => 'Hola Ana, te damos 20% de descuento.']);
    $res = $this->actingAs($this->owner)->postJson($this->today.'/suggest', ['key' => $key])->assertOk();
    expect($res->json('source'))->toBe('fallback')->and($res->json('text'))->not->toContain('20%')
        ->and($res->json('reach.mode'))->toBe('text')->and($res->json('reach.channel'))->toBe('whatsapp');

    $ai->replyJson(['mensaje' => 'Hola Ana, para pintar los 120 m² el sábado tenemos cuadrilla disponible. Mira las fachadas que hicimos: https://inventado.example/fachadas ¿Te confirmo la hora?']);
    ($this->in)(fn () => Lead::query()->whereKey($lead->id)->update(['updated_at' => now()->addSecond()]));
    $res = $this->actingAs($this->owner)->postJson($this->today.'/suggest', ['key' => $key])->assertOk();
    expect($res->json('source'))->toBe('ai')->and($res->json('text'))->toContain('120 m²')->and($res->json('text'))->not->toContain('inventado.example');
    $facts = end($ai->requests)['request']->messages[0]['content'];
    expect(end($ai->requests)['request']->feature)->toBe('lead_followup')->and($facts)->toContain('Pintura de fachada')
        ->and($facts)->toContain('120 m²')->and($facts)->not->toContain('573001234567');
});

it('envía con un clic: dentro de la ventana como texto en la conversación; afuera solo con la plantilla de marketing', function () {
    $fake = FakeMessageDispatcher::install();
    $lead = assistLead($this);
    $conversation = assistConversation($this, $this->contact);
    $key = $this->actingAs($this->owner)->getJson($this->today)->json('items.0.key');
    $body = ['key' => $key, 'text' => 'Sí, el sábado tenemos cuadrilla para los 120 m².', 'client_id' => (string) Str::uuid()];
    $this->actingAs($this->owner)->postJson($this->today.'/send', $body)->assertCreated()->assertJsonPath('mode', 'text');
    $this->actingAs($this->owner)->postJson($this->today.'/send', $body)->assertOk(); // el doble clic no reenvía
    expect($fake->dispatched)->toHaveCount(1)->and($fake->dispatched[0]->kind->value)->toBe('service')
        ->and($fake->dispatched[0]->sourceType)->toBe('inbox')->and($fake->dispatched[0]->context['text'])->toBe($body['text']);
    expect(($this->in)(fn () => $conversation->fresh()->awaiting_since))->toBeNull();
    $this->actingAs($this->owner)->getJson($this->today)->assertJsonCount(0, 'items');
    expect(($this->in)(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'message')->where('data->from', 'today')->count()))->toBe(1);

    // Sin conversación abierta: la plantilla de seguimiento (marketing) con el texto como variable.
    $other = $this->flowContact('luis@empresa.co', '+573005550001');
    $hot = assistLead($this, ['temperature' => 'hot'], $other);
    $this->actingAs($this->owner)->postJson($this->today.'/send', ['key' => 'hot:'.$hot->publicId(), 'text' => 'Hola Luis, ¿te cuento las opciones?', 'client_id' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('mode', 'template');
    $sent = end($fake->dispatched);
    expect($sent->kind->value)->toBe('marketing')->and($sent->templateId)->not->toBeNull()
        ->and($sent->context['seguimiento']['mensaje'])->toBe('Hola Luis, ¿te cuento las opciones?');
});

it('nada sale sin consentimiento ni fuera del horario legal', function () {
    PolicyDispatcherStub::install();
    $noConsent = $this->flowContact('nadie@empresa.co', '+573005550009', []);
    $lead = assistLead($this, ['temperature' => 'hot'], $noConsent);
    $this->actingAs($this->owner)->postJson($this->today.'/send', ['key' => 'hot:'.$lead->publicId(), 'text' => 'Hola', 'client_id' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('reason', 'no_consent');

    // Domingo: el marketing se aplaza al lunes y el ítem sigue en la lista.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 11:00:00', 'America/Bogota'));
    $hot = assistLead($this, ['temperature' => 'hot']);
    $this->actingAs($this->owner)->postJson($this->today.'/send', ['key' => 'hot:'.$hot->publicId(), 'text' => 'Hola Ana', 'client_id' => (string) Str::uuid()])
        ->assertStatus(409)->assertJsonPath('status', 'deferred');
    expect(collect($this->actingAs($this->owner)->getJson($this->today)->json('items'))->pluck('lead.id'))->toContain($hot->publicId());
});

it('tareas: se crean en el lead, salen en «Hoy» y se completan desde ahí', function () {
    $lead = assistLead($this, ['owner_id' => $this->seller->id]);
    $api = "/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}/tasks";
    $task = $this->actingAs($this->seller)->postJson($api, ['title' => 'Llamar a Ana', 'kind' => 'call', 'due_at' => now()->addHour()->toIso8601String(), 'note' => 'Vive en Chía'])
        ->assertCreated()->json();
    expect($task['owner_id'])->toBe($this->seller->publicId());
    expect((string) DB::table('lead_tasks')->value('note'))->not->toContain('Chía');
    // El agente no asigna tareas a otra persona.
    $this->actingAs($this->seller)->postJson($api, ['title' => 'x', 'due_at' => now()->toIso8601String(), 'owner_id' => $this->owner->publicId()])->assertForbidden();

    $items = $this->actingAs($this->seller)->getJson($this->today)->assertOk()->json('items');
    expect($items[0]['kind'])->toBe('task')->and($items[0]['task']['title'])->toBe('Llamar a Ana');
    $this->actingAs($this->seller)->postJson($this->today.'/done', ['key' => $items[0]['key']])->assertOk();
    expect(($this->in)(fn () => LeadTask::query()->sole()->status))->toBe('done');
    $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}")->assertOk()->assertJsonPath('tasks.0.status', 'done');
});

it('nodo «Crear tarea»: la deja al vendedor del lead una sola vez y no la crea para el grupo de control', function () {
    $lead = assistLead($this, ['owner_id' => $this->seller->id]);
    $flow = assistFlow($this, [['id' => 't', 'type' => 'crm_task', 'config' => ['title' => 'Llamar al lead', 'kind' => 'call', 'due_in' => ['value' => 1, 'unit' => 'days']]]], [['from' => 'trigger', 'to' => 't']]);
    $run = assistRun($this, $flow);
    $task = ($this->in)(fn () => LeadTask::query()->sole());
    expect($task->owner_id)->toBe($this->seller->id)->and($task->lead_id)->toBe($lead->id)->and($task->kind)->toBe('call')
        ->and($task->source)->toBe('flow')->and($task->due_at->toIso8601String())->toBe(now()->addDay()->toIso8601String())
        ->and($run->status)->toBe('completed');
    // Un reintento del paso no la duplica.
    ($this->in)(fn () => FlowRunStep::query()->where('run_id', $run->id)->delete());
    ($this->in)(fn () => FlowRun::query()->whereKey($run->id)->update(['status' => 'active', 'current_node_id' => 't', 'next_run_at' => now()]));
    $this->artisan('igr:flows:tick')->assertSuccessful();
    expect(($this->in)(fn () => LeadTask::query()->count()))->toBe(1);

    $holdout = $this->flowContact('control@empresa.co', '+573005550010');
    assistLead($this, [], $holdout);
    ($this->in)(fn () => $flow->forceFill(['holdout_pct' => 100])->save());
    $controlRun = assistRun($this, $flow, $holdout);
    expect($controlRun->is_holdout)->toBeTrue()->and(($this->in)(fn () => LeadTask::query()->count()))->toBe(1);
});

it('nodo «Asignar a una persona»: la conversación y el lead quedan con quien se eligió', function () {
    $lead = assistLead($this);
    $conversation = assistConversation($this, $this->contact, awaiting: false);
    $flow = assistFlow($this, [['id' => 'a', 'type' => 'assign_agent', 'config' => ['mode' => 'user', 'user_id' => $this->seller->publicId()]]], [['from' => 'trigger', 'to' => 'a']]);
    assistRun($this, $flow);
    expect(($this->in)(fn () => $conversation->fresh()->assignee_id))->toBe($this->seller->id)
        ->and(($this->in)(fn () => $lead->fresh()->owner_id))->toBe($this->seller->id)
        ->and(($this->in)(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'owner')->count()))->toBe(1);
});

it('nodo «Mover etapa del lead»: lo mueve con la acción del panel y sigue por «falló» si no hay lead', function () {
    $lead = assistLead($this);
    $flow = assistFlow($this, [['id' => 's', 'type' => 'lead_stage', 'config' => ['stage' => 'qualified']]], [['from' => 'trigger', 'to' => 's']]);
    assistRun($this, $flow);
    $lead = ($this->in)(fn () => $lead->fresh());
    expect($lead->stage)->toBe('qualified')->and($lead->stage_changed_at)->not->toBeNull()
        ->and(($this->in)(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'stage')->value('data')))->toMatchArray(['from' => 'new', 'to' => 'qualified']);

    $nobody = $this->flowContact('sin@lead.co', null);
    $run = assistRun($this, $flow, $nobody);
    $step = ($this->in)(fn () => FlowRunStep::query()->where('run_id', $run->id)->where('node_id', 's')->sole());
    expect($step->outcome)->toBe('failed')->and($step->details['reason'])->toBe('no_open_lead');
});

it('valida los nodos: etapa existente, persona del equipo y una tarea con título', function () {
    $errors = fn (array $node) => ($this->in)(fn () => app(GraphValidator::class)->validate(
        Graph::fromArray(['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], $node], 'edges' => [['from' => 'trigger', 'to' => $node['id']]]]), $this->ws->id));
    expect($errors(['id' => 's', 'type' => 'lead_stage', 'config' => ['stage' => 'no_existe']])['s'])->toContain('Elige una etapa de tus pipelines de leads.')
        ->and($errors(['id' => 's', 'type' => 'lead_stage', 'config' => ['stage' => 'proposal']]))->toBe([])
        ->and($errors(['id' => 't', 'type' => 'crm_task', 'config' => ['title' => '']])['t'])->toContain('Escribe la tarea (máximo 160 caracteres).')
        ->and($errors(['id' => 't', 'type' => 'crm_task', 'config' => ['title' => 'Llamar', 'due_in' => ['value' => 120, 'unit' => 'days']]])['t'])->toContain('La tarea debe vencer entre 1 minuto y 90 días después.')
        ->and($errors(['id' => 'a', 'type' => 'assign_agent', 'config' => ['mode' => 'auto']]))->toBe([])
        ->and($errors(['id' => 'f', 'type' => 'lead_followup', 'config' => ['channels' => ['sms']]])['f'])->toContain('Elige por qué canales sale el seguimiento: WhatsApp, correo o los dos.');
});

it('seguimiento con IA: texto dentro de la ventana, tarea en «Hoy» si no hay consentimiento y aplazado en domingo con el mismo texto', function () {
    $stub = PolicyDispatcherStub::install();
    $ai = FakeAiGateway::install();
    $lead = assistLead($this, ['owner_id' => $this->seller->id]);
    assistConversation($this, $this->contact, awaiting: false);
    $flow = assistFlow($this, [['id' => 'f', 'type' => 'lead_followup', 'config' => ['channels' => ['whatsapp', 'email'], 'step' => 1]]], [['from' => 'trigger', 'to' => 'f']]);
    $ai->replyJson(['mensaje' => 'Hola Ana, para la fachada te propongo una visita de medición sin costo esta semana. ¿Qué día te queda mejor?']);
    assistRun($this, $flow);
    $sent = array_values($stub->sent);
    expect($sent)->toHaveCount(1)->and($sent[0]['message']->channel->value)->toBe('whatsapp')->and($sent[0]['message']->kind->value)->toBe('marketing')
        ->and($sent[0]['message']->templateId)->toBeNull()->and($sent[0]['message']->context['text'])->toContain('visita de medición');

    // Sin consentimiento en ningún canal: no sale nada y el vendedor lo ve en «Hoy».
    $noConsent = $this->flowContact('nadie@empresa.co', '+573005550009', []);
    $other = assistLead($this, ['owner_id' => $this->seller->id], $noConsent);
    $run = assistRun($this, $flow, $noConsent);
    expect(array_values($stub->sent))->toHaveCount(1);
    $task = ($this->in)(fn () => LeadTask::query()->where('lead_id', $other->id)->sole());
    expect($task->owner_id)->toBe($this->seller->id)->and($task->source)->toBe('flow');
    expect(collect($this->actingAs($this->seller)->getJson($this->today)->json('items'))->pluck('task.id'))->toContain($task->publicId());

    // Domingo: se aplaza al lunes y sale con el mismo texto, sin volver a llamar a la IA.
    $this->travelTo(CarbonImmutable::parse('2026-11-22 10:00:00', 'America/Bogota'));
    $sunday = $this->flowContact('domingo@empresa.co', '+573005550011');
    assistLead($this, [], $sunday);
    $ai->replyJson(['mensaje' => 'Hola Ana, te escribo por la pintura de fachada. ¿Te ayudo con la medición?']);
    $run = assistRun($this, $flow, $sunday);
    expect($run->status)->toBe('waiting')->and(array_values($stub->sent))->toHaveCount(1);
    $calls = count($ai->requests);
    $this->travelTo(CarbonImmutable::parse('2026-11-23 07:05:00', 'America/Bogota'));
    $this->artisan('igr:flows:tick')->assertSuccessful();
    $sent = array_values($stub->sent);
    // Fuera de la ventana de 24 h: la plantilla de marketing con el texto que ya había escrito la IA.
    expect($sent)->toHaveCount(2)->and(count($ai->requests))->toBe($calls)
        ->and($sent[1]['message']->templateId)->not->toBeNull()
        ->and($sent[1]['message']->context['seguimiento']['mensaje'])->toContain('medición');
});
