<?php

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Intelligence\Ai\Support\AiModels;
use App\Domain\Platform\Settings\PlatformSettingsRuntime;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\DB;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/*
 * Modelo y esfuerzo de la IA desde Plataforma → Ajustes → IA (inteligencia.md §6.1): el grupo «principal» o «rápido»
 * de cada función, lo de una función concreta y lo de fábrica, en caliente; un esfuerzo que el modelo no admite no se
 * guarda ni se manda; «Probar la IA» dice en palabras qué pasó sin devolver la clave.
 */

/** Cliente HTTP que responde como la API de Anthropic con un guion (o falla de red con `network`). */
function aiModelsHttp(array $responses): object
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
            if (($next['network'] ?? false) === true) {
                throw new class($request) extends RuntimeException implements NetworkExceptionInterface
                {
                    public function __construct(private RequestInterface $req)
                    {
                        parent::__construct('Could not resolve host');
                    }

                    public function getRequest(): RequestInterface
                    {
                        return $this->req;
                    }
                };
            }

            return new Response($next['status'] ?? 200, ['Content-Type' => 'application/json'], json_encode($next['body']));
        }
    };
}

function aiModelsMessage(string $model = 'claude-sonnet-5-5', string $text = 'listo', string $stop = 'end_turn'): array
{
    return ['body' => ['id' => 'msg_'.uniqid(), 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => $stop, 'stop_sequence' => null, 'usage' => ['input_tokens' => 20, 'output_tokens' => 40, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 0]]];
}

function aiModelsError(int $status, string $type, string $message): array
{
    return ['status' => $status, 'body' => ['type' => 'error', 'error' => ['type' => $type, 'message' => $message]]];
}

beforeEach(function () {
    $this->admin = $this->userWith2fa(['is_platform_admin' => true]);
    $this->owner = $this->userWith2fa();
    $this->ws = $this->workspaceFor($this->owner);
});

it('cada función toma el modelo de su grupo, el de la función manda sobre el grupo y vacío vuelve a lo de fábrica', function () {
    expect(AiModels::resolve('weekly_analyst'))->toMatchArray(['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'tier' => 'main', 'source' => 'default'])
        ->and(AiModels::resolve('inbound_classifier'))->toMatchArray(['model' => 'claude-haiku-5-5', 'effort' => 'low', 'tier' => 'fast'])
        // Las funciones de leads (ARM) son del grupo rápido aunque su modelo de fábrica salga del calificador.
        ->and(AiModels::resolve('lead_brief')['tier'])->toBe('fast')
        ->and(AiModels::resolve('assistant')['tier'])->toBe('main');

    config(['igr-ai.tiers.main' => ['model' => 'claude-opus-5-5', 'effort' => 'high']]);
    expect(AiModels::resolve('copilot'))->toMatchArray(['model' => 'claude-opus-5-5', 'effort' => 'high', 'source' => 'tier'])
        ->and(AiModels::resolve('review_moderator')['model'])->toBe('claude-haiku-5-5');

    config(['igr-ai.overrides.sales_agent' => ['model' => 'claude-fable-5-2', 'effort' => null]]);
    expect(AiModels::resolve('sales_agent'))->toMatchArray(['model' => 'claude-fable-5-2', 'effort' => 'high', 'source' => 'override'])
        ->and(AiModels::resolve('copilot')['model'])->toBe('claude-opus-5-5');

    // Un esfuerzo que el modelo no admite no se manda: el modelo usa el suyo.
    config(['igr-ai.catalog.claude-haiku-5-5.efforts' => ['low', 'medium', 'high'], 'igr-ai.tiers.fast' => ['model' => null, 'effort' => 'max']]);
    expect(AiModels::resolve('profile_summary')['effort'])->toBeNull();

    // Todo modelo que se ofrece tiene precio (el medidor y el tope dependen de eso) y solo esfuerzos válidos.
    foreach (AiModels::catalog() as $id => $entry) {
        expect(config("igr-ai.pricing.{$id}.input"))->toBeGreaterThan(0)
            ->and(array_diff($entry['efforts'], AiModels::EFFORTS))->toBe([]);
    }
});

it('lo elegido en el panel rige en caliente en la llamada real, y Haiku va sin el respaldo del servidor que no tiene', function () {
    config(['igr-ai.api_key' => 'sk-test-clave-larga-123']);
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => [
        'main_model' => 'claude-opus-5-5', 'main_effort' => 'high', 'feature_review_summary_model' => 'claude-sonnet-5-5',
    ]])->assertOk();
    expect(config('igr-ai.tiers.main.model'))->toBe('claude-opus-5-5')->and(config('igr-ai.overrides.review_summary.model'))->toBe('claude-sonnet-5-5');

    $http = aiModelsHttp([aiModelsMessage('claude-opus-5-5', 'Hola'), aiModelsMessage('claude-haiku-5-5', 'ok'), aiModelsMessage('claude-sonnet-5-5', 'ok')]);
    app()->instance('igr.ai.transporter', $http);
    app()->forgetInstance(AiGateway::class);
    $gateway = app(AiGateway::class);
    $gateway->generate(AiRequest::ask($this->ws->id, 'weekly_analyst', 'Eres el analista.', 'Hola'));
    $gateway->generate(AiRequest::ask($this->ws->id, 'inbound_classifier', 'Clasifica.', 'Hola'));
    $gateway->generate(AiRequest::ask($this->ws->id, 'review_summary', 'Resume.', 'Hola'));

    expect($http->bodies[0]['model'])->toBe('claude-opus-5-5')->and($http->bodies[0]['output_config']['effort'])->toBe('high')
        ->and($http->bodies[0]['fallbacks'])->toBe('default')
        ->and($http->bodies[1]['model'])->toBe('claude-haiku-5-5')->and($http->bodies[1])->not->toHaveKey('fallbacks')
        ->and($http->requests[1]->getHeaderLine('anthropic-beta'))->not->toContain('server-side-fallback')
        ->and($http->bodies[2]['model'])->toBe('claude-sonnet-5-5');
    // El costo se mide con el precio del modelo que se usó.
    $row = DB::table('ai_usage')->where('workspace_id', $this->ws->id)->where('feature', 'weekly_analyst')->first();
    expect((string) $row->model)->toBe('claude-opus-5-5')->and((float) $row->cost_usd)->toBe(AiMeter::cost('claude-opus-5-5', 20, 40));

    // Vaciar vuelve a lo de fábrica.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['main_model' => null, 'main_effort' => null]])->assertOk();
    expect(AiModels::resolve('weekly_analyst'))->toMatchArray(['model' => 'claude-sonnet-5-5', 'effort' => 'medium']);

    // El panel muestra cada modelo con su precio y cada función con lo que usa ahora.
    $ai = collect($this->actingAs($this->admin)->getJson('/api/platform/settings')->assertOk()->json('sections'))->firstWhere('key', 'ai');
    expect(collect($ai['info']['models'])->pluck('id')->all())->toBe(AiModels::ids())
        ->and(collect($ai['info']['features'])->firstWhere('key', 'review_summary')['effective']['model'])->toBe('claude-sonnet-5-5')
        ->and(collect($ai['fields'])->firstWhere('key', 'main_model')['options'])->toBe(AiModels::ids());
});

it('no guarda un esfuerzo que el modelo elegido no admite ni un modelo fuera de la lista', function () {
    config(['igr-ai.catalog.claude-haiku-5-5.efforts' => ['low', 'medium']]);
    $res = $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['fast_model' => 'claude-haiku-5-5', 'fast_effort' => 'max']])
        ->assertUnprocessable();
    expect($res->json('errors')['values.fast_effort'][0])->toContain('low, medium');
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['feature_lead_brief_model' => 'gpt-4']])
        ->assertUnprocessable()->assertJsonValidationErrors('values.feature_lead_brief_model');
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['fast_model' => 'claude-haiku-5-5', 'fast_effort' => 'low']])->assertOk();
    // Con el modelo ya guardado, un esfuerzo suelto también se revisa.
    $this->actingAs($this->admin)->putJson('/api/platform/settings/ai', ['values' => ['fast_effort' => 'xhigh']])->assertUnprocessable();
    expect(app(PlatformSettingsRuntime::class)->baseline())->toHaveKey('igr-ai.tiers.fast.effort');
});

it('«Probar la IA» dice si respondió, con la latencia, el modelo y el costo, sin devolver la clave', function () {
    config(['igr-ai.api_key' => 'sk-ant-clave-secreta-de-prueba']);
    $http = aiModelsHttp([aiModelsMessage('claude-haiku-5-5', 'listo')]);
    app()->instance('igr.ai.transporter', $http);

    $res = $this->actingAs($this->admin)->postJson('/api/platform/settings/ai/test', ['model' => 'claude-haiku-5-5', 'effort' => 'low'])->assertOk();
    expect($res->json())->toMatchArray(['ok' => true, 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'served_model' => 'claude-haiku-5-5', 'reply' => 'listo'])
        ->and($res->json('latency_ms'))->toBeInt()
        ->and($res->json('cost_usd'))->toBe(AiMeter::cost('claude-haiku-5-5', 20, 40))
        ->and($res->getContent())->not->toContain('sk-ant-clave-secreta-de-prueba')
        ->and($http->bodies[0]['output_config']['effort'])->toBe('low')
        // Sin modelo, prueba el principal; la prueba no se cobra a ningún espacio de trabajo.
        ->and(DB::table('ai_usage')->count())->toBe(0);
    $this->assertDatabaseHas('audit_logs', ['action' => 'platform.ai_tested']);

    $this->actingAs($this->owner)->postJson('/api/platform/settings/ai/test')->assertForbidden();
});

it('«Probar la IA» explica cada falla en palabras: clave, saldo, modelo, saturación y red', function (array $response, string $reason) {
    config(['igr-ai.api_key' => 'sk-ant-clave-secreta-de-prueba']);
    app()->instance('igr.ai.transporter', aiModelsHttp([$response]));
    $res = $this->actingAs($this->admin)->postJson('/api/platform/settings/ai/test')->assertUnprocessable();
    expect($res->json('ok'))->toBeFalse()->and($res->json('reason'))->toBe($reason)
        ->and($res->json('message'))->toBeString()->not->toBe('')
        ->and($res->json('model'))->toBe(AiModels::resolve('assistant')['model'])
        ->and($res->getContent())->not->toContain('sk-ant-clave-secreta-de-prueba');
})->with([
    'clave inválida' => [aiModelsError(401, 'authentication_error', 'invalid x-api-key'), 'auth'],
    'sin saldo' => [aiModelsError(400, 'invalid_request_error', 'Your credit balance is too low to access the Anthropic API.'), 'billing'],
    'modelo no disponible' => [aiModelsError(404, 'not_found_error', 'model: claude-x'), 'model'],
    'saturado' => [aiModelsError(529, 'overloaded_error', 'Overloaded'), 'overloaded'],
    'demasiadas' => [aiModelsError(429, 'rate_limit_error', 'Rate limited'), 'rate_limit'],
    'sin red' => [['network' => true], 'network'],
]);

it('«Probar la IA» sin clave dice que falta, sin llamar', function () {
    config(['igr-ai.api_key' => null]);
    $http = aiModelsHttp([]);
    app()->instance('igr.ai.transporter', $http);
    $this->actingAs($this->admin)->postJson('/api/platform/settings/ai/test')->assertUnprocessable()->assertJsonPath('reason', 'no_key');
    expect($http->requests)->toBe([]);
});
