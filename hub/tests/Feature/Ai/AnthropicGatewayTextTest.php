<?php

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiTool;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/*
 * El gateway real con herramientas (AnthropicAiGateway::withTools): si el modelo escribe antes de llamar una
 * herramienta y la última vuelta termina sin texto, el texto no se pierde (antes la respuesta quedaba vacía).
 */

/** @param list<array<string, mixed>> $bodies respuestas de la API, en orden */
function gatewayHttp(array $bodies): ClientInterface
{
    return new class($bodies) implements ClientInterface
    {
        public function __construct(private array $bodies) {}

        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            $body = array_shift($this->bodies) + ['id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-5-5', 'stop_sequence' => null,
                'usage' => ['input_tokens' => 10, 'output_tokens' => 10, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]];

            return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($body));
        }
    };
}

beforeEach(function () {
    $this->ws = $this->workspaceFor($this->userWith2fa());
    config(['igr-ai.api_key' => 'sk-ant-prueba-larga-123', 'igr-ai.enabled' => true]);
    app()->forgetInstance(AiGateway::class);
});

it('conserva el texto escrito antes de la herramienta cuando la última vuelta llega vacía', function () {
    app()->instance('igr.ai.transporter', gatewayHttp([
        ['content' => [['type' => 'text', 'text' => 'Conecta tu web desde Conexiones.'], ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'noop', 'input' => (object) []]], 'stop_reason' => 'tool_use'],
        ['content' => [], 'stop_reason' => 'end_turn'],
    ]));
    $tool = new AiTool('noop', 'Nada.', ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false], fn (array $in): array => ['ok' => true]);

    $result = app(AiGateway::class)->withTools(AiRequest::ask($this->ws->id, 'ask_data', 'Sistema.', 'Hola'), [$tool], 3);

    expect($result->text)->toBe('Conecta tu web desde Conexiones.')->and($result->toolCalls)->toHaveCount(1);
});

it('con salida estructurada usa solo la última vuelta (el JSON)', function () {
    app()->instance('igr.ai.transporter', gatewayHttp([
        ['content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'noop', 'input' => (object) []]], 'stop_reason' => 'tool_use'],
        ['content' => [['type' => 'text', 'text' => '{"answer":"Listo"}']], 'stop_reason' => 'end_turn'],
    ]));
    $tool = new AiTool('noop', 'Nada.', ['type' => 'object', 'properties' => (object) [], 'additionalProperties' => false], fn (array $in): array => ['ok' => true]);
    $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer'], 'additionalProperties' => false];

    $result = app(AiGateway::class)->withTools(AiRequest::ask($this->ws->id, 'assistant', 'Sistema.', 'Hola', $schema), [$tool], 3);

    expect($result->json)->toBe(['answer' => 'Listo']);
});
