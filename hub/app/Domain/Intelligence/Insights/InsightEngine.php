<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use App\Domain\Intelligence\Insights\Detectors\ConversionDropDetector;
use App\Domain\Intelligence\Insights\Detectors\DataDriftDetector;
use App\Domain\Intelligence\Insights\Detectors\LowStockStarsDetector;
use App\Domain\Intelligence\Insights\Detectors\PaymentMethodDetector;
use App\Domain\Intelligence\Insights\Detectors\ValuableCustomersAtRiskDetector;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Corre los detectores de la Fase 1 (modulos.md M17: 5 detectores) y guarda
 * los hallazgos (inteligencia.md §5):
 * - un hallazgo que se repite actualiza sus cifras;
 * - uno descartado no vuelve en 30 días salvo que su impacto empeore > 50 %;
 * - uno abierto que ya no se detecta pasa a «expired».
 */
final class InsightEngine
{
    /** @var list<class-string<Detector>> */
    public const DETECTORS = [
        ConversionDropDetector::class,
        PaymentMethodDetector::class,
        LowStockStarsDetector::class,
        ValuableCustomersAtRiskDetector::class,
        DataDriftDetector::class,
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /** @return array<string, int> hallazgos activos por detector */
    public function run(Workspace $workspace, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now($workspace->timezone ?: 'UTC');

        return $this->current->run($workspace, function () use ($workspace, $now): array {
            $summary = [];
            foreach (self::DETECTORS as $class) {
                $detector = app($class);
                try {
                    $candidates = $detector->detect($workspace, $now);
                } catch (Throwable $e) {
                    Log::warning('igr.insights.detector_failed', ['detector' => $detector->key(), 'workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);

                    continue;
                }
                $summary[$detector->key()] = $this->store($detector->key(), $candidates, $now);
            }

            return $summary;
        });
    }

    /** @param list<InsightCandidate> $candidates */
    private function store(string $key, array $candidates, CarbonImmutable $now): int
    {
        $seen = [];
        foreach ($candidates as $c) {
            $fingerprint = $key.':'.$c->fingerprint;
            $seen[] = $fingerprint;
            /** @var Insight|null $latest */
            $latest = Insight::query()->where('detector_key', $key)->where('fingerprint', $fingerprint)->latest('created_at')->first();
            $attributes = [
                'detector_key' => $key,
                'fingerprint' => $fingerprint,
                'severity' => $c->severity,
                'title' => mb_substr($c->title, 0, 255),
                'facts' => $c->facts,
                'estimated_impact_ws' => $c->impact,
                'impact_assumptions' => $c->assumptions === [] ? null : $c->assumptions,
                'recommended_action' => $c->action,
                'period_start' => $c->periodStart,
                'period_end' => $c->periodEnd,
            ];
            if ($latest !== null && in_array($latest->status, ['new', 'seen'], true)) {
                $latest->forceFill($attributes)->save();

                continue;
            }
            if ($latest !== null && in_array($latest->status, ['dismissed', 'actioned'], true)) {
                $closedAt = $latest->dismissed_at ?? $latest->updated_at;
                $recent = $closedAt !== null && $closedAt->greaterThan($now->subDays(30));
                $worse = $c->impact !== null && $latest->estimated_impact_ws !== null && (float) $c->impact > 1.5 * (float) $latest->estimated_impact_ws;
                if ($recent && ! $worse) {
                    continue;
                }
            }
            Insight::query()->create($attributes + ['status' => 'new']);
        }

        Insight::query()->where('detector_key', $key)->whereIn('status', ['new', 'seen'])
            ->when($seen !== [], fn ($q) => $q->whereNotIn('fingerprint', $seen))
            ->update(['status' => 'expired', 'updated_at' => now()]);

        return count($candidates);
    }
}
