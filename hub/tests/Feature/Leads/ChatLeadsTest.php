<?php

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Inbox\Support\HeuristicInboundClassifier;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Messaging\Models\Conversation;
use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Ingest\Schema\SchemaRegistry;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Support\MessagingFixtures;

/*
 * Leads desde Messenger e Instagram (lote 4, M18): el mismo comportamiento que WhatsApp. Una conversación con
 * intención de compra o de contratar crea el lead con el canal como origen (`lead.created` v7) y la ficha con IA
 * (`ExtractLeadBrief`) puede crearlo cuando el clasificador no lo vio.
 */

uses(MessagingFixtures::class);

beforeEach(function () {
    $this->setUpMessaging();
    $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00:00', 'America/Bogota'));
    app()->instance(InboundClassifier::class, new HeuristicInboundClassifier);
    $this->ai = FakeAiGateway::install();
    $this->in = fn (callable $fn) => app(CurrentWorkspace::class)->run($this->ws, $fn);
    [$this->messenger, $this->instagram] = ($this->in)(fn () => [
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'messenger', 'provider' => 'meta_messenger', 'display_name' => 'Pinturas La 14',
            'status' => 'active', 'is_default' => true, 'external_id' => '5551', 'config' => ['page_id' => '5551', 'page_access_token' => 'EAAP-token']]),
        ChannelAccount::query()->create(['workspace_id' => $this->ws->id, 'channel' => 'instagram', 'provider' => 'meta_instagram', 'display_name' => '@pinturasla14',
            'status' => 'active', 'is_default' => true, 'external_id' => '1784', 'config' => ['page_id' => '5551', 'ig_user_id' => '1784', 'page_access_token' => 'EAAP-token']]),
    ]);
    Http::fake(['graph.test/*' => Http::response(['first_name' => 'Luisa', 'last_name' => 'Gómez'])]);
});

/** Mensaje de Messenger (`object = page`) o Instagram (`object = instagram`) como lo manda Meta. */
function chatLeadIn($test, string $sender, string $text, string $mid, string $owner = '5551', string $object = 'page')
{
    $body = json_encode(['object' => $object, 'entry' => [['id' => $owner, 'time' => now()->getTimestampMs(), 'messaging' => [
        ['sender' => ['id' => $sender], 'recipient' => ['id' => $owner], 'timestamp' => now()->getTimestampMs(), 'message' => ['mid' => $mid, 'text' => $text]],
    ]]]]);

    return $test->call('POST', '/webhooks/meta', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', (string) $body, 'meta-app-secret'),
    ], $body);
}

function chatLeads($test): Collection
{
    return ($test->in)(fn () => Lead::query()->orderBy('created_at')->get());
}

it('Messenger e Instagram crean el lead con intención de compra, con el canal como origen y su evento v7', function () {
    chatLeadIn($this, '9001', 'Hola, ¿cuánto cuesta la pintura azul?', 'm_1')->assertOk();
    $this->travel(1)->minutes();
    chatLeadIn($this, '7001', 'Quiero comprar 3 galones', 'ig_1', '1784', 'instagram')->assertOk();

    $leads = chatLeads($this);
    expect($leads)->toHaveCount(2)
        ->and($leads->pluck('origin')->all())->toBe(['messenger', 'instagram'])
        ->and($leads->pluck('source')->all())->toBe([['channel' => 'messenger'], ['channel' => 'instagram']])
        ->and(collect($leads->first()->score_reasons)->pluck('signal')->all())->toContain('whatsapp_click');
    $conversation = ($this->in)(fn () => Conversation::query()->where('channel_account_id', $this->messenger->id)->firstOrFail());
    expect($leads->first()->origin_ref)->toBe($conversation->id);

    $events = array_map(fn (string $p) => json_decode($p, true), leadsHubEventsFor($this, 'lead.created'));
    expect(array_column($events, 'origin'))->toBe(['messenger', 'instagram'])
        ->and(array_column($events, 'channel'))->toBe(['messenger', 'instagram']);
    // El contrato acepta los dos orígenes nuevos y nada más.
    expect(app(SchemaRegistry::class)->validateProperties('lead.created', (object) ['lead_id' => 'ld_1', 'origin' => 'messenger', 'score' => 15, 'temperature' => 'cold']))->toBeNull()
        ->and(app(SchemaRegistry::class)->validateProperties('lead.created', (object) ['lead_id' => 'ld_1', 'origin' => 'tiktok', 'score' => 15, 'temperature' => 'cold']))->not->toBeNull();

    // Otro mensaje del mismo cliente no duplica el lead.
    chatLeadIn($this, '9001', 'Quiero comprar también rodillos', 'm_2')->assertOk();
    expect(chatLeads($this))->toHaveCount(2);

    // El aviso al vendedor y el detalle dicen por dónde escribió.
    $detail = $this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/leads/{$leads->first()->publicId()}")->assertOk();
    expect($detail->json('origin'))->toBe('messenger');
});

it('no crea leads de Messenger o Instagram si se apagó, sin intención de compra ni sin el módulo de servicios', function () {
    ($this->in)(fn () => LeadSettings::for($this->ws->id)->forceFill(['whatsapp_leads' => false])->save());
    chatLeadIn($this, '9001', 'Quiero comprar pintura azul', 'm_1')->assertOk();
    expect(chatLeads($this))->toHaveCount(0);

    ($this->in)(fn () => LeadSettings::for($this->ws->id)->forceFill(['whatsapp_leads' => true])->save());
    chatLeadIn($this, '7001', 'Hola, buenas tardes', 'ig_1', '1784', 'instagram')->assertOk();
    expect(chatLeads($this))->toHaveCount(0);
});

it('la ficha con IA crea el lead de Instagram cuando el cliente quiere contratar', function () {
    $this->ai->replyJson([
        'intencion_compra' => true,
        'campos' => ['necesidad' => ['valor' => 'pintar la fachada', 'frase' => 'necesito pintar la fachada'], 'cantidad' => ['valor' => '', 'frase' => ''],
            'ciudad' => ['valor' => 'Manizales', 'frase' => 'en Manizales'], 'zona' => ['valor' => '', 'frase' => ''], 'para_cuando' => ['valor' => '', 'frase' => ''], 'presupuesto' => ['valor' => '', 'frase' => '']],
        'temperatura' => ['valor' => '', 'razon' => '', 'frase' => ''],
    ]);
    // «Necesito pintar…» no es compra para el clasificador por palabras: la ficha lo ve.
    chatLeadIn($this, '7001', 'Hola, necesito pintar la fachada de mi casa en Manizales', 'ig_1', '1784', 'instagram')->assertOk();
    $this->travel(2)->minutes();

    $lead = chatLeads($this)->sole();
    expect($lead->origin)->toBe('instagram')->and($lead->service)->toBe('pintar la fachada')
        ->and($lead->brief['city']['value'] ?? null)->toBe('Manizales');
});

/** @return list<string> */
function leadsHubEventsFor($test, string $event): array
{
    return array_map(fn (array $r) => (string) $r['p'], $test->chRows($test->ws,
        'SELECT toJSONString(properties) AS p FROM events WHERE workspace_id = {workspace_id:UInt32} AND event = {e:String} ORDER BY occurred_at',
        ['e' => $event]));
}
