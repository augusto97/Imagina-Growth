<?php

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Privacy\Actions\DeleteContactData;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Services\Leads\Brief\LeadBrief;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * «La ficha se completa sola» (M18, auditoría §5.1 función 2): la IA lee la conversación
 * en cola y guarda en el lead solo lo que el cliente escribió, sin pisar lo que editó una persona.
 */
uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    [$this->site] = $this->connectedSite($this->ws);
    // El clasificador de entrantes con IA consumiría el guion: aquí, el de palabras.
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $this->ai = FakeAiGateway::install();
    Http::fake(['graph.test/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
});

function briefInbound($test, string $text, string $id = 'wamid.B1')
{
    $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['id' => '2222', 'changes' => [['field' => 'messages', 'value' => [
        'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '1111'],
        'contacts' => [['wa_id' => '573001234567', 'profile' => ['name' => 'Ana']]],
        'messages' => [['from' => '573001234567', 'id' => $id, 'timestamp' => (string) now()->getTimestamp(), 'type' => 'text', 'text' => ['body' => $text]]],
    ]]]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'meta-app-secret'),
    ], $body);
}

/** @param array<string, array{0: string, 1: string}> $fields */
function briefReply(array $fields, array $temperature = ['', '', ''], bool $purchase = true): array
{
    $campos = [];
    foreach (['necesidad', 'cantidad', 'ciudad', 'zona', 'para_cuando', 'presupuesto'] as $k) {
        $campos[$k] = ['valor' => $fields[$k][0] ?? '', 'frase' => $fields[$k][1] ?? ''];
    }

    return ['intencion_compra' => $purchase, 'campos' => $campos, 'temperatura' => ['valor' => $temperature[0], 'razon' => $temperature[1], 'frase' => $temperature[2]]];
}

function briefLead($test): Lead
{
    return ($test->in)(fn () => Lead::query()->where('contact_id', $test->contact->id)->latest('created_at')->firstOrFail());
}

it('lee la conversación en cola, crea el lead de WhatsApp y guarda solo lo que el cliente escribió', function () {
    $this->ai->replyJson(briefReply([
        'necesidad' => ['pintura azul', 'necesito pintura azul para la fachada'],
        'cantidad' => ['3 galones', 'unos 3 galones'],
        'ciudad' => ['Bogotá', 'estoy en chapinero, bogota'],
        'zona' => ['Chapinero', ''],
        'para_cuando' => ['para el viernes', 'la necesito para el viernes'],
        'presupuesto' => ['$500.000', 'tengo $500.000'], // el cliente nunca dijo un presupuesto: se descarta
    ], ['caliente', 'Quiere la pintura esta semana', 'la necesito para el viernes']));

    briefInbound($this, 'Hola! necesito pintura azul para la fachada, unos 3 galones. Estoy en chapinero, bogota. La necesito para el viernes, ¿cuánto vale?')->assertOk();

    $lead = briefLead($this);
    expect($lead->origin)->toBe('whatsapp')->and($lead->isOpen())->toBeTrue()->and($lead->service)->toBe('pintura azul');
    $brief = $lead->brief;
    expect(LeadBrief::facts($brief))->toBe(['need' => 'pintura azul', 'quantity' => '3 galones', 'city' => 'Bogotá', 'zone' => 'Chapinero', 'timing' => 'para el viernes', 'temperature' => 'hot'])
        ->and($brief['need']['by'])->toBe('ai')
        ->and($brief['need']['quote'])->toBe('necesito pintura azul para la fachada')
        ->and($brief['zone']['quote'])->toContain('chapinero') // sin frase del modelo: la del mensaje donde aparece
        ->and($brief['temperature']['reason'])->toBe('Quiere la pintura esta semana')
        ->and($brief)->not->toHaveKey('budget');
    // La ciudad pasa al contacto, que no la tenía; la historia guarda los campos, no los valores.
    expect(($this->in)(fn () => $this->contact->fresh()->city))->toBe('Bogotá');
    $activity = ($this->in)(fn () => LeadActivity::query()->where('lead_id', $lead->id)->where('kind', 'brief')->sole());
    expect($activity->actor)->toBe('ai')->and($activity->data['discarded'])->toBe(['budget'])
        ->and(json_encode($activity->data))->not->toContain('Chapinero');
    // Va cifrada en la base: las palabras del cliente no quedan en claro.
    expect((string) DB::table('leads')->where('id', $lead->id)->value('brief'))->not->toContain('pintura');
    expect(collect($this->ai->requests)->pluck('request.feature')->all())->toBe(['lead_brief']);
    // El modelo no recibe teléfonos ni correos.
    expect(json_encode($this->ai->requests[0]['request']->messages))->not->toContain('573001234567');
});

it('nunca pisa lo que editó una persona, ni un campo que la persona vació', function () {
    briefInbound($this, '¿Cuánto cuesta la pintura azul? estoy en Bogotá')->assertOk();
    $lead = briefLead($this);
    $api = "/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}/brief";
    $this->actingAs($this->owner)->patchJson($api, ['city' => 'Medellín', 'budget' => null])->assertOk()
        ->assertJsonPath('brief.city.value', 'Medellín')->assertJsonPath('brief.city.by', 'user')
        ->assertJsonPath('brief.budget.value', null)->assertJsonPath('brief.budget.by', 'user');

    $this->ai->replyJson(briefReply(['ciudad' => ['Bogotá', 'estoy en Bogotá'], 'presupuesto' => ['2 millones', 'tengo 2 millones'], 'necesidad' => ['pintura azul', 'Cuánto cuesta la pintura azul']]));
    briefInbound($this, 'Tengo 2 millones de presupuesto', 'wamid.B2')->assertOk();
    $brief = briefLead($this)->brief;
    expect($brief['city']['value'])->toBe('Medellín')->and($brief['city']['by'])->toBe('user')
        ->and($brief['budget']['value'])->toBeNull()
        ->and($brief['need']['value'])->toBe('pintura azul')->and($brief['need']['by'])->toBe('ai');
});

it('no llama a la IA si se apagó en Ajustes ni crea leads sin intención de compra', function () {
    ($this->in)(fn () => LeadSettings::query()->create(['workspace_id' => $this->ws->id, 'ai_brief' => false]));
    briefInbound($this, 'Necesito 3 galones de pintura')->assertOk();
    expect($this->ai->requests)->toBe([]);

    ($this->in)(fn () => LeadSettings::query()->where('workspace_id', $this->ws->id)->update(['ai_brief' => true]));
    $this->ai->replyJson(briefReply([], ['fria', 'Solo saluda', 'buenas tardes'], purchase: false));
    briefInbound($this, 'buenas tardes', 'wamid.B3')->assertOk();
    expect(($this->in)(fn () => Lead::query()->count()))->toBe(0);
});

it('descarta un valor que solo dijo el negocio y una temperatura sin frase del cliente', function () {
    $conversation = ($this->in)(function () {
        $c = Conversation::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->wa->id, 'status' => 'open',
            'window_expires_at' => now()->addDay(), 'last_inbound_at' => now()]);
        foreach ([['in', 'Hola, quiero una cotización de pintura'], ['out', 'Claro, hacemos envíos a Medellín en 2 días']] as $i => [$dir, $text]) {
            Message::query()->forceCreate(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->wa->id, 'channel' => 'whatsapp',
                'provider' => 'meta_cloud', 'provider_message_id' => 'wamid.T'.$i, 'direction' => $dir, 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
                'payload' => ['text' => $text], 'conversation_id' => $c->id, 'created_at' => now()->addSeconds($i)]);
        }
        Lead::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'stage' => 'new', 'origin' => 'manual', 'score' => 0, 'temperature' => 'cold', 'score_reasons' => []]);

        return $c;
    });
    $this->ai->replyJson(briefReply(['necesidad' => ['cotización de pintura', 'quiero una cotización de pintura'], 'ciudad' => ['Medellín', 'envíos a Medellín']], ['caliente', 'Pidió 2 cotizaciones', 'quiere comprar ya']));
    $changed = ($this->in)(fn () => app(UpdateLeadBrief::class)->fromConversation($this->ws, $conversation));
    expect($changed)->toBe(['need']);
    $lead = briefLead($this);
    expect(LeadBrief::facts($lead->brief))->toBe(['need' => 'cotización de pintura']);
});

it('se ve en el panel del chat y en el detalle del lead; el rol agente solo edita sus leads; otro espacio no la ve', function () {
    $this->ai->replyJson(briefReply(['necesidad' => ['pintura azul', 'Necesito pintura azul']]));
    briefInbound($this, 'Necesito pintura azul')->assertOk();
    $lead = briefLead($this);
    $conversation = ($this->in)(fn () => Conversation::query()->where('contact_id', $this->contact->id)->firstOrFail());
    $ws = $this->ws->publicId();

    $this->actingAs($this->owner)->getJson("/api/w/{$ws}/inbox/conversations/{$conversation->publicId()}/panel")->assertOk()
        ->assertJsonPath('lead.id', $lead->publicId())->assertJsonPath('lead.brief.need.value', 'pintura azul')
        ->assertJsonPath('lead.brief.need.by', 'ai')->assertJsonPath('lead.brief.need.quote', 'Necesito pintura azul')->assertJsonPath('lead.can_open', true);
    $this->actingAs($this->owner)->getJson("/api/w/{$ws}/leads/{$lead->publicId()}")->assertOk()->assertJsonPath('brief.need.value', 'pintura azul');

    $agent = $this->addMember($this->ws, WorkspaceRole::Agent);
    $this->actingAs($agent)->patchJson("/api/w/{$ws}/leads/{$lead->publicId()}/brief", ['need' => 'otra cosa'])->assertNotFound();
    ($this->in)(fn () => $lead->forceFill(['owner_id' => $agent->id])->save());
    $this->actingAs($agent)->patchJson("/api/w/{$ws}/leads/{$lead->publicId()}/brief", ['need' => 'pintura azul mate'])->assertOk()->assertJsonPath('brief.need.by', 'user');

    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/leads/{$lead->publicId()}")->assertNotFound();
    $this->actingAs($other)->patchJson("/api/w/{$otherWs->publicId()}/leads/{$lead->publicId()}/brief", ['need' => 'x'])->assertNotFound();
});

it('valida las frases sin tildes ni mayúsculas y por palabras completas', function () {
    expect(LeadBrief::containsWords('Estoy en BOGOTÁ, Chapinero', 'bogota'))->toBeTrue()
        ->and(LeadBrief::containsWords('necesito 300 metros', '3'))->toBeFalse()
        ->and(LeadBrief::ground('3 galones', null, ['quiero 3 galones. Gracias']))->toBe(['quote' => 'quiero 3 galones.', 'index' => 0])
        ->and(LeadBrief::ground('Cali', 'en Cali', ['vivo en Bogotá']))->toBeNull();
});

it('el acceso del titular exporta la ficha y la supresión la borra con sus tareas', function () {
    $this->ai->replyJson(briefReply(['necesidad' => ['pintura azul', 'Necesito pintura azul'], 'ciudad' => ['Cali', 'vivo en Cali']]));
    briefInbound($this, 'Necesito pintura azul, vivo en Cali')->assertOk();
    $lead = briefLead($this);
    ($this->in)(fn () => LeadTask::query()->create(['workspace_id' => $this->ws->id, 'lead_id' => $lead->id, 'contact_id' => $this->contact->id,
        'kind' => 'call', 'title' => 'Llamar', 'due_at' => now(), 'status' => 'open', 'source' => 'user']));
    $export = ($this->in)(fn () => app(ExportContactData::class)->handle($this->ws, $this->contact->fresh()));
    expect($export['leads'][0]['brief'])->toBe(['need' => 'pintura azul', 'city' => 'Cali']);

    ($this->in)(fn () => app(DeleteContactData::class)->handle($this->ws, $this->contact->fresh()));
    expect(DB::table('leads')->where('workspace_id', $this->ws->id)->count())->toBe(0)
        ->and(DB::table('lead_tasks')->where('workspace_id', $this->ws->id)->count())->toBe(0);
});
