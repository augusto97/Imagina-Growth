<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Assistant;

use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Data\AiTool;
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

/**
 * «Pregúntale a la IA» desde cualquier pantalla (inteligencia.md §6.3): la misma regla que «Pregúntale a tus datos»
 * (el modelo arma consultas con `run_metric_query`, el hub las corre y las cifras de la respuesta se validan contra lo
 * que devolvieron) más los hechos de la pantalla donde está la persona (`AssistantContext`) y las últimas preguntas de
 * la conversación. Puede proponer **un** siguiente paso con `suggest_next_step` (borrador de campaña o de
 * automatización con el copiloto, o abrir una pantalla): el panel lo muestra como botón y nada se crea ni se envía sin
 * que la persona lo revise. Si inventa una cifra se le corrige una vez y, si insiste, responde la plantilla sin IA.
 */
final class AiAssistant
{
    /** Pantallas a las que el asistente puede llevar (la SPA sabe su ruta). */
    public const PAGES = ['segments', 'campaigns', 'flows', 'templates', 'insights', 'leads', 'inbox', 'results', 'contacts', 'strategies', 'connections', 'onsite', 'reviews', 'loyalty'];

    public function __construct(
        private readonly AiGateway $ai,
        private readonly MetricQueryRunner $runner,
        private readonly NumberValidator $validator,
        private readonly SegmentFromQuery $segments,
        private readonly AssistantContext $context,
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
        // Los hechos de la pantalla, con los permisos del rol de la petición (antes de `run`, que no lleva rol).
        $context = $this->ai->available($workspace->id) ? $this->context->build($workspace, $kind, $id, (string) $user->id) : null;

        return $this->current->run($workspace, function () use ($workspace, $user, $question, $kind, $context, $history): AskDataQuery {
            $question = mb_substr(trim($question), 0, 500);
            $tool = new MetricQueryTool($workspace, $this->runner);
            $record = new AskDataQuery(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'question' => $question, 'context' => ['kind' => $kind]]);

            if (! $this->ai->available($workspace->id)) {
                $configured = (bool) config('igr-ai.enabled') && (string) config('igr-ai.api_key') !== '';

                return $this->save($record, 'failed', $configured
                    ? __('Este mes ya se usó todo el cupo de IA de tu plan. Vuelve el mes que viene o mejora tu plan; mientras tanto, mira estas cifras en Resultados.')
                    : ((bool) ($user->getAttributes()['is_platform_admin'] ?? false)
                        ? __('La IA está apagada. Enciéndela en Plataforma → Ajustes → IA: pega la clave de Anthropic y guarda.')
                        : __('La IA todavía no está encendida en la plataforma. Pídele a quien la administra que la active.')),
                    $tool, null, ['fallback_reason' => $configured ? 'budget' : 'disabled'], []);
            }

            $history = array_slice(array_values(array_filter($history, fn ($h) => isset($h['question'], $h['answer']) && is_string($h['question']) && is_string($h['answer']))), -3);
            $messages = [];
            foreach ($history as $turn) {
                $messages[] = ['role' => 'user', 'content' => mb_substr($turn['question'], 0, 500)];
                $messages[] = ['role' => 'assistant', 'content' => mb_substr($turn['answer'], 0, 1500)];
            }
            $messages[] = ['role' => 'user', 'content' => self::prompt($workspace, $kind, $context, $question)];
            $request = new AiRequest($workspace->id, 'assistant', self::system(), $messages);

            $suggestions = [];
            $suggest = new AiTool('suggest_next_step', 'Propone a la persona un siguiente paso concreto que el panel muestra como botón. Úsala como máximo una vez y solo si ayuda.', [
                'type' => 'object',
                'properties' => [
                    'kind' => ['type' => 'string', 'enum' => ['campaign', 'flow', 'page'], 'description' => 'campaign: borrador de campaña con el copiloto; flow: borrador de automatización; page: abrir una pantalla.'],
                    'prompt' => ['type' => 'string', 'description' => 'Para campaign o flow: el pedido al copiloto en una frase. Para page: vacío.'],
                    'page' => ['type' => 'string', 'enum' => [...self::PAGES, 'none'], 'description' => 'Para page: la pantalla. Si no, none.'],
                ],
                'required' => ['kind', 'prompt', 'page'],
                'additionalProperties' => false,
            ], function (array $input) use (&$suggestions): array {
                $suggestions[] = $input;

                return ['ok' => true];
            });

            // Los hechos con que se valida: la pregunta, la pantalla, lo que ya se respondió y lo que devuelvan las herramientas.
            $facts = [self::numbersOf($question), $context['facts'] ?? [], array_map(fn ($h) => self::numbersOf($h['answer'].' '.$h['question']), $history)];
            $attempts = [];
            $result = null;
            try {
                for ($try = 1; $try <= 2; $try++) {
                    $suggestions = [];
                    $result = $this->ai->withTools($request, [$tool->tool(), $suggest], 5);
                    if ($result->refused()) {
                        throw new AiUnavailable('refusal');
                    }
                    $check = $this->validator->validate($result->text, [...$facts, ...$result->toolFacts()]);
                    $attempts[] = $check->toArray();
                    if ($check->ok()) {
                        $actions = $this->actions($suggestions, [...$facts, ...$result->toolFacts()]);

                        return $this->save($record, 'answered', trim($result->text), $tool, $result, ['attempts' => $attempts, 'ok' => true], $actions);
                    }
                    $request = $request->followUp($result->text, 'Estas cifras no están en los hechos ni en los resultados: '.implode(', ', $check->unknown)
                        .'. Responde de nuevo usando solo cifras de los hechos o de los resultados; si no tienes la cifra, dilo sin número.');
                }
                $reason = 'numbers';
            } catch (AiUnavailable $e) {
                $reason = $e->reason;
            }

            return $this->save($record, $tool->lastResult !== null ? 'answered' : 'failed', $tool->lastResult !== null
                ? AskYourData::templateFor($workspace, $tool)
                : __('No pude responder eso sin inventar cifras. Prueba con una pregunta sobre ventas, clientes, mensajes o lo que ves en esta pantalla.'),
                $tool, $result, ['attempts' => $attempts, 'ok' => true, 'fallback_reason' => $reason], []);
        });
    }

    public static function system(): string
    {
        return AskYourData::system()."\n\n"
            .'Estás dentro del panel de Imagina Growth como asistente de la persona que lo usa. Si la pregunta es sobre lo que está viendo, '
            .'responde con los «Hechos de la pantalla»; para cifras del negocio usa run_metric_query. Puedes explicar cómo usar el panel en palabras simples. '
            .'Si conviene, propone un siguiente paso con suggest_next_step (borrador de campaña o de automatización con el copiloto, o abrir una pantalla); '
            .'nunca digas que ya creaste, enviaste o activaste algo: la persona lo revisa. Responde en 2 a 4 líneas, en segunda persona y sin jerga.';
    }

    /**
     * @param  array{kind: string, facts: array<string, mixed>}|null  $context
     */
    private static function prompt(Workspace $workspace, string $kind, ?array $context, string $question): string
    {
        $now = CarbonImmutable::now($workspace->timezone ?: 'UTC');
        $lines = [sprintf('Hoy es %s, zona horaria %s. Moneda: %s.', $now->toDateString(), $workspace->timezone, $workspace->currency), 'Pantalla: '.$kind.'.'];
        if ($context !== null) {
            $lines[] = 'Hechos de la pantalla: '.json_encode($context['facts'], JSON_UNESCAPED_UNICODE);
        }

        return implode("\n", $lines)."\n\nPregunta: ".$question;
    }

    /**
     * Los pasos que propuso el modelo, solo si su pedido no trae cifras inventadas. Como mucho uno.
     *
     * @param  list<array<string, mixed>>  $suggestions
     * @param  list<mixed>  $facts
     * @return list<array{kind: string, prompt: string|null, page: string|null}>
     */
    private function actions(array $suggestions, array $facts): array
    {
        foreach ($suggestions as $s) {
            $kind = $s['kind'] ?? null;
            $prompt = mb_substr(trim((string) ($s['prompt'] ?? '')), 0, 300);
            $page = $s['page'] ?? null;
            if (in_array($kind, ['campaign', 'flow'], true) && $prompt !== '' && $this->validator->validate($prompt, $facts)->ok()) {
                return [['kind' => $kind, 'prompt' => $prompt, 'page' => null]];
            }
            if ($kind === 'page' && in_array($page, self::PAGES, true)) {
                return [['kind' => 'page', 'prompt' => null, 'page' => (string) $page]];
            }
        }

        return [];
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
     */
    private function save(AskDataQuery $record, string $status, string $answer, MetricQueryTool $tool, ?AiResult $result, array $validation, array $actions): AskDataQuery
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
        ])->save();

        return $record;
    }
}
