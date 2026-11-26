<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\AskData;

use App\Analytics\Semantic\Catalog;
use App\Analytics\Semantic\MetricQueryRunner;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\AiResult;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Support\NumberValidator;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * «Pregúntale a tus datos» (inteligencia.md §6.3): pregunta en lenguaje natural →
 * el modelo arma una consulta con `run_metric_query` → el hub la corre → el modelo
 * responde en 2 o 3 líneas. Las cifras de la respuesta se validan contra lo que
 * devolvió la consulta (y la pregunta): si inventa una, se le corrige una vez y,
 * si insiste, se responde con la plantilla sin IA. La tabla y el gráfico salen de
 * los datos, no del modelo.
 */
final class AskYourData
{
    public function __construct(
        private readonly AiGateway $ai,
        private readonly MetricQueryRunner $runner,
        private readonly NumberValidator $validator,
        private readonly SegmentFromQuery $segments,
        private readonly CurrentWorkspace $current,
    ) {}

    public function handle(Workspace $workspace, ?User $user, string $question): AskDataQuery
    {
        return $this->current->run($workspace, function () use ($workspace, $user, $question): AskDataQuery {
            $question = mb_substr(trim($question), 0, 500);
            $tool = new MetricQueryTool($workspace, $this->runner);
            $record = new AskDataQuery(['workspace_id' => $workspace->id, 'user_id' => $user?->id, 'question' => $question]);
            if (! $this->ai->available($workspace->id)) {
                return $this->save($record, 'failed', __('La IA no está disponible ahora (sin configurar o sin presupuesto este mes). Puedes ver estas cifras en Analítica.'), $tool, null, ['fallback_reason' => 'disabled']);
            }

            $request = new AiRequest($workspace->id, 'ask_data', self::system(), [[
                'role' => 'user',
                'content' => self::context($workspace)."\n\nPregunta: ".$question,
            ]]);
            $facts = [self::numbersOf($question)];
            $attempts = [];
            $result = null;
            try {
                for ($try = 1; $try <= 2; $try++) {
                    $result = $this->ai->withTools($request, [$tool->tool()], 4);
                    if ($result->refused()) {
                        throw new AiUnavailable('refusal');
                    }
                    $facts = array_merge($facts, $result->toolFacts());
                    $check = $this->validator->validate($result->text, $facts);
                    $attempts[] = $check->toArray();
                    if ($check->ok()) {
                        $status = $tool->lastResult === null ? 'unsupported' : 'answered';

                        return $this->save($record, $status, trim($result->text), $tool, $result, ['attempts' => $attempts, 'ok' => true]);
                    }
                    $request = $request->followUp($result->text, 'Estas cifras no están en los resultados de la consulta: '.implode(', ', $check->unknown)
                        .'. Responde de nuevo usando solo cifras de los resultados; si no tienes la cifra, dilo sin número.');
                }
                $reason = 'numbers';
            } catch (AiUnavailable $e) {
                $reason = $e->reason;
            }

            $answer = $tool->lastResult !== null ? $this->template($workspace, $tool) : __('No pude responder eso con tus datos. Prueba con una pregunta sobre ventas, pedidos, clientes o tráfico en un rango de fechas.');

            return $this->save($record, $tool->lastResult !== null ? 'answered' : 'failed', $answer, $tool, $result, ['attempts' => $attempts, 'ok' => true, 'fallback_reason' => $reason]);
        });
    }

    /**
     * Vuelve a correr una consulta guardada, sin IA (cifras al día).
     *
     * @return array<string, mixed>
     */
    public function rerun(Workspace $workspace, AskDataQuery $record): array
    {
        $query = MetricQueryTool::normalize((array) $record->query);
        $result = $this->runner->run($workspace, MetricQueryTool::toMetricQuery($workspace, $query));

        return self::meta($query, $result, null);
    }

    public static function system(): string
    {
        $metrics = [];
        foreach (Catalog::METRICS as $key => $m) {
            $metrics[] = "- {$key} ({$m['label']}, {$m['unit']}): {$m['definition']}";
        }
        $dims = [];
        foreach (Catalog::DIMENSIONS as $key => $d) {
            $dims[] = "- {$key}: {$d['label']}";
        }

        return "Eres el analista de datos de una tienda en línea de Latinoamérica y respondes preguntas del dueño sobre su negocio, en español neutro.\n\n"
            ."Para responder usa la herramienta run_metric_query: arma la consulta con las métricas y dimensiones del catálogo, el rango de fechas (en la zona del negocio) y, si sirve, la comparación y filtros. Si la pregunta no se puede responder con estas métricas, dilo y sugiere una pregunta que sí; no inventes.\n\n"
            ."Métricas:\n".implode("\n", $metrics)."\n\nDimensiones (máximo 2):\n".implode("\n", $dims)."\n\n"
            .'Responde en 2 o 3 líneas, sin tablas (el panel muestra la tabla y el gráfico). Usa solo cifras que vengan en los resultados, tal como están o redondeadas. Dinero con $ y puntos de miles ($1.234.567); porcentajes con coma decimal. La conversión viene como proporción (0,0183 = 1,83 %).';
    }

    private static function context(Workspace $workspace): string
    {
        $now = CarbonImmutable::now($workspace->timezone ?: 'UTC');

        $day = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'][$now->dayOfWeek];

        return sprintf('Hoy es %s (%s), zona horaria %s. Moneda: %s. Semana de lunes a domingo.', $now->toDateString(), $day, $workspace->timezone, $workspace->currency);
    }

    /** @return list<float> las cifras que trae la pregunta («top 5», «últimos 30 días») */
    private static function numbersOf(string $text): array
    {
        preg_match_all('/\d+(?:[.,]\d+)?/', $text, $m);

        return array_map(fn (string $n) => NumberValidator::parse($n)[0], $m[0]);
    }

    /**
     * @param  array<string, mixed>  $validation
     */
    private function save(AskDataQuery $record, string $status, string $answer, MetricQueryTool $tool, ?AiResult $result, array $validation): AskDataQuery
    {
        $meta = $tool->lastResult !== null && $tool->lastQuery !== null ? self::meta($tool->lastQuery, $tool->lastResult, null) : null;
        if ($meta !== null) {
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
        ])->save();

        return $record;
    }

    /**
     * La tabla y el gráfico que muestra el panel.
     *
     * @param  array<string, mixed>  $query
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, float|int>, compare_totals: array<string, float|int>|null}  $result
     * @return array<string, mixed>
     */
    private static function meta(array $query, array $result, ?string $source): array
    {
        $dims = (array) $query['dimensions'];
        $visual = match (true) {
            $dims === [] => 'kpi',
            count($dims) === 1 && $dims[0] === 'date' => 'line',
            count($dims) === 1 => 'bar',
            default => 'table',
        };
        $columns = [];
        foreach ($dims as $d) {
            $columns[] = ['key' => $d, 'label' => Catalog::DIMENSIONS[$d]['label'], 'kind' => 'dimension'];
        }
        foreach ((array) $query['metrics'] as $m) {
            $columns[] = ['key' => $m, 'label' => Catalog::METRICS[$m]['label'], 'kind' => 'metric', 'unit' => Catalog::METRICS[$m]['unit']];
        }

        return array_filter([
            'visual' => $visual,
            'columns' => $columns,
            'rows' => array_slice($result['rows'], 0, 100),
            'totals' => $result['totals'],
            'compare_totals' => $result['compare_totals'],
            'source' => $source,
        ], fn ($v) => $v !== null);
    }

    /** La respuesta sin IA: los totales de la consulta, en una línea. */
    private function template(Workspace $workspace, MetricQueryTool $tool): string
    {
        return self::templateFor($workspace, $tool);
    }

    /**
     * La tabla y el gráfico de una consulta (también para el asistente «Pregúntale a la IA»).
     *
     * @param  array<string, mixed>  $query
     * @param  array{rows: list<array<string, mixed>>, totals: array<string, float|int>, compare_totals: array<string, float|int>|null}  $result
     * @return array<string, mixed>
     */
    public static function metaFor(array $query, array $result): array
    {
        return self::meta($query, $result, null);
    }

    /** La respuesta sin IA con los totales de la última consulta de la herramienta (también para el asistente). */
    public static function templateFor(Workspace $workspace, MetricQueryTool $tool): string
    {
        $parts = [];
        foreach ((array) ($tool->lastResult['totals'] ?? []) as $metric => $value) {
            $unit = Catalog::METRICS[$metric]['unit'] ?? 'count';
            $parts[] = (Catalog::METRICS[$metric]['label'] ?? $metric).': '.match ($unit) {
                'money' => Money::format($value, (string) $workspace->currency),
                'percent' => Money::percent((float) $value, 2),
                default => Money::number($value),
            };
        }

        return __('Esto es lo que encontré del :from al :to: :parts.', ['from' => $tool->lastQuery['from'] ?? '', 'to' => $tool->lastQuery['to'] ?? '', 'parts' => implode('; ', $parts)]);
    }
}
