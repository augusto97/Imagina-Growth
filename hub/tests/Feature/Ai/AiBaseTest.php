<?php

use App\Domain\Inbox\Contracts\InboundClassifier;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Data\AiUsage;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Intelligence\Ai\Support\PiiScrubber;
use App\Domain\Intelligence\Ai\Testing\FakeAiGateway;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** Cliente HTTP que responde como la API de Anthropic con un guion. */
function aiHttp(array $responses): object
{
    return new class($responses) implements ClientInterface
    {
        /** @var list<array<string, mixed>> */
        public array $bodies = [];

        /** @var list<RequestInterface> */
        public array $requests = [];

        public function __construct(private array $responses) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $this->requests[] = $request;
            $this->bodies[] = json_decode((string) $request->getBody(), true);
            $next = array_shift($this->responses);

            return new Response($next['status'] ?? 200, ['Content-Type' => 'application/json'], json_encode($next['body']));
        }
    };
}

function aiMessage(array $content, string $stop = 'end_turn', int $in = 1200, int $out = 300, int $cacheRead = 0): array
{
    return ['body' => ['id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'content' => $content,
        'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => $in, 'output_tokens' => $out, 'cache_read_input_tokens' => $cacheRead, 'cache_creation_input_tokens' => 0]]];
}

beforeEach(function () {
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

it('valida las cifras de un texto contra los hechos, con redondeo, porcentajes y formato colombiano', function () {
    $facts = ['clientes_en_riesgo' => 37, 'reactivacion' => 0.18, 'ticket' => 185432.7, 'impacto' => 1232100, 'conversion' => ['antes' => 2.08, 'ahora' => 1.31], 'multiplo' => 2.1];
    $v = app(NumberValidator::class);

    $ok = $v->validate("Estimación: 37 clientes × 18% de reactivación × \$185.000 de ticket = \$1.232.100. La conversión bajó de 2,1% a 1,3% desde el 10 de noviembre de 2026; los de Google valen 2,1× más. Te propongo 3 acciones:\n1. Activar el flujo.", $facts);
    expect($ok->ok())->toBeTrue()->and($ok->checked)->toContain('37', '18%', '$1.232.100');

    $bad = $v->validate('Recuperarías $2.500.000 con 41 clientes y un 25% más de ventas.', $facts);
    expect($bad->ok())->toBeFalse()->and($bad->unknown)->toBe(['$2.500.000', '41', '25%']);

    // Escalas y cupones: «1,2 millones» cubre 1.232.100; PRIMA15 no es una cifra.
    expect($v->validate('Unos $1,2 millones con el cupón PRIMA15.', $facts)->ok())->toBeTrue();
    expect(NumberValidator::parse('1.232.100,50'))->toBe([1232100.5, 2])->and(NumberValidator::parse('2.500'))->toBe([2500.0, 0]);
});

it('el texto narrativo se regenera una vez si inventa una cifra y, si insiste, usa la plantilla sin IA', function () {
    $facts = ['ventas' => 4200000, 'pedidos' => 28];
    $req = AiRequest::ask($this->ws->id, 'weekly_analyst', 'Eres el analista.', 'Hechos: '.json_encode($facts));

    $ai = FakeAiGateway::install()->reply('Vendiste $4.500.000 en 28 pedidos.')->reply('Vendiste $4.200.000 en 28 pedidos.');
    $r = app(NarrativeWriter::class)->write($req, $facts, fn () => 'plantilla');
    expect($r->fromAi())->toBeTrue()->and($r->text)->toBe('Vendiste $4.200.000 en 28 pedidos.')
        ->and($r->validation['attempts'][0]['unknown'])->toBe(['$4.500.000'])
        ->and(end($ai->requests)['request']->messages[2]['content'])->toContain('$4.500.000');

    FakeAiGateway::install()->reply('Vendiste $9.000.000.')->reply('Fueron 99 pedidos.');
    $r = app(NarrativeWriter::class)->write($req, $facts, fn () => 'Ventas: $4.200.000 en 28 pedidos.');
    expect($r->source)->toBe('fallback')->and($r->text)->toBe('Ventas: $4.200.000 en 28 pedidos.')->and($r->validation['fallback_reason'])->toBe('numbers');

    FakeAiGateway::install()->failWith('budget');
    expect(app(NarrativeWriter::class)->write($req, $facts, fn () => 'sin IA')->validation['fallback_reason'])->toBe('budget');
});

it('llama a la API con el SDK: modelo por función, caché del system, esfuerzo, respaldo del servidor y costo en ai_usage', function () {
    config(['igr-ai.api_key' => 'sk-test', 'igr-ai.base_url' => 'https://api.anthropic.test']);
    $http = aiHttp([aiMessage([['type' => 'text', 'text' => '{"resumen":"Bien"}']], 'end_turn', 1200, 300, 800)]);
    app()->instance('igr.ai.transporter', $http);

    $schema = ['type' => 'object', 'properties' => ['resumen' => ['type' => 'string']], 'required' => ['resumen'], 'additionalProperties' => false];
    $r = app(AiGateway::class)->generate(AiRequest::ask($this->ws->id, 'weekly_analyst', 'Eres el analista.', 'Hola', $schema));
    $body = $http->bodies[0];
    expect($r->json)->toBe(['resumen' => 'Bien'])
        ->and((string) $http->requests[0]->getUri())->toStartWith('https://api.anthropic.test/v1/messages')
        ->and($http->requests[0]->getHeaderLine('authorization'))->toBe('Bearer sk-test')
        ->and($http->requests[0]->getHeaderLine('anthropic-beta'))->toContain('server-side-fallback-2026-07-01')
        ->and($body['model'])->toBe('claude-sonnet-5-5')
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['output_config']['effort'])->toBe('medium')
        ->and($body['output_config']['format']['type'])->toBe('json_schema')
        ->and($body['fallbacks'])->toBe('default');
    $usage = DB::table('ai_usage')->where('workspace_id', $this->ws->id)->first();
    expect((int) $usage->tokens_in)->toBe(1200)->and((int) $usage->cache_read)->toBe(800)
        ->and((float) $usage->cost_usd)->toBe(AiMeter::cost('claude-sonnet-5-5', 1200, 300, 800));
});

it('el bucle de herramientas ejecuta lo que pide el modelo y le devuelve los hechos', function () {
    config(['igr-ai.api_key' => 'sk-test']);
    $http = aiHttp([
        aiMessage([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_product', 'input' => ['product_id' => '88']]], 'tool_use'),
        aiMessage([['type' => 'text', 'text' => 'La Pintura Azul cuesta $141.000 y hay 12 unidades.']]),
    ]);
    app()->instance('igr.ai.transporter', $http);
    $tool = new AiTool('get_product', 'Precio y stock en vivo', ['type' => 'object', 'properties' => ['product_id' => ['type' => 'string']], 'required' => ['product_id'], 'additionalProperties' => false],
        fn (array $in) => ['id' => $in['product_id'], 'name' => 'Pintura Azul', 'price' => '141000.00', 'stock' => 12]);

    $r = app(AiGateway::class)->withTools(AiRequest::ask($this->ws->id, 'sales_agent', 'Eres el asesor.', '¿Cuánto vale la pintura azul?'), [$tool]);
    expect($r->text)->toContain('$141.000')->and($r->toolCalls[0]['output']['stock'])->toBe(12)
        ->and($http->bodies[0]['tools'][0]['strict'])->toBeTrue()
        ->and($http->bodies[1]['messages'][2]['content'][0]['tool_use_id'])->toBe('toolu_1')
        ->and(app(NumberValidator::class)->validate($r->text, $r->toolFacts())->ok())->toBeTrue();
});

it('sin clave, apagada o sin presupuesto, la IA no llama y lo dice', function () {
    config(['igr-ai.api_key' => null]);
    expect(app(AiGateway::class)->available($this->ws->id))->toBeFalse();
    expect(fn () => app(AiGateway::class)->generate(AiRequest::ask($this->ws->id, 'content', 's', 'p')))->toThrow(AiUnavailable::class);

    config(['igr-ai.api_key' => 'sk-test', 'igr-ai.monthly_budget_usd' => 1]);
    app(AiMeter::class)->record($this->ws->id, 'content', 'claude-sonnet-5-5', new AiUsage(0, 0, 0, 0, 1.5, 1));
    expect(app(AiGateway::class)->available($this->ws->id))->toBeFalse();
});

it('quita correos y teléfonos de los hechos de análisis sin tocar las cifras', function () {
    expect(PiiScrubber::scrub(['q' => 'pintura ana@ejemplo.com 300 123 4567', 'ventas' => '1.232.100', 'n' => 37]))
        ->toBe(['q' => 'pintura [correo] [teléfono]', 'ventas' => '1.232.100', 'n' => 37]);
});

it('clasifica entrantes por palabras sin IA: baja, queja, soporte y compra', function () {
    $c = app(InboundClassifier::class);
    expect($c->classify($this->ws->id, 'BAJA')->optOut())->toBeTrue()
        ->and($c->classify($this->ws->id, 'Pésimo servicio, mi pedido nunca llegó')->intent)->toBe('complaint')
        ->and($c->classify($this->ws->id, '¿Cuánto cuesta la pintura azul?')->intent)->toBe('purchase')
        ->and($c->classify($this->ws->id, 'Necesito la guía de seguimiento')->intent)->toBe('support');
});
