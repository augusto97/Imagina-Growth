<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Testing;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
use App\Domain\Intelligence\Ai\Data\AiUsage;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Intelligence\Ai\Support\AiModels;
use RuntimeException;

/**
 * Doble de `AiGateway` para las pruebas: responde con un guion.
 *
 *     $ai = FakeAiGateway::install()
 *         ->callTool('get_product', ['product_id' => '88'])   // el modelo pide una herramienta
 *         ->reply('La Pintura Azul cuesta $141.000.');        // y después contesta
 *
 * Cada pedido queda en `$requests` (con las herramientas que se ofrecieron) y cada
 * llamada suma 100 tokens de entrada y 50 de salida en `ai_usage`, como una real.
 */
final class FakeAiGateway implements AiGateway
{
    /** @var list<array{request: AiRequest, tools: list<string>}> */
    public array $requests = [];

    public bool $enabled = true;

    /** @var list<array{kind: string, text?: string, json?: array<string, mixed>, name?: string, input?: array<string, mixed>, reason?: string}> */
    private array $script = [];

    public static function install(): self
    {
        $fake = new self;
        app()->instance(AiGateway::class, $fake);

        return $fake;
    }

    public function reply(string $text): self
    {
        $this->script[] = ['kind' => 'text', 'text' => $text];

        return $this;
    }

    /** @param array<string, mixed> $json */
    public function replyJson(array $json): self
    {
        $this->script[] = ['kind' => 'json', 'json' => $json];

        return $this;
    }

    /** @param array<string, mixed> $input */
    public function callTool(string $name, array $input): self
    {
        $this->script[] = ['kind' => 'tool', 'name' => $name, 'input' => $input];

        return $this;
    }

    public function refuse(): self
    {
        $this->script[] = ['kind' => 'refusal'];

        return $this;
    }

    public function failWith(string $reason = 'provider'): self
    {
        $this->script[] = ['kind' => 'fail', 'reason' => $reason];

        return $this;
    }

    public function pending(): int
    {
        return count($this->script);
    }

    public function available(string $workspaceId): bool
    {
        return $this->enabled;
    }

    public function generate(AiRequest $request): AiResult
    {
        return $this->withTools($request, [], 1);
    }

    public function withTools(AiRequest $request, array $tools, int $maxSteps = 6): AiResult
    {
        if (! $this->enabled) {
            throw new AiUnavailable('disabled');
        }
        $this->requests[] = ['request' => $request, 'tools' => array_map(fn (AiTool $t) => $t->name, $tools)];
        $byName = [];
        foreach ($tools as $tool) {
            $byName[$tool->name] = $tool;
        }
        $model = AiModels::resolve($request->feature)['model'];
        $usage = new AiUsage;
        $calls = [];
        for ($step = 0; $step < max(1, $maxSteps) + 1; $step++) {
            $call = new AiUsage(100, 50, 0, 0, AiMeter::cost($model, 100, 50), 1);
            $usage = $usage->plus($call);
            app(AiMeter::class)->record($request->workspaceId, $request->feature, $model, $call);
            $next = array_shift($this->script) ?? ['kind' => $request->outputSchema !== null ? 'json' : 'text', 'text' => 'Respuesta simulada.', 'json' => []];
            switch ($next['kind']) {
                case 'fail':
                    throw new AiUnavailable((string) ($next['reason'] ?? 'provider'));
                case 'refusal':
                    return new AiResult('', null, $model, 'refusal', $usage, $calls);
                case 'json':
                    $json = (array) ($next['json'] ?? []);

                    return new AiResult((string) json_encode($json, JSON_UNESCAPED_UNICODE), $json, $model, 'end_turn', $usage, $calls);
                case 'text':
                    return new AiResult((string) ($next['text'] ?? ''), null, $model, 'end_turn', $usage, $calls);
                case 'tool':
                    $name = (string) ($next['name'] ?? '');
                    $input = (array) ($next['input'] ?? []);
                    $tool = $byName[$name] ?? throw new RuntimeException("El guion pide la herramienta {$name}, que no se ofreció.");
                    try {
                        $calls[] = ['name' => $name, 'input' => $input, 'output' => ($tool->handler)($input), 'error' => null];
                    } catch (\Throwable $e) {
                        $calls[] = ['name' => $name, 'input' => $input, 'output' => null, 'error' => $e->getMessage()];
                    }
                    if ($step + 1 >= $maxSteps) {
                        return new AiResult('', null, $model, 'max_steps', $usage, $calls);
                    }
                    break;
            }
        }

        return new AiResult('', null, $model, 'max_steps', $usage, $calls);
    }
}
