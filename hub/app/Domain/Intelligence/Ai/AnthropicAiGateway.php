<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Data\AiUsage;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Intelligence\Ai\Support\AiModels;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Throwable;

/**
 * `AiGateway` con el SDK oficial de Anthropic para PHP (anthropic-ai/sdk).
 *
 * - El `system` va como bloque con `cacheControl` (caché de prompts): las
 *   herramientas y las instrucciones son estables por función; lo variable va en
 *   los mensajes.
 * - Modelo y esfuerzo por función con `AiModels::resolve` (lo de la función en el
 *   panel, si no el de su grupo, si no `igr-ai.features`). Los modelos actuales
 *   piensan de forma adaptativa; el esfuerzo controla cuánto.
 * - Con `igr-ai.server_fallbacks`, la llamada usa el respaldo del lado del servidor
 *   (`fallbacks: "default"`) si el modelo se niega por política, salvo en los modelos
 *   que no lo tienen (Claude Haiku 5.5, `igr-ai.catalog`).
 * - Salidas estructuradas con `outputConfig.format` (JSON Schema) y herramientas
 *   con `strict`.
 */
final class AnthropicAiGateway implements AiGateway
{
    private const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    public function __construct(private readonly AiMeter $meter) {}

    public function available(string $workspaceId): bool
    {
        return (bool) config('igr-ai.enabled') && (string) config('igr-ai.api_key') !== '' && $this->meter->withinBudget($workspaceId);
    }

    public function generate(AiRequest $request): AiResult
    {
        return $this->withTools($request, [], 1);
    }

    public function withTools(AiRequest $request, array $tools, int $maxSteps = 6): AiResult
    {
        $feature = AiModels::resolve($request->feature);
        $model = $feature['model'];
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }
        $messages = $request->messages;
        $usage = new AiUsage;
        $calls = [];
        $response = null;
        // El último texto no vacío de una vuelta anterior: si la respuesta final llega sin texto (el modelo escribió
        // antes de llamar una herramienta y terminó después sin más), no se pierde.
        $earlierText = '';
        for ($step = 0; $step < max(1, $maxSteps); $step++) {
            $this->guard($request->workspaceId);
            $response = $this->call($request, $model, $feature, $messages, $tools);
            $u = $response->usage;
            $callUsage = new AiUsage((int) $u->inputTokens, (int) $u->outputTokens, (int) ($u->cacheReadInputTokens ?? 0),
                (int) ($u->cacheCreationInputTokens ?? 0), AiMeter::cost($model, (int) $u->inputTokens, (int) $u->outputTokens,
                    (int) ($u->cacheReadInputTokens ?? 0), (int) ($u->cacheCreationInputTokens ?? 0)), 1);
            $usage = $usage->plus($callUsage);
            $this->meter->record($request->workspaceId, $request->feature, $model, $callUsage);
            if ($response->stopReason !== 'tool_use' || $byName === []) {
                break;
            }
            $results = [];
            foreach ($response->content as $block) {
                if (! $block instanceof BetaToolUseBlock && ! $block instanceof ToolUseBlock) {
                    continue;
                }
                $input = (array) $block->input;
                $tool = $byName[$block->name] ?? null;
                try {
                    if ($tool === null) {
                        throw new \InvalidArgumentException("Herramienta desconocida: {$block->name}");
                    }
                    $output = ($tool->handler)($input);
                    $calls[] = ['name' => $block->name, 'input' => $input, 'output' => $output, 'error' => null];
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => (string) json_encode($output, JSON_UNESCAPED_UNICODE)];
                } catch (Throwable $e) {
                    $calls[] = ['name' => $block->name, 'input' => $input, 'output' => null, 'error' => $e->getMessage()];
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => mb_substr($e->getMessage(), 0, 500), 'isError' => true];
                }
            }
            $turnText = self::textOf($response);
            if (trim($turnText) !== '') {
                $earlierText = $turnText;
            }
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];
        }
        assert($response !== null);

        $text = self::textOf($response);
        if (trim($text) === '' && $request->outputSchema === null && $response->stopReason !== 'refusal') {
            $text = $earlierText;
        }
        $json = null;
        if ($request->outputSchema !== null && $response->stopReason !== 'refusal') {
            $json = json_decode($text, true);
            if (! is_array($json)) {
                throw new AiUnavailable('invalid', 'La respuesta no es el JSON pedido.');
            }
        }
        $stop = $response->stopReason === 'tool_use' ? 'max_steps' : $response->stopReason;

        return new AiResult($text, $json, (string) $response->model, $stop, $usage, $calls);
    }

    /** El texto de una respuesta (los bloques de pensamiento no cuentan). */
    private static function textOf(BetaMessage|Message $response): string
    {
        $text = '';
        foreach ($response->content as $block) {
            if ($block instanceof BetaTextBlock || $block instanceof TextBlock) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /**
     * @param  array<string, mixed>  $feature  lo de `AiModels::resolve`
     * @param  list<array<string, mixed>>  $messages
     * @param  list<AiTool>  $tools
     */
    private function call(AiRequest $request, string $model, array $feature, array $messages, array $tools): BetaMessage|Message
    {
        $effort = $request->effort !== null && AiModels::supportsEffort($model, $request->effort) ? $request->effort : ($feature['effort'] ?? null);
        $outputConfig = array_filter([
            'effort' => $effort,
            'format' => $request->outputSchema !== null ? ['type' => 'json_schema', 'schema' => $request->outputSchema] : null,
        ]);
        $params = [
            'model' => $model,
            'maxTokens' => $request->maxTokens ?? (int) ($feature['max_tokens'] ?? 4000),
            'system' => [['type' => 'text', 'text' => $request->system, 'cacheControl' => ['type' => 'ephemeral']]],
            'messages' => $messages,
        ];
        if ($outputConfig !== []) {
            $params['outputConfig'] = $outputConfig;
        }
        if ($tools !== []) {
            $params['tools'] = array_map(fn (AiTool $t) => $t->definition(), $tools);
        }
        try {
            if (AiModels::serverFallbacks($model)) {
                // @phpstan-ignore argument.type, argument.type (mensajes y herramientas en forma de arreglo, como documenta el SDK)
                return $this->client()->beta->messages->create(...$params, fallbacks: 'default', betas: [self::FALLBACK_BETA]);
            }

            // @phpstan-ignore argument.type, argument.type
            return $this->client()->messages->create(...$params);
        } catch (APIException $e) {
            Log::warning('igr.ai.provider_error', ['feature' => $request->feature, 'model' => $model, 'error' => class_basename($e)]);

            throw new AiUnavailable('provider', 'El proveedor de IA no respondió.', $e);
        }
    }

    private function guard(string $workspaceId): void
    {
        if (! (bool) config('igr-ai.enabled') || (string) config('igr-ai.api_key') === '') {
            throw new AiUnavailable('disabled', 'La IA no está configurada.');
        }
        if (! $this->meter->withinBudget($workspaceId)) {
            throw new AiUnavailable('budget', 'Se alcanzó el tope de gasto en IA de este mes.');
        }
    }

    private function client(): Client
    {
        if ($this->client === null) {
            $options = ['timeout' => (float) config('igr-ai.timeout', 60), 'maxRetries' => (int) config('igr-ai.max_retries', 2)];
            // Las pruebas inyectan un cliente HTTP (PSR-18) que responde como la API.
            if (app()->bound('igr.ai.transporter')) {
                $transporter = app('igr.ai.transporter');
                if ($transporter instanceof ClientInterface) {
                    $options['transporter'] = $transporter;
                }
            }
            $this->client = new Client(apiKey: (string) config('igr-ai.api_key'), baseUrl: (string) config('igr-ai.base_url'), requestOptions: $options);
        }

        return $this->client;
    }
}
