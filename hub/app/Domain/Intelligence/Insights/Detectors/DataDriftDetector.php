<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Detectors;

use App\Domain\Intelligence\Insights\Detector;
use App\Domain\Intelligence\Insights\InsightCandidate;
use App\Domain\Intelligence\Insights\Money;
use App\Domain\Sites\Models\Site;
use App\Domain\Sites\Queries\ReconciliationQuery;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * #20 Desfase de datos: pedidos de WooCommerce frente a pedidos recibidos con
 * una diferencia mayor al 2 % (problema de conexión), según la conciliación
 * del sitio (api-connector.md §5.3).
 */
final class DataDriftDetector implements Detector
{
    public function __construct(private readonly ReconciliationQuery $reconciliation) {}

    public function key(): string
    {
        return 'data_drift';
    }

    public function detect(Workspace $workspace, CarbonImmutable $now): array
    {
        $out = [];
        foreach (Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->get() as $site) {
            $rec = $this->reconciliation->handle($site);
            if (! ($rec['available'] ?? false)) {
                continue;
            }
            $wc = (int) $rec['totals']['wc_count'];
            $hub = (int) $rec['totals']['hub_count'];
            if ($wc === 0) {
                continue;
            }
            $diff = abs($wc - $hub) / $wc;
            if ($diff <= 0.02) {
                continue;
            }
            $out[] = new InsightCandidate(
                fingerprint: $site->id,
                severity: 'critical',
                title: __('Faltan datos de :site: WooCommerce tiene :wc pedidos y el hub :hub', ['site' => $site->name ?? $site->host, 'wc' => Money::number($wc), 'hub' => Money::number($hub)]),
                facts: ['site_id' => $site->publicId(), 'site' => $site->host, 'wc_count' => $wc, 'hub_count' => $hub, 'difference' => round($diff, 4),
                    'rows' => array_values(array_filter($rec['rows'], fn ($r) => ! $r['ok']))],
                assumptions: [__('Se compara la conciliación por estado que calcula el plugin con los pedidos recibidos.')],
                action: ['label' => __('Abre el sitio y revisa la conexión y la importación'), 'to' => 'sitios/'.$site->publicId()],
            );
        }

        return $out;
    }
}
