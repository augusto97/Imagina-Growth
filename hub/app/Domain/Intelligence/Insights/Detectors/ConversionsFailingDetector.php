<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Intelligence\Insights\Support\Data;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #19 Conversiones desde el servidor fallando (más del 10 % de las entregas
 * finales en 7 días, al menos 20) o con pocos datos coincidentes (menos de 2
 * claves en promedio: las plataformas no reconocen a la persona).
 */
final class ConversionsFailingDetector implements Detector
{
    public function key(): string
    {
        return 'conversions_failing';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $since = $now->subDays(7);
        $rows = Ch::forWorkspace($workspace)->select(<<<'SQL'
            SELECT platform, count() AS n, countIf(status = 'failed') AS failed, avg(length(match_keys)) AS keys,
                   anyIf(error, status = 'failed') AS sample_error
            FROM conversion_deliveries
            WHERE workspace_id = {workspace_id:UInt32} AND final = 1 AND test = 0 AND delivered_at >= {since:DateTime64(3)}
            GROUP BY platform
            SQL, ['since' => Data::ch($since)]);
        $maxFail = (float) config('igr-insights.thresholds.conversion_failure_rate', 0.10);
        $minKeys = (float) config('igr-insights.thresholds.match_keys_min', 2.0);
        $out = [];
        foreach ($rows as $r) {
            $n = (int) $r['n'];
            if ($n < 20) {
                continue;
            }
            $platform = (string) $r['platform'];
            $failRate = (int) $r['failed'] / $n;
            $keys = (float) $r['keys'];
            if ($failRate > $maxFail) {
                $out[] = new InsightCandidate(
                    fingerprint: $platform.':failed',
                    severity: $failRate >= 0.3 ? 'critical' : 'warning',
                    title: __('El :rate de las conversiones a :platform está fallando', ['rate' => Money::percent($failRate, 0), 'platform' => __('insight.platform.'.$platform)]),
                    facts: ['platform' => $platform, 'deliveries' => $n, 'failed' => (int) $r['failed'], 'failure_rate' => round($failRate, 4),
                        'sample_error' => mb_substr((string) $r['sample_error'], 0, 200), 'window_days' => 7],
                    assumptions: [__('Sin conversiones, la plataforma optimiza los anuncios a ciegas.')],
                    action: ['label' => __('Revisa el destino en Medición'), 'to' => 'medicion'],
                    periodStart: $since->toDateString(),
                    periodEnd: $now->toDateString(),
                    magnitude: $failRate,
                );
            } elseif ($keys < $minKeys) {
                $out[] = new InsightCandidate(
                    fingerprint: $platform.':match',
                    severity: 'opportunity',
                    title: __('Las conversiones a :platform llevan pocos datos para reconocer a la persona', ['platform' => __('insight.platform.'.$platform)]),
                    facts: ['platform' => $platform, 'deliveries' => $n, 'avg_match_keys' => round($keys, 2), 'min_match_keys' => $minKeys, 'window_days' => 7],
                    assumptions: [__('Pide el correo y el teléfono en el checkout y activa el píxel con las mismas claves.')],
                    action: ['label' => __('Revisa el destino en Medición'), 'to' => 'medicion'],
                    periodStart: $since->toDateString(),
                    periodEnd: $now->toDateString(),
                    magnitude: $minKeys - $keys,
                );
            }
        }

        return $out;
    }
}
