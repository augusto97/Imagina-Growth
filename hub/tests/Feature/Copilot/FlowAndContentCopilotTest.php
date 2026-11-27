<?php

use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Copilot\Support\WhatsAppTemplateRules;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use App\Domain\Templates\Models\Template;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Campaigns\CampaignFixtures;

uses(CampaignFixtures::class);

beforeEach(function () {
    $this->campaignWorkspace();
});

/** Flujo de recuperación como lo devuelve el modelo. */
function copilotFlowProposal(array $graph = [], array $overrides = []): array
{
    $graph = $graph ?: [
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger', 'config' => []],
            ['id' => 'w1', 'type' => 'wait', 'config' => ['mode' => 'duration', 'duration' => ['value' => 1, 'unit' => 'days']]],
            ['id' => 'split', 'type' => 'channel_split', 'config' => ['channels' => ['whatsapp', 'email']]],
            ['id' => 'wa', 'type' => 'send_whatsapp', 'config' => ['template_key' => 'wa_winback_coupon', 'kind' => 'marketing']],
            ['id' => 'em', 'type' => 'send_email', 'config' => ['template_key' => 'winback_1', 'kind' => 'marketing']],
        ],
        'edges' => [['from' => 'trigger', 'to' => 'w1'], ['from' => 'w1', 'to' => 'split'], ['from' => 'split', 'to' => 'wa', 'branch' => 'whatsapp'], ['from' => 'split', 'to' => 'em', 'branch' => 'email']],
    ];

    return $overrides + [
        'name' => 'Recuperar a quienes no compran',
        'description' => 'Clientes en riesgo: WhatsApp o correo un día después.',
        'trigger_json' => json_encode(['type' => 'segment', 'system_key' => 'rfm_at_risk', 'on' => 'entered']),
        'goal_event' => 'order.paid', 'reentry_mode' => 'every_days', 'reentry_days' => 90,
        'graph_json' => json_encode($graph),
    ];
}

it('propone un flujo válido y aplicarlo crea un borrador (nunca activo) con las plantillas de correo instaladas', function () {
    FakeAiGateway::install()->replyJson(copilotFlowProposal());
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/flows", ['prompt' => 'Un flujo para recuperar clientes que no compran hace 60 días con WhatsApp y correo'])->assertCreated();
    $p = $res->json('proposal');
    expect($p['trigger'])->toMatchArray(['type' => 'segment', 'on' => 'entered'])->and($p['goal']['event'])->toBe('order.paid')
        ->and($p['pending_whatsapp'])->toBe(['wa'])->and(implode(' ', $p['warnings']))->toContain('plantilla aprobada de WhatsApp')
        ->and(array_column($p['steps'], 'label'))->toContain('Espera 1 día')
        ->and($p['graph']['nodes'][1]['position'])->toHaveKeys(['x', 'y']);

    $applied = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/drafts/{$res->json('id')}/apply")->assertOk();
    $this->inWs(function () use ($applied) {
        $flow = Flow::findByPublicId($applied->json('applied.0.id'));
        expect($flow->status)->toBe(Flow::DRAFT)->and($flow->name)->toBe('Recuperar a quienes no compran')
            ->and(Template::query()->where('library_key', 'winback_1')->exists())->toBeTrue();
    });
    expect($applied->json('applied.0.issues'))->toHaveKey('wa');
});

it('un grafo inválido se corrige una vez; si sigue inválido, error claro', function () {
    $loop = ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => []], ['id' => 'a', 'type' => 'tag', 'config' => ['add' => ['x']]], ['id' => 'b', 'type' => 'tag', 'config' => ['add' => ['y']]]],
        'edges' => [['from' => 'trigger', 'to' => 'a'], ['from' => 'a', 'to' => 'b'], ['from' => 'b', 'to' => 'a']]];
    $ai = FakeAiGateway::install()->replyJson(copilotFlowProposal($loop))->replyJson(copilotFlowProposal());
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/flows", ['prompt' => 'Un flujo para clientes en riesgo con WhatsApp y correo'])->assertCreated();
    expect($ai->requests[1]['request']->messages[2]['content'])->toContain('ciclo');

    FakeAiGateway::install()->replyJson(copilotFlowProposal($loop, ['trigger_json' => '{"type":"telepatia"}']))->replyJson(copilotFlowProposal($loop));
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/flows", ['prompt' => 'Un flujo para clientes en riesgo con WhatsApp y correo'])
        ->assertUnprocessable()->assertJsonPath('message', fn ($m) => str_contains($m, 'flujo válido'));
    expect($this->inWs(fn () => Flow::query()->count()))->toBe(0);
});

it('asuntos y variantes con el tono de marca, sin cifras inventadas', function () {
    $this->ws->forceFill(['settings' => ['brand_tone' => ['summary' => 'Cercano y práctico, de ferretería de barrio', 'traits' => ['cercano'], 'do' => [], 'dont' => [], 'updated_at' => now()->toIso8601String()]]])->save();
    $ai = FakeAiGateway::install()
        ->replyJson(['variants' => [['subject' => 'Tu 20% te espera', 'preheader' => 'Solo hasta el domingo'], ['subject' => '¿Pintamos juntos?', 'preheader' => '20% en pinturas']]]);
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/subjects", ['goal' => 'Black Friday', 'offer' => '20% en pinturas', 'count' => 2])->assertOk();
    expect($res->json('variants'))->toHaveCount(2)->and($ai->requests[0]['request']->messages[0]['content'])->toContain('ferretería de barrio')
        ->and($this->inWs(fn () => AiDraft::query()->where('kind', 'content')->count()))->toBe(1);

    FakeAiGateway::install()->replyJson(['variants' => [['subject' => 'Ahorra 40%', 'preheader' => '']]])->replyJson(['variants' => [['subject' => 'Ahorra 45%', 'preheader' => '']]]);
    $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/subjects", ['goal' => 'Black Friday', 'offer' => '20% en pinturas'])->assertUnprocessable();

    FakeAiGateway::install()->replyJson(['variants' => ['Te guardamos el 20%', 'Hoy pintas con 20% menos']]);
    expect($this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/variants", ['text' => 'Tu 20% te espera', 'count' => 2])->assertOk()->json('variants'))
        ->toBe(['Te guardamos el 20%', 'Hoy pintas con 20% menos']);
});

it('variantes de SMS: cada una cabe en un mensaje; se pide corregir una vez y lo que sigue largo no se entrega', function () {
    $long = 'Hola {{ contacto.nombre }}, '.str_repeat('vuelve pronto ', 12).'Responde BAJA';
    $ai = FakeAiGateway::install()
        ->replyJson(['variants' => ['Hola {{ contacto.nombre }}, vuelve y pinta tu casa. Responde BAJA', 'Te extrañamos: ¿pintamos? Responde BAJA', $long]])
        ->replyJson(['variants' => ['Hola {{ contacto.nombre }}, vuelve y pinta tu casa. Responde BAJA', 'Te extranamos, ¿pintamos? Responde BAJA', $long]]);
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/variants", ['text' => 'Vuelve, te extrañamos', 'kind' => 'sms', 'count' => 3])->assertOk();
    expect($res->json('variants'))->toBe(['Hola {{ contacto.nombre }}, vuelve y pinta tu casa. Responde BAJA', 'Te extranamos, ¿pintamos? Responde BAJA'])
        ->and($ai->requests[0]['request']->system)->toContain('UN solo SMS')
        // La corrección dice qué variante no cabe y por qué letra.
        ->and(json_encode($ai->requests[1]['request']->messages, JSON_UNESCAPED_UNICODE))->toContain('La variante 3 ocupa');
});

it('cuerpo de WhatsApp que cumple las reglas de Meta: se corrige si no las cumple', function () {
    expect(WhatsAppTemplateRules::check('Hola {{1}}, tu pedido está listo'))->toBe([])
        ->and(WhatsAppTemplateRules::check('{{1}} {{2}}'))->not->toBeEmpty()
        ->and(implode(' ', WhatsAppTemplateRules::check('Hola, tu código es {{2}}')))->toContain('seguidas')
        ->and(implode(' ', WhatsAppTemplateRules::check('Mira la oferta en bit.ly/abc, {{1}} y tu pedido')))->toContain('acortadores');

    FakeAiGateway::install()
        ->replyJson(['body' => 'Tenemos 20% en pinturas para ti {{1}}', 'variables' => [['index' => 1, 'meaning' => 'nombre', 'example' => 'Laura']]])
        ->replyJson(['body' => 'Hola {{1}}, tenemos 20% en pinturas para ti esta semana.', 'variables' => [['index' => 1, 'meaning' => 'nombre', 'example' => 'Laura']]]);
    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/content/whatsapp", ['goal' => 'Promoción de pinturas', 'offer' => '20% en pinturas'])->assertOk();
    expect($res->json('body'))->toStartWith('Hola {{1}}')->and($res->json('problems'))->toBe([])->and($res->json('category'))->toBe('marketing');
});

it('aprende el tono de la portada y de «Nosotros» del sitio y lo guarda 7 días', function () {
    $this->connectedSite($this->ws);
    Http::fake([
        'https://tienda.co/' => Http::response('<html><head><style>x{}</style></head><body><h1>Pinturas La 14</h1><p>Desde 1985 pintamos Manizales. Escríbenos a hola@tienda.co</p><script>var a=1</script></body></html>', 200, ['Content-Type' => 'text/html']),
        'https://tienda.co/nosotros/' => Http::response('<p>Somos una familia de pintores.</p>', 200, ['Content-Type' => 'text/html; charset=UTF-8']),
        '*' => Http::response('', 404),
    ]);
    $ai = FakeAiGateway::install()->replyJson(['summary' => 'Cercano, familiar y con orgullo local.', 'traits' => ['cercano', 'familiar'], 'do' => ['Hablar de tú'], 'dont' => ['Tecnicismos'], 'examples' => ['Te ayudamos a escoger el color.']]);

    $res = $this->actingAs($this->owner)->postJson("/api/w/{$this->ws->publicId()}/copilot/tone")->assertOk();
    expect($res->json('tone.summary'))->toBe('Cercano, familiar y con orgullo local.')->and($res->json('tone.sources'))->toBe(['https://tienda.co/', 'https://tienda.co/nosotros/'])
        ->and($ai->requests[0]['request']->messages[0]['content'])->toContain('Somos una familia de pintores')->not->toContain('hola@tienda.co')->not->toContain('var a=1');
    expect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/copilot/status")->json('tone.traits'))->toBe(['cercano', 'familiar']);
    $this->travel(8)->days();
    expect($this->actingAs($this->owner)->getJson("/api/w/{$this->ws->publicId()}/copilot/status")->json('tone'))->toBeNull();
});
