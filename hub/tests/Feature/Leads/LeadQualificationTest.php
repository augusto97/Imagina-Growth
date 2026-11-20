<?php

use App\Domain\Contacts\Models\Contact;
use App\Domain\Inbox\Contracts\ConversationAgent;
use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Agent\Models\AgentSettings;
use App\Domain\Intelligence\Ai\Agent\Models\AgentTurn;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Messaging\Models\Message;
use App\Domain\Messaging\Testing\FakeMessageDispatcher;
use App\Domain\Services\Leads\Jobs\QualifyLeadWithAi;
use App\Domain\Services\Leads\Jobs\RescoreLeadHistory;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadQualificationSet;
use App\Domain\Services\Leads\Models\LeadSeller;
use App\Domain\Services\Leads\Qualification\Qualification;
use App\Domain\Services\Leads\Qualification\QualificationRules;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Support\Pii\BlindIndex;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/LeadsHelpers.php';

/*
 * «Filtra a los curiosos» (auditoría §5.1 función 3): preguntas de calificación que configura
 * el dueño, que el agente hace en el chat y que se guardan en la ficha del lead con la frase
 * del cliente; puntaje, descalificación y derivación (dueño, vendedor, agente o web).
 */

beforeEach(function () {
    leadsSetUp($this);
    Queue::fake([QualifyLeadWithAi::class, RescoreLeadHistory::class]);
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $this->dispatcher = FakeMessageDispatcher::install();
    $this->api = "/api/w/{$this->ws->publicId()}/leads/qualification";
    $this->seller = $this->addMember($this->ws, WorkspaceRole::Agent);
    leadsRun($this, fn () => LeadSeller::query()->create(['workspace_id' => $this->ws->id, 'user_id' => $this->seller->id, 'active' => true, 'services' => []]));
    $this->account = leadsRun($this, fn () => ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud', 'display_name' => 'WA', 'status' => 'active', 'external_id' => '1001', 'is_default' => true]));
    $this->contact = leadsRun($this, fn () => Contact::query()->create(['workspace_id' => $this->ws->id, 'first_name' => 'Ana', 'phone' => '+573001234567',
        'phone_bidx' => BlindIndex::phone($this->ws->id, '+573001234567'), 'country' => 'CO']));
    $this->conversation = leadsRun($this, fn () => Conversation::query()->create(['workspace_id' => $this->ws->id, 'contact_id' => $this->contact->id, 'channel_account_id' => $this->account->id,
        'status' => 'open', 'ai_mode' => 'auto', 'window_expires_at' => now()->addHours(20), 'last_inbound_at' => now()]));
    leadsRun($this, fn () => AgentSettings::query()->create(['workspace_id' => $this->ws->id, 'enabled' => true, 'default_mode' => 'auto', 'max_steps' => 6,
        'policies' => [], 'handoff_rules' => AgentSettings::defaultHandoff(), 'discount_rules' => ['enabled' => false]]));
});

/** @return array<string, mixed> */
function qualificationPayload(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Pintura de fachadas',
        'services' => ['pintura'],
        'questions' => [
            ['key' => 'tipo', 'text' => '¿Es para tu casa o para una empresa?', 'type' => 'choice', 'required' => true, 'options' => [
                ['key' => 'casa', 'label' => 'Casa', 'points' => 10], ['key' => 'empresa', 'label' => 'Empresa', 'points' => 30],
            ]],
            ['key' => 'metros', 'text' => '¿Cuántos metros cuadrados son?', 'type' => 'number', 'required' => true, 'min' => 20, 'points' => 20, 'out_of_range' => 'disqualify'],
            ['key' => 'pronto', 'text' => '¿Lo necesitas este mes?', 'type' => 'yes_no', 'required' => false, 'options' => [['key' => 'yes', 'points' => 15]]],
        ],
        'routing' => [
            'rules' => [
                ['when' => 'disqualified', 'action' => 'web', 'link' => 'catalog'],
                ['when' => 'score_gte', 'value' => 50, 'action' => 'seller'],
            ],
            'default' => ['action' => 'agent'],
        ],
    ];
}

function qualificationTurn($test, string $text): AgentTurn
{
    $inbound = leadsRun($test, fn () => Message::query()->create([
        'workspace_id' => $test->ws->id, 'contact_id' => $test->contact->id, 'channel_account_id' => $test->account->id, 'channel' => 'whatsapp', 'provider' => 'meta_cloud',
        'provider_message_id' => 'wamid.'.uniqid(), 'direction' => 'in', 'kind' => 'service', 'source_type' => 'inbox', 'status' => 'received',
        'payload' => ['type' => 'text', 'text' => $text], 'conversation_id' => $test->conversation->id,
    ]));
    leadsRun($test, fn () => app(ConversationAgent::class)->onInbound($test->conversation->fresh(), $inbound));

    return leadsRun($test, fn () => AgentTurn::query()->where('inbound_message_id', $inbound->id)->firstOrFail());
}

it('el dueño configura las preguntas en Leads → Calificación; se validan y nadie más las ve ni las cambia', function () {
    $this->actingAs($this->owner)->postJson($this->api, ['name' => '', 'questions' => [['text' => '¿?', 'type' => 'choice', 'options' => [['label' => 'Sí']]]],
        'routing' => ['default' => ['action' => 'web', 'link' => 'custom']]])
        ->assertStatus(422)->assertJsonValidationErrors(['name', 'questions.0', 'routing.default']);

    $created = $this->actingAs($this->owner)->postJson($this->api, qualificationPayload())->assertCreated();
    expect($created->json('id'))->toStartWith('lqs_')
        ->and($created->json('questions.2.options'))->toBe([
            ['key' => 'yes', 'label' => 'Sí', 'points' => 15, 'disqualifies' => false],
            ['key' => 'no', 'label' => 'No', 'points' => 0, 'disqualifies' => false],
        ])
        ->and($created->json('routing.rules.0'))->toBe(['when' => 'disqualified', 'action' => 'web', 'link' => 'catalog', 'url' => null]);
    $id = $created->json('id');

    $this->actingAs($this->owner)->getJson($this->api)->assertOk()->assertJsonPath('sets.0.name', 'Pintura de fachadas')
        ->assertJsonPath('links.booking', fn ($v) => str_contains((string) $v, '/reservar'));
    // El vendedor (rol agente) ve, pero no cambia.
    $this->actingAs($this->seller)->getJson($this->api)->assertOk();
    $this->actingAs($this->seller)->postJson($this->api, qualificationPayload())->assertForbidden();
    $this->actingAs($this->seller)->putJson("{$this->api}/{$id}", qualificationPayload(['name' => 'Otro']))->assertForbidden();

    // Otro espacio de trabajo no la ve ni la toca.
    $other = $this->userWith2fa();
    $otherWs = $this->workspaceFor($other, 'Otra');
    $this->actingAs($other)->getJson("/api/w/{$otherWs->publicId()}/leads/qualification")->assertOk()->assertJsonCount(0, 'sets');
    $this->actingAs($other)->putJson("/api/w/{$otherWs->publicId()}/leads/qualification/{$id}", qualificationPayload())->assertNotFound();
    $this->actingAs($other)->deleteJson("/api/w/{$otherWs->publicId()}/leads/qualification/{$id}")->assertNotFound();

    $this->actingAs($this->owner)->putJson("{$this->api}/{$id}", qualificationPayload(['name' => 'Fachadas', 'active' => false]))->assertOk()->assertJsonPath('active', false);
    $this->actingAs($this->owner)->deleteJson("{$this->api}/{$id}")->assertOk();
    expect(leadsRun($this, fn () => LeadQualificationSet::query()->count()))->toBe(0);
});

it('el agente pregunta en el chat, guarda cada respuesta con la frase del cliente y deriva al vendedor con puntaje alto', function () {
    $this->actingAs($this->owner)->postJson($this->api, qualificationPayload())->assertCreated();
    $ai = FakeAiGateway::install()
        ->callTool('get_qualification', ['service' => 'pintura de fachada'])
        // Una respuesta inventada (la frase no está en lo que escribió) no se guarda.
        ->callTool('save_qualification_answer', ['question_key' => 'metros', 'answer' => '300', 'customer_quote' => 'son 300 metros'])
        ->callTool('save_qualification_answer', ['question_key' => 'tipo', 'answer' => 'empresa', 'customer_quote' => 'para la bodega de mi empresa'])
        ->callTool('save_qualification_answer', ['question_key' => 'metros', 'answer' => '120', 'customer_quote' => 'son unos 120 metros'])
        ->reply('Gracias, Ana. Una persona del equipo te escribe en breve con la propuesta.');
    $turn = qualificationTurn($this, 'Hola, necesito pintura de fachada para la bodega de mi empresa, son unos 120 metros');

    expect($ai->requests[0]['tools'])->toContain('get_qualification', 'save_qualification_answer')
        ->and($ai->requests[0]['request']->system)->toContain('get_qualification');
    $calls = collect($turn->tool_calls);
    expect($calls[0]['output'])->toContain('"available":true')->not->toContain('points')
        ->and($calls[1]['output'])->toContain('"saved":false')
        ->and($calls[2]['output'])->toContain('"saved":true');

    $lead = leadsRun($this, fn () => Lead::query()->where('contact_id', $this->contact->id)->sole());
    $q = $lead->qualification;
    expect($lead->origin)->toBe('whatsapp')
        ->and($q['answers']['tipo'])->toMatchArray(['value' => 'Empresa', 'option' => 'empresa', 'quote' => 'para la bodega de mi empresa', 'points' => 30, 'by' => 'ai'])
        ->and($q['answers']['metros'])->toMatchArray(['value' => '120', 'quote' => 'son unos 120 metros', 'points' => 20, 'disqualifies' => false])
        ->and($q['score'])->toBe(50)->and($q['complete'])->toBeTrue()->and($q['disqualified'])->toBeFalse()
        ->and($q['route']['action'])->toBe('seller')
        ->and(collect($lead->score_reasons)->firstWhere('signal', 'qualification')['points'])->toBe(50)
        ->and($lead->owner_id)->toBe($this->seller->id)->and($lead->qualified_at)->not->toBeNull();

    // La conversación pasa al vendedor: la IA se apaga y se despide con su respuesta.
    $conversation = $this->conversation->fresh();
    expect($turn->status)->toBe('handoff')->and($turn->handoff_reason)->toBe('qualified_seller')
        ->and($conversation->ai_mode)->toBe('off')->and($conversation->assignee_id)->toBe($this->seller->id)
        ->and(collect($this->dispatcher->dispatched)->pluck('context.text')->filter()->implode(' '))->toContain('Una persona del equipo te escribe');
    // El aviso al vendedor (lead calificado) sale aunque los avisos de «nuevo» estén apagados.
    expect(collect($this->dispatcher->dispatched)->pluck('idempotencyKey')->filter(fn ($k) => str_contains((string) $k, ':qualified:'))->all())->not->toBeEmpty();

    // La ficha del lead muestra las respuestas con la frase de origen.
    $detail = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/leads/{$lead->publicId()}")->assertOk();
    expect($detail->json('qualification.answers.0'))->toMatchArray(['key' => 'tipo', 'value' => 'Empresa', 'quote' => 'para la bodega de mi empresa'])
        ->and($detail->json('qualification.route.action'))->toBe('seller');
});

it('con una respuesta que descalifica manda a la web con el enlace, aunque el modelo no lo escriba', function () {
    $this->actingAs($this->owner)->postJson($this->api, qualificationPayload())->assertCreated();
    FakeAiGateway::install()
        ->callTool('save_qualification_answer', ['question_key' => 'metros', 'answer' => '8', 'customer_quote' => 'es una pared de 8 metros'])
        ->reply('¡Gracias! Para algo de ese tamaño te recomiendo ver nuestro catálogo.');
    $turn = qualificationTurn($this, 'Quiero pintura para mi casa, es una pared de 8 metros');

    $lead = leadsRun($this, fn () => Lead::query()->where('contact_id', $this->contact->id)->sole());
    expect($lead->qualification['disqualified'])->toBeTrue()->and($lead->qualification['route']['action'])->toBe('web')
        ->and($turn->status)->toBe('replied')->and($this->conversation->fresh()->ai_mode)->toBe('auto');
    $text = (string) end($this->dispatcher->dispatched)->context['text'];
    expect($text)->toContain('catálogo')->toContain('https://asesorias.co');
});

it('sin preguntas activas no ofrece las herramientas; la regla de respuesta y los números se revisan contra el cliente', function () {
    FakeAiGateway::install()->reply('Hola, ¿en qué te ayudo?');
    $ai = app(AiGateway::class);
    qualificationTurn($this, 'Hola');
    expect($ai->requests[0]['tools'])->not->toContain('get_qualification');

    $set = new LeadQualificationSet(QualificationRules::normalize(qualificationPayload()));
    $metros = $set->questions[1];
    expect(Qualification::check($metros, '2500000', 'el presupuesto es de 2,5 millones', ['el presupuesto es de 2,5 millones'])['ok'])->toBeTrue()
        ->and(Qualification::check($metros, '30', 'son 30 m2', ['Hola', 'son 300 m2'])['ok'])->toBeFalse()
        ->and(Qualification::numbersIn('unos 1.200 metros y 80 mil pesos'))->toBe([1200.0, 80000.0]);
    $tipo = $set->questions[0];
    expect(Qualification::check($tipo, 'oficina', 'es para la oficina', ['es para la oficina'])['ok'])->toBeFalse()
        ->and(Qualification::check($tipo, 'Casa', 'es para la casa', ['es para la casa'])['ok'])->toBeTrue();
});
