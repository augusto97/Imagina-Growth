<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Intelligence\Ai\Data\AiRequest;
use App\Domain\Intelligence\Ai\Support\NarrativeWriter;
use App\Domain\Intelligence\Insights\Money;

/**
 * La conclusión del embudo de leads del lunes (función `lead_funnel`, económica): dos o
 * tres frases con dónde se cae la venta y por qué, y qué hacer. **Solo con cifras de los
 * hechos** (`NarrativeWriter`: si cita una cifra que no está, se corrige una vez y luego
 * va el texto sin IA, armado con los mismos hechos).
 */
final class FunnelNarrative
{
    public const FEATURE = 'lead_funnel';

    public function __construct(private readonly NarrativeWriter $writer) {}

    /**
     * @param  array<string, mixed>  $funnel  LeadFunnel::week()
     * @return array{text: string, source: 'ai'|'fallback', facts: array<string, mixed>}
     */
    public function write(string $workspaceId, array $funnel, string $currency): array
    {
        $facts = self::facts($funnel, $currency);
        $request = AiRequest::ask($workspaceId, self::FEATURE, self::system(), "Hechos del embudo (JSON):\n".json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), self::schema());
        $result = $this->writer->write($request, $facts, fn (): string => self::fallback($facts), fn ($r) => (string) ($r->json['conclusion'] ?? $r->text));

        return ['text' => trim($result->text), 'source' => $result->fromAi() ? 'ai' : 'fallback', 'facts' => $facts];
    }

    /**
     * Los hechos con las cifras ya listas para citar (porcentajes, minutos y dinero), sin datos personales.
     *
     * @param  array<string, mixed>  $f
     * @return array<string, mixed>
     */
    public static function facts(array $f, string $currency): array
    {
        $pct = fn (?float $r): ?string => $r === null ? null : Money::percent($r, 0);
        $minutes = fn (?int $s): ?int => $s === null ? null : (int) round($s / 60);
        $stages = array_map(fn (array $s) => array_filter([
            'etapa' => $s['label'],
            'llegaron_esta_semana' => $s['reached'],
            'llegaron_semana_anterior' => $s['previous'],
            'paso_desde_la_etapa_anterior' => $pct($s['conversion']),
            'paso_semana_anterior' => $pct($s['previous_conversion']),
            'cambio_en_puntos' => $s['change_points'],
        ], fn ($v) => $v !== null), (array) $f['stages']);
        $drop = is_array($f['drop'] ?? null) ? [
            'entre' => $f['drop']['from_label'], 'y' => $f['drop']['to_label'], 'pasa' => $pct((float) $f['drop']['conversion']),
            'leads_de_los_ultimos_dias' => $f['drop']['cohort'], 'dias' => $f['drop']['days'],
        ] : null;
        $fr = (array) ($f['first_response']['week'] ?? []);
        $frPrev = (array) ($f['first_response']['previous'] ?? []);

        return array_filter([
            'pipeline' => $f['pipeline']['name'] ?? null,
            'semana' => ($f['week']['from'] ?? '').' a '.($f['week']['to'] ?? ''),
            'etapas' => $stages,
            'donde_se_cae' => $drop,
            'perdidos' => [
                'esta_semana' => $f['lost']['count'] ?? 0,
                'semana_anterior' => $f['lost']['previous'] ?? 0,
                'motivos' => array_map(fn (array $r) => ['motivo' => $r['reason'] ?? __('Sin motivo'), 'leads' => $r['count']], (array) ($f['lost']['reasons'] ?? [])),
                'desde_la_etapa' => array_map(fn (array $r) => ['etapa' => $r['label'], 'leads' => $r['count']], (array) ($f['lost']['by_stage'] ?? [])),
            ],
            'estancados' => array_map(fn (array $s) => ['etapa' => $s['label'], 'leads' => $s['count'], 'mas_de_dias' => $f['stale_days'] ?? 7, 'el_mas_viejo_dias' => $s['max_days']], (array) ($f['stalled'] ?? [])),
            'primera_respuesta' => array_filter([
                'mediana_minutos' => $minutes($fr['median_seconds'] ?? null),
                'mediana_minutos_semana_anterior' => $minutes($frPrev['median_seconds'] ?? null),
                'en_15_minutos_o_menos' => $pct(isset($fr['within_15m_rate']) ? (float) $fr['within_15m_rate'] : null),
                'sin_respuesta' => isset($fr['leads'], $fr['answered']) ? (int) $fr['leads'] - (int) $fr['answered'] : null,
            ], fn ($v) => $v !== null),
            'ganados' => [
                'esta_semana' => $f['won']['week']['count'] ?? 0,
                'valor' => Money::format((float) ($f['won']['week']['value'] ?? 0), $currency),
                'semana_anterior' => $f['won']['previous']['count'] ?? 0,
            ],
        ], fn ($v) => $v !== null && $v !== []);
    }

    public static function system(): string
    {
        return 'Eres el analista comercial de un negocio de servicios o una tienda de Latinoamérica. Con los hechos del embudo de leads de la semana '
            .'escribe para el dueño una conclusión de 2 o 3 frases en español neutro: dónde se cae la venta, por qué según los hechos (motivos de pérdida, leads estancados, tiempo de primera respuesta) '
            .'y una acción concreta para esta semana. Usa solo cifras que estén en los hechos, tal como están; no calcules cifras nuevas. Si los hechos no explican la causa, dilo sin especular. '
            .'Sin datos personales ni nombres de clientes.';
    }

    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return ['type' => 'object', 'properties' => ['conclusion' => ['type' => 'string']], 'required' => ['conclusion'], 'additionalProperties' => false];
    }

    /**
     * La conclusión sin IA, con los mismos hechos.
     *
     * @param  array<string, mixed>  $facts
     */
    public static function fallback(array $facts): string
    {
        $parts = [];
        $first = $facts['etapas'][0] ?? null;
        if (is_array($first)) {
            $parts[] = __('Esta semana llegaron :n consultas (la anterior, :p).', ['n' => $first['llegaron_esta_semana'] ?? 0, 'p' => $first['llegaron_semana_anterior'] ?? 0]);
        }
        if (is_array($facts['donde_se_cae'] ?? null)) {
            $d = $facts['donde_se_cae'];
            $parts[] = __('La venta se cae entre «:a» y «:b»: pasa :rate de los leads de los últimos :days días.', ['a' => $d['entre'], 'b' => $d['y'], 'rate' => $d['pasa'], 'days' => $d['dias']]);
        }
        $reason = $facts['perdidos']['motivos'][0] ?? null;
        if (is_array($reason)) {
            $parts[] = __('El motivo de pérdida más común fue «:reason» (:n).', ['reason' => $reason['motivo'], 'n' => $reason['leads']]);
        }
        $stalled = $facts['estancados'][0] ?? null;
        if (is_array($stalled)) {
            $parts[] = __(':n leads llevan más de :days días quietos en «:stage»: escríbeles desde «Hoy».', ['n' => $stalled['leads'], 'days' => $stalled['mas_de_dias'], 'stage' => $stalled['etapa']]);
        }

        return $parts !== [] ? implode(' ', $parts) : __('Todavía no hay movimiento de leads para sacar conclusiones esta semana.');
    }
}
