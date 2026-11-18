<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Support;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiUsage;
use App\Domain\Intelligence\Ai\Data\NarrativeResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Texto narrativo con cifras validadas (inteligencia.md §6.1): genera, revisa que
 * toda cifra exista en `facts`, si falla regenera **una** vez diciéndole al modelo
 * cuáles cifras no están, y si vuelve a fallar (o la IA no está disponible) usa la
 * plantilla sin IA. Lo usan el analista semanal, «Pregúntale a tus datos», los
 * resúmenes, el copiloto y el agente de ventas.
 */
final class NarrativeWriter
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly NumberValidator $validator,
    ) {}

    /**
     * @param  array<mixed>  $facts  los hechos que puede citar
     * @param  Closure(): string  $fallback  el texto sin IA
     * @param  Closure(AiResult): string|null  $textOf  qué parte de la respuesta se valida (por defecto, el texto
     *                                                  o todos los textos del JSON)
     */
    public function write(AiRequest $request, array $facts, Closure $fallback, ?Closure $textOf = null): NarrativeResult
    {
        $textOf ??= fn (AiResult $r): string => $r->json !== null ? self::strings($r->json) : $r->text;
        $usage = new AiUsage;
        $attempts = [];
        $model = null;
        try {
            $current = $request;
            for ($try = 1; $try <= 2; $try++) {
                $result = $this->ai->generate($current);
                $usage = $usage->plus($result->usage);
                $model = $result->model;
                if ($result->refused()) {
                    throw new AiUnavailable('refusal');
                }
                $text = $textOf($result);
                $check = $this->validator->validate($text, $facts);
                $attempts[] = $check->toArray();
                if ($check->ok()) {
                    return new NarrativeResult($result->json !== null ? $text : $result->text, $result->json, 'ai',
                        ['attempts' => $attempts, 'ok' => true], $model, $usage);
                }
                $current = $current->followUp($result->json !== null ? (string) json_encode($result->json, JSON_UNESCAPED_UNICODE) : $result->text,
                    'Estas cifras no están en los hechos: '.implode(', ', $check->unknown).'. Reescribe la respuesta usando solo cifras que aparezcan en los hechos, tal como están o redondeadas; si no tienes la cifra, dilo sin número.');
            }
            $reason = 'numbers';
        } catch (AiUnavailable $e) {
            $reason = $e->reason;
        }
        Log::info('igr.ai.narrative_fallback', ['feature' => $request->feature, 'reason' => $reason]);

        return new NarrativeResult($fallback(), null, 'fallback', ['attempts' => $attempts, 'ok' => true, 'fallback_reason' => $reason], $model, $usage);
    }

    /** @param array<mixed> $json */
    private static function strings(array $json): string
    {
        $out = [];
        array_walk_recursive($json, function (mixed $v) use (&$out): void {
            if (is_string($v)) {
                $out[] = $v;
            }
        });

        return implode("\n", $out);
    }
}
