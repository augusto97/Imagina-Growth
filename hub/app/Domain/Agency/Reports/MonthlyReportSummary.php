<?php

declare(strict_types=1);

namespace App\Domain\Agency\Reports;

use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Data\NarrativeResult;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Insights\Money;

/**
 * El resumen del reporte mensual (M22). La IA solo redacta a partir de los
 * hechos ya calculados; `NarrativeWriter` comprueba que cada cifra exista en
 * ellos y, si no, usa la plantilla sin IA (CLAUDE.md §3.6).
 */
final class MonthlyReportSummary
{
    public const FEATURE = 'monthly_report';

    public function __construct(private readonly NarrativeWriter $writer) {}

    /**
     * @param  array<string, mixed>  $facts
     * @return array{text: string, source: string, validation: array<string, mixed>}
     */
    public function write(string $workspaceId, array $facts): array
    {
        $request = AiRequest::ask($workspaceId, self::FEATURE, self::system(),
            "Hechos del mes (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), self::schema());
        $result = $this->writer->write($request, $facts, fn (): string => self::template($facts));

        return ['text' => $this->text($result, $facts), 'source' => $result->source, 'validation' => $result->validation];
    }

    public static function system(): string
    {
        return <<<'TXT'
        Escribes el resumen del reporte mensual que una agencia de marketing le entrega a su cliente (el dueño de un negocio en Latinoamérica). Español neutro, cálido y concreto; 160 palabras como máximo en total.

        Reglas:
        1. Usa solo cifras que estén en los hechos, tal como están o redondeadas. Los cambios porcentuales ya vienen calculados: no calcules cifras nuevas ni inventes ninguna.
        2. «resumen»: cuánto dinero generó la plataforma en el mes y cómo se compara con el mes anterior, en 2 o 3 frases.
        3. «destacados»: 2 o 3 frases cortas sobre lo que más aportó (módulos, KPI o hallazgos).
        4. «siguiente_paso»: una recomendación concreta para el próximo mes, respaldada por los hallazgos o las predicciones.
        5. Dinero con el signo $ y puntos de miles ($1.234.567); porcentajes con coma decimal (12,5 %). Sin datos personales.
        TXT;
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'resumen' => ['type' => 'string'],
                'destacados' => ['type' => 'array', 'items' => ['type' => 'string']],
                'siguiente_paso' => ['type' => 'string'],
            ],
            'required' => ['resumen', 'destacados', 'siguiente_paso'],
            'additionalProperties' => false,
        ];
    }

    /** @param array<string, mixed> $facts */
    private function text(NarrativeResult $result, array $facts): string
    {
        if (! $result->fromAi()) {
            return $result->text;
        }
        $json = $result->json;
        if ($json === null) {
            return trim($result->text) !== '' ? trim($result->text) : self::template($facts);
        }
        $parts = [trim((string) ($json['resumen'] ?? ''))];
        foreach (array_slice((array) ($json['destacados'] ?? []), 0, 3) as $line) {
            if (is_string($line) && trim($line) !== '') {
                $parts[] = '• '.trim($line);
            }
        }
        if (trim((string) ($json['siguiente_paso'] ?? '')) !== '') {
            $parts[] = __('Para el próximo mes: :step', ['step' => trim((string) $json['siguiente_paso'])]);
        }
        $text = trim(implode("\n\n", array_filter($parts)));

        return $text !== '' ? $text : self::template($facts);
    }

    /**
     * El resumen sin IA, con las mismas cifras.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function template(array $facts): string
    {
        $currency = (string) ($facts['moneda'] ?? 'COP');
        $money = (array) ($facts['dinero_generado'] ?? []);
        $total = (float) ($money['total'] ?? 0);
        $lines = [];
        if ($total > 0) {
            $line = __('En :month la plataforma ayudó a generar :amount en :orders pedidos, el :share del total de la tienda.', [
                'month' => (string) ($facts['mes'] ?? ''),
                'amount' => Money::format($total, $currency),
                'orders' => Money::number((int) ($money['pedidos'] ?? 0)),
                'share' => Money::percent((float) ($money['participacion_pct'] ?? 0) / 100),
            ]);
            $change = $money['cambio_vs_anterior_pct'] ?? null;
            if (is_numeric($change)) {
                $line .= ' '.((float) $change >= 0
                    ? __('Es :pct más que el mes anterior.', ['pct' => Money::percent(abs((float) $change) / 100)])
                    : __('Es :pct menos que el mes anterior.', ['pct' => Money::percent(abs((float) $change) / 100)]));
            }
            $lines[] = $line;
            $top = $money['por_modulo'][0] ?? null;
            if (is_array($top)) {
                $lines[] = '• '.__(':module fue lo que más aportó: :amount.', ['module' => $top['modulo'], 'amount' => Money::format($top['ventas'], $currency)]);
            }
        } else {
            $lines[] = __('En :month todavía no hay ventas atribuidas a la plataforma. Activar los flujos de la biblioteca es el primer paso.', ['month' => (string) ($facts['mes'] ?? '')]);
        }
        $insight = $facts['hallazgos'][0] ?? null;
        if (is_array($insight)) {
            $lines[] = __('Para el próximo mes: :title.', ['title' => rtrim((string) $insight['titulo'], '.')]);
        } elseif ((int) ($facts['predicciones']['clientes_en_riesgo_alto'] ?? 0) > 0) {
            $lines[] = __('Para el próximo mes: recuperar a los :n clientes con riesgo alto de no volver.', ['n' => Money::number((int) $facts['predicciones']['clientes_en_riesgo_alto'])]);
        }

        return implode("\n\n", $lines);
    }
}
