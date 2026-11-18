<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Analyst;

use App\Domain\Flows\Library\FlowLibrary;
use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Features\Models\AiReport;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * Analista semanal con IA (inteligencia.md §6.2): con los KPI de la semana y los 5
 * hallazgos principales escribe, en español y en 250 palabras como máximo, qué pasó,
 * por qué (solo si los hechos lo respaldan) y 3 acciones con su botón. Toda cifra se
 * valida contra los hechos (`NarrativeWriter`); si no pasa, va la plantilla sin IA.
 * Queda en `ai_reports`, uno por semana.
 */
final class WeeklyAnalyst
{
    public const MAX_WORDS = 250;

    /** Rutas del panel a las que puede llevar una acción. */
    public const ROUTES = ['hallazgos', 'analitica', 'segmentos', 'flujos', 'campanas', 'contactos', 'en-el-sitio', 'fidelizacion', 'referidos', 'resenas', 'medicion', 'inteligencia'];

    public function __construct(
        private readonly WeeklyFacts $facts,
        private readonly NarrativeWriter $writer,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array<string, mixed>|null  $baseFacts  los hechos del resumen semanal, si ya se calcularon
     */
    public function handle(Workspace $workspace, CarbonImmutable $weekStart, bool $force = false, ?array $baseFacts = null): AiReport
    {
        return $this->current->run($workspace, function () use ($workspace, $weekStart, $force, $baseFacts): AiReport {
            $existing = AiReport::query()->where('kind', 'weekly')->whereDate('period_start', $weekStart->toDateString())->first();
            if ($existing !== null && ! $force) {
                return $existing;
            }
            $facts = $this->facts->build($workspace, $weekStart, $baseFacts);
            $request = new AiRequest($workspace->id, 'weekly_analyst', self::system(), [[
                'role' => 'user',
                'content' => "Hechos de la semana (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            ]], self::schema());
            $result = $this->writer->write($request, $facts, fn () => self::template($facts));
            $usable = $result->fromAi() && $result->json !== null && trim((string) ($result->json['resumen'] ?? '')) !== '';
            [$body, $actions] = $usable ? $this->fromJson((array) $result->json, $facts) : [$result->fromAi() ? self::template($facts) : $result->text, self::fallbackActions($facts)];
            $source = $usable || ! $result->fromAi() ? $result->source : 'fallback';
            $validation = $result->validation + ($source !== $result->source ? ['fallback_reason' => 'invalid'] : []);

            $report = $existing ?? new AiReport(['workspace_id' => $workspace->id, 'kind' => 'weekly', 'period_start' => $weekStart->toDateString()]);
            $report->fill([
                'period_end' => $weekStart->addDays(6)->toDateString(),
                'facts' => $facts,
                'body_markdown' => $body,
                'actions' => $actions,
                'source' => $source,
                'model' => $result->model,
                'tokens_in' => $result->usage->inputTokens,
                'tokens_out' => $result->usage->outputTokens,
                'validation' => $validation + ['words' => self::words($body)],
            ])->save();

            return $report;
        });
    }

    public static function system(): string
    {
        $routes = implode(', ', self::ROUTES);
        $flows = implode(', ', self::libraryKeys());

        return <<<TXT
        Eres el analista de datos de una tienda en línea de Latinoamérica. Escribes el resumen semanal para el dueño del negocio, en español neutro, claro y directo, de 250 palabras como máximo en total.

        Reglas:
        1. Usa solo cifras que estén en los hechos, tal como están o redondeadas. Los cambios porcentuales ya vienen calculados (cambio_vs_anterior_pct, cambio_vs_promedio_pct): no calcules cifras nuevas ni inventes ninguna.
        2. «resumen»: qué pasó en la semana, con las 2 o 3 cifras más importantes.
        3. «por_que»: las causas solo si los hechos las respaldan (los hallazgos). Si no hay datos para saberlo, dilo sin especular.
        4. «acciones»: exactamente 3, concretas y ordenadas por impacto. Cada una con un botón: tipo «ruta» con una de estas rutas del panel ({$routes}), o tipo «flujo» con la clave de un flujo listo de la biblioteca ({$flows}).
        5. Dinero con el signo $ y puntos de miles ($1.234.567); porcentajes con coma decimal (12,5 %). No incluyas datos personales.
        TXT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resumen' => ['type' => 'string', 'description' => 'Qué pasó en la semana, 2 a 4 frases.'],
                'por_que' => ['type' => 'string', 'description' => 'Causas respaldadas por los hechos, o que no hay datos para saberlo.'],
                'acciones' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'titulo' => ['type' => 'string'],
                            'detalle' => ['type' => 'string'],
                            'tipo' => ['type' => 'string', 'enum' => ['ruta', 'flujo']],
                            'destino' => ['type' => 'string', 'description' => 'La ruta del panel o la clave del flujo.'],
                        ],
                        'required' => ['titulo', 'detalle', 'tipo', 'destino'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['resumen', 'por_que', 'acciones'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $json
     * @param  array<string, mixed>  $facts
     * @return array{0: string, 1: list<array<string, string>>}
     */
    private function fromJson(array $json, array $facts): array
    {
        $actions = [];
        foreach (array_slice((array) ($json['acciones'] ?? []), 0, 3) as $a) {
            if (! is_array($a)) {
                continue;
            }
            $actions[] = self::action((string) ($a['titulo'] ?? ''), (string) ($a['detalle'] ?? ''), (string) ($a['tipo'] ?? ''), (string) ($a['destino'] ?? ''));
        }
        // Siempre 3 acciones: si el modelo dio menos, se completan con las de los hallazgos.
        foreach (self::fallbackActions($facts) as $fallback) {
            if (count($actions) >= 3) {
                break;
            }
            $actions[] = $fallback;
        }
        $body = trim((string) ($json['resumen'] ?? ''))."\n\n**".__('Por qué')."**\n\n".trim((string) ($json['por_que'] ?? ''));
        // Tope de 250 palabras: si se pasa, se recorta la explicación, nunca el resumen.
        if (self::words($body) + self::words(implode(' ', array_column($actions, 'detalle'))) > self::MAX_WORDS) {
            $words = preg_split('/\s+/u', trim((string) ($json['por_que'] ?? ''))) ?: [];
            $budget = max(20, self::MAX_WORDS - self::words((string) ($json['resumen'] ?? '')) - self::words(implode(' ', array_column($actions, 'detalle'))));
            $body = trim((string) ($json['resumen'] ?? ''))."\n\n**".__('Por qué')."**\n\n".implode(' ', array_slice($words, 0, $budget)).(count($words) > $budget ? '…' : '');
        }

        return [$body, $actions];
    }

    /** @return array<string, string> */
    private static function action(string $title, string $detail, string $type, string $target): array
    {
        $target = trim($target, " /\t\n");
        if ($type === 'flujo' && in_array($target, self::libraryKeys(), true)) {
            return ['titulo' => $title, 'detalle' => $detail, 'tipo' => 'flujo', 'destino' => $target, 'ruta' => 'flujos?biblioteca='.$target];
        }
        $root = explode('?', explode('/', $target)[0])[0];
        $route = in_array($root, self::ROUTES, true) && preg_match('#^[a-z0-9\-/?=&_]+$#', $target) === 1 ? $target : 'hallazgos';

        return ['titulo' => $title, 'detalle' => $detail, 'tipo' => 'ruta', 'destino' => $route, 'ruta' => $route];
    }

    /**
     * Las acciones sin IA: las de los hallazgos principales, completadas con las de siempre.
     *
     * @param  array<string, mixed>  $facts
     * @return list<array<string, string>>
     */
    public static function fallbackActions(array $facts): array
    {
        $actions = [];
        foreach ((array) ($facts['hallazgos'] ?? []) as $i) {
            $a = is_array($i) ? ($i['accion'] ?? null) : null;
            if (is_array($a) && isset($a['label'])) {
                $actions[] = self::action((string) $a['label'], (string) ($i['titulo'] ?? ''), 'ruta', (string) ($a['to'] ?? 'hallazgos'));
            }
            if (count($actions) === 3) {
                return $actions;
            }
        }
        $defaults = [
            [__('Revisa tus clientes en riesgo'), __('Prepara una campaña de recuperación para quienes dejaron de comprar.'), 'analitica/clientes'],
            [__('Activa el carrito abandonado'), __('Recupera ventas de quienes llenaron el carrito y no pagaron.'), 'flujos'],
            [__('Mira tus hallazgos'), __('Problemas y oportunidades detectados con su impacto estimado.'), 'hallazgos'],
        ];
        foreach ($defaults as [$t, $d, $r]) {
            if (count($actions) === 3) {
                break;
            }
            $actions[] = self::action($t, $d, 'ruta', $r);
        }

        return $actions;
    }

    /**
     * El texto sin IA, con los mismos hechos.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function template(array $facts): string
    {
        $currency = (string) ($facts['moneda'] ?? 'COP');
        $lines = [];
        foreach ((array) ($facts['indicadores'] ?? []) as $k) {
            $value = match ($k['unidad']) {
                'money' => Money::format($k['semana'], $currency),
                'percent' => Money::percent((float) $k['semana'], 2),
                default => Money::number((float) $k['semana']),
            };
            $change = $k['cambio_vs_anterior_pct'] === null ? '' : ' ('.($k['cambio_vs_anterior_pct'] >= 0 ? '▲ ' : '▼ ')
                .number_format(abs((float) $k['cambio_vs_anterior_pct']), 1, ',', '.').' % '.__('frente a la semana anterior').')';
            $lines[] = '- **'.$k['nombre'].'**: '.$value.$change;
        }
        $insights = array_values(array_filter(array_map(fn ($i) => is_array($i) ? ($i['titulo'] ?? null) : null, (array) ($facts['hallazgos'] ?? []))));

        return __('Así le fue a tu negocio esta semana:')."\n\n".implode("\n", $lines)
            .($insights !== [] ? "\n\n**".__('Lo que encontramos')."**\n\n- ".implode("\n- ", array_slice($insights, 0, 3)) : '');
    }

    public static function words(string $text): int
    {
        return count(preg_split('/\s+/u', trim(strip_tags($text)), -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** @return list<string> */
    private static function libraryKeys(): array
    {
        return array_values(array_map(fn (array $f) => (string) $f['key'], array_filter(FlowLibrary::all(), fn (array $f) => ($f['graph'] ?? null) !== null)));
    }
}
