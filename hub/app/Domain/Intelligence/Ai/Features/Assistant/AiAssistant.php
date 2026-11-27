<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Features\AskData\AskYourData;
use App\Domain\Intelligence\Ai\Features\AskData\MetricQueryTool;
use App\Domain\Intelligence\Ai\Features\AskData\SegmentFromQuery;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;

/**
 * «Pregúntale a la IA» desde cualquier pantalla (inteligencia.md §6.3). Sabe del producto (`AssistantKnowledge`: la
 * base de conocimiento, las estrategias y las pantallas, en el `system`), de la cuenta (`AssistantWorkspaceState` sobre
 * `SetupStatus`: qué está conectado, qué falta, qué estrategias convienen) y de la pantalla donde está la persona
 * (`AssistantContext`), y consulta cifras con `run_metric_query` solo cuando la pregunta las pide.
 *
 * La respuesta es JSON (salida estructurada): `answer` (texto), hasta 3 `steps` con la pantalla de cada uno y un
 * `action` opcional (borrador con el copiloto). Antes la propuesta era una herramienta (`suggest_next_step`) y el texto
 * llegaba antes de llamarla: en los modelos 5.5 ese texto vuelve como pensamiento, no como texto, y la burbuja quedaba
 * vacía con solo el botón. Ahora:
 * - una respuesta sin texto (o que no es el JSON) se pide otra vez **una** vez; si sigue vacía, responde el plan sin
 *   IA (`AssistantPlan`): nunca queda muda;
 * - las cifras de la respuesta, de los pasos y del pedido al copiloto se validan contra los hechos (la pregunta, la
 *   pantalla, la cuenta, la base de conocimiento, la conversación y lo que devolvió la herramienta); si inventa, se le
 *   corrige una vez y, si insiste, plantilla sin IA;
 * - los pasos solo llevan a pantallas de `AssistantPages` (una clave desconocida deja el paso sin enlace);
 * - si la IA falla (proveedor, rechazo, cupo), lo dice en palabras y el panel ofrece reintentar.
 */
final class AiAssistant
{
    private const MAX_STEPS = 3;

    public function __construct(
        private readonly AiGateway $ai,
        private readonly MetricQueryRunner $runner,
        private readonly NumberValidator $validator,
        private readonly SegmentFromQuery $segments,
        private readonly AssistantContext $context,
        private readonly AssistantWorkspaceState $state,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array{kind?: string, id?: string|null}  $page
     * @param  list<array{question: string, answer: string}>  $history  lo último de la conversación del panel
     */
    public function handle(Workspace $workspace, User $user, string $question, array $page = [], array $history = []): AskDataQuery
    {
        $kind = in_array($page['kind'] ?? null, AssistantContext::KINDS, true) ? (string) $page['kind'] : 'other';
        $id = isset($page['id']) && is_string($page['id']) && $page['id'] !== '' ? mb_substr($page['id'], 0, 40) : null;
        $available = $this->ai->available($workspace->id);
        // Lo de la pantalla y de la cuenta, con los permisos del rol de la petición (antes de `run`, que no lleva rol).
        $context = $available ? $this->context->build($workspace, $kind, $id, (string) $user->id) : null;
        $state = $available ? $this->state->facts($workspace, Gate::allows('ws.manageWorkspace')) : [];

        return $this->current->run($workspace, function () use ($workspace, $user, $question, $kind, $context, $state, $history, $available): AskDataQuery {
            $question = mb_substr(trim($question), 0, 500);
            $tool = new MetricQueryTool($workspace, $this->runner);
            $record = new AskDataQuery(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'question' => $question, 'context' => ['kind' => $kind]]);

            if (! $available) {
                $configured = (bool) config('igr-ai.enabled') && (string) config('igr-ai.api_key') !== '';

                return $this->save($record, 'failed', $configured
                    ? __('Este mes ya se usó todo el cupo de IA de tu plan. Vuelve el mes que viene o mejora tu plan; mientras tanto, mira estas cifras en Resultados.')
                    : ((bool) ($user->getAttributes()['is_platform_admin'] ?? false)
                        ? __('La IA está apagada. Enciéndela en Plataforma → Ajustes → IA: pega la clave de Anthropic y guarda.')
                        : __('La IA todavía no está encendida en la plataforma. Pídele a quien la administra que la active.')),
                    $tool, null, ['fallback_reason' => $configured ? 'budget' : 'disabled'], [], []);
            }

            $plan = AssistantPlan::steps($state);
            $history = array_slice(array_values(array_filter($history, fn ($h) => isset($h['question'], $h['answer']) && is_string($h['question']) && is_string($h['answer']))), -3);
            $messages = [];
            foreach ($history as $turn) {
                $messages[] = ['role' => 'user', 'content' => mb_substr($turn['question'], 0, 500)];
                $messages[] = ['role' => 'assistant', 'content' => mb_substr($turn['answer'], 0, 1500)];
            }
            $messages[] = ['role' => 'user', 'content' => self::prompt($workspace, $kind, $context, $state, $plan, $question)];
            $request = new AiRequest($workspace->id, 'assistant', AssistantKnowledge::system(), $messages, AssistantKnowledge::schema());

            // Con qué se validan las cifras: la pregunta, la pantalla, la cuenta, la base de conocimiento, la conversación
            // y lo que devuelva la herramienta.
            $facts = [self::numbersOf($question), $context['facts'] ?? [], $state, AssistantKnowledge::numbers(),
                array_map(fn ($h) => self::numbersOf($h['answer'].' '.$h['question']), $history)];
            $attempts = [];
            $reason = 'empty';
            $result = null;
            try {
                for ($try = 1; $try <= 2; $try++) {
                    try {
                        $result = $this->ai->withTools($request, [$tool->tool()], 4);
                    } catch (AiUnavailable $e) {
                        // Respuesta que no es el JSON (p. ej. cortada): se pide otra vez igual.
                        if ($e->reason !== 'invalid') {
                            throw $e;
                        }
                        $attempts[] = ['ok' => false, 'invalid' => true];
                        $reason = 'invalid';

                        continue;
                    }
                    if ($result->refused()) {
                        throw new AiUnavailable('refusal');
                    }
                    $parsed = self::parse($result);
                    if ($parsed['answer'] === '') {
                        $attempts[] = ['ok' => false, 'empty' => true];
                        $reason = 'empty';
                        $request = $request->followUp($result->text !== '' ? $result->text : '{}',
                            'Tu respuesta llegó sin texto en «answer». Responde de nuevo mi última pregunta con un texto útil en «answer» y, si sirven, los pasos en «steps».');

                        continue;
                    }
                    $known = [...$facts, ...$result->toolFacts()];
                    $check = $this->validator->validate(self::allText($parsed), $known);
                    $attempts[] = $check->toArray();
                    if ($check->ok()) {
                        return $this->save($record, 'answered', $parsed['answer'], $tool, $result, ['attempts' => $attempts, 'ok' => true],
                            self::actions($parsed['action']), $parsed['steps']);
                    }
                    $reason = 'numbers';
                    $request = $request->followUp($result->text, 'Estas cifras no están en los hechos ni en los resultados: '.implode(', ', $check->unknown)
                        .'. Responde de nuevo usando solo cifras de los hechos, de la base de conocimiento o de los resultados; si no tienes la cifra, dilo sin número.');
                }
            } catch (AiUnavailable $e) {
                return $this->save($record, 'failed', self::unavailableMessage($e->reason), $tool, $result,
                    ['attempts' => $attempts, 'fallback_reason' => $e->reason], [], []);
            }

            // Sin una respuesta válida de la IA: con cifras consultadas, la plantilla de «Pregúntale a tus datos»; si no,
            // los próximos pasos según la cuenta. Nunca una burbuja vacía.
            if ($tool->lastResult !== null) {
                return $this->save($record, 'answered', AskYourData::templateFor($workspace, $tool), $tool, $result,
                    ['attempts' => $attempts, 'ok' => true, 'fallback_reason' => $reason], [], []);
            }

            return $this->save($record, 'answered', AssistantPlan::answer($state), $tool, $result,
                ['attempts' => $attempts, 'ok' => true, 'fallback_reason' => $reason], [], $plan);
        });
    }

    /** Lo que se le dice a la persona cuando la IA no pudo responder, según el motivo. */
    public static function unavailableMessage(string $reason): string
    {
        return match ($reason) {
            'refusal' => __('La IA no pudo responder esa pregunta. Pregúntalo de otra forma.'),
            'budget' => __('Este mes ya se usó todo el cupo de IA de tu plan. Vuelve el mes que viene o mejora tu plan.'),
            'disabled' => __('La IA está apagada en la plataforma.'),
            default => __('La IA no respondió esta vez (puede estar ocupada o sin conexión). Inténtalo de nuevo en un momento.'),
        };
    }

    /**
     * @param  array<string, mixed>|null  $context
     * @param  array<string, mixed>  $state
     * @param  list<array{text: string, page: string, ref: string|null, path: string}>  $plan
     */
    private static function prompt(Workspace $workspace, string $kind, ?array $context, array $state, array $plan, string $question): string
    {
        $now = CarbonImmutable::now($workspace->timezone ?: 'UTC');
        $lines = [sprintf('Hoy es %s, zona horaria %s. Moneda: %s.', $now->toDateString(), $workspace->timezone, $workspace->currency), 'Pantalla donde está la persona: '.$kind.'.'];
        if ($context !== null) {
            $lines[] = 'Hechos de la pantalla: '.json_encode($context['facts'], JSON_UNESCAPED_UNICODE);
        }
        if ($state !== []) {
            $lines[] = 'Estado de la cuenta: '.json_encode($state, JSON_UNESCAPED_UNICODE);
        }
        if ($plan !== []) {
            $lines[] = "Próximos pasos sugeridos según el estado (úsalos si pregunta cómo empezar o qué hacer; puedes reordenarlos o cambiarlos):\n"
                .implode("\n", array_map(fn (array $s) => '- '.$s['text'].' [page: '.$s['page'].($s['ref'] !== null ? ', ref: '.$s['ref'] : '').']', $plan));
        }

        return implode("\n", $lines)."\n\nPregunta: ".$question;
    }

    /**
     * La respuesta del modelo: el JSON de la salida estructurada o, si llegó texto suelto, ese texto como respuesta.
     *
     * @return array{answer: string, steps: list<array{text: string, page: string|null, ref: string|null, path: string|null}>, action: array{kind: string, prompt: string}}
     */
    private static function parse(AiResult $result): array
    {
        $json = $result->json ?? json_decode($result->text, true);
        if (! is_array($json)) {
            return ['answer' => trim($result->text), 'steps' => [], 'action' => ['kind' => 'none', 'prompt' => '']];
        }
        $steps = [];
        foreach (is_array($json['steps'] ?? null) ? $json['steps'] : [] as $step) {
            if (! is_array($step) || ! is_string($step['text'] ?? null) || trim($step['text']) === '' || count($steps) >= self::MAX_STEPS) {
                continue;
            }
            $link = AssistantPages::link($step['page'] ?? null, $step['ref'] ?? null);
            $steps[] = ['text' => mb_substr(trim($step['text']), 0, 240), 'page' => $link['page'] ?? null, 'ref' => $link['ref'] ?? null, 'path' => $link['path'] ?? null];
        }
        $action = is_array($json['action'] ?? null) ? $json['action'] : [];

        return [
            'answer' => mb_substr(trim(is_string($json['answer'] ?? null) ? $json['answer'] : ''), 0, 2000),
            'steps' => $steps,
            'action' => ['kind' => is_string($action['kind'] ?? null) ? $action['kind'] : 'none', 'prompt' => mb_substr(trim(is_string($action['prompt'] ?? null) ? $action['prompt'] : ''), 0, 300)],
        ];
    }

    /** @param array{answer: string, steps: list<array{text: string}>, action: array{kind: string, prompt: string}} $parsed */
    private static function allText(array $parsed): string
    {
        return implode("\n", [$parsed['answer'], ...array_map(fn (array $s) => $s['text'], $parsed['steps']), $parsed['action']['prompt']]);
    }

    /**
     * @param  array{kind: string, prompt: string}  $action
     * @return list<array{kind: string, prompt: string|null, page: string|null}>
     */
    private static function actions(array $action): array
    {
        return in_array($action['kind'], ['campaign', 'flow'], true) && $action['prompt'] !== ''
            ? [['kind' => $action['kind'], 'prompt' => $action['prompt'], 'page' => null]]
            : [];
    }

    /** @return list<float> */
    private static function numbersOf(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)*/', $text, $m);

        return array_map(fn (string $n) => NumberValidator::parse($n)[0], $m[0]);
    }

    /**
     * @param  array<string, mixed>  $validation
     * @param  list<array{kind: string, prompt: string|null, page: string|null}>  $actions
     * @param  list<array{text: string, page: string|null, ref: string|null, path: string|null}>  $steps
     */
    private function save(AskDataQuery $record, string $status, string $answer, MetricQueryTool $tool, ?AiResult $result, array $validation, array $actions, array $steps): AskDataQuery
    {
        $meta = $tool->lastResult !== null && $tool->lastQuery !== null ? AskYourData::metaFor($tool->lastQuery, $tool->lastResult) : null;
        if ($meta !== null && $tool->lastQuery !== null) {
            $meta['segment_available'] = $this->segments->definition($tool->lastQuery) !== null;
            $meta['source'] = isset($validation['fallback_reason']) ? 'fallback' : 'ai';
        }
        $record->fill([
            'query' => $tool->lastQuery,
            'answer' => $answer,
            'result_meta' => $meta,
            'status' => $status,
            'model' => $result?->model,
            'validation' => $validation,
            'actions' => $actions === [] ? null : $actions,
            'steps' => $steps === [] ? null : $steps,
        ])->save();

        return $record;
    }
}
