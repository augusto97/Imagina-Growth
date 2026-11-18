<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights;

use App\Domain\Intelligence\Models\Insight;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\RedisKey;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Corre los detectores de inteligencia.md §5.1 (`DetectorCatalog`) y guarda los
 * hallazgos:
 * - los nocturnos corren cada noche; los semanales, los lunes (o si nunca
 *   corrieron, o con `$all`);
 * - un hallazgo que se repite actualiza sus cifras y su vigencia;
 * - uno descartado no vuelve en 30 días salvo que su impacto (o su magnitud, si no
 *   tiene impacto en dinero) empeore más de un 50 %;
 * - uno abierto que su detector ya no encuentra, o cuya vigencia venció, pasa a
 *   «expired»;
 * - el resultado de cada detector (corrió, se saltó y por qué, falló, apagado)
 *   queda en caché para la pantalla.
 */
final class InsightEngine
{
    /** Los detectores de la Fase 1, para quien los liste por clase. */
    public const DETECTORS = [
        Detectors\ConversionDropDetector::class,
        Detectors\PaymentMethodDetector::class,
        Detectors\LowStockStarsDetector::class,
        Detectors\ValuableCustomersAtRiskDetector::class,
        Detectors\DataDriftDetector::class,
    ];

    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @param  list<string>|null  $only  claves de detectores (pruebas y `igr:insights --detector`)
     * @return array<string, int> hallazgos activos por detector que corrió
     */
    public function run(Workspace $workspace, ?CarbonImmutable $now = null, bool $all = false, ?array $only = null): array
    {
        $now ??= CarbonImmutable::now($workspace->timezone ?: 'UTC');

        return $this->current->run($workspace, function () use ($workspace, $now, $all, $only): array {
            $summary = [];
            $runs = self::runs($workspace);
            foreach (DetectorCatalog::all() as $entry) {
                $key = $entry['key'];
                if ($only !== null && ! in_array($key, $only, true)) {
                    continue;
                }
                if ($entry['class'] === null) {
                    $runs[$key] = ['status' => 'disabled', 'phase' => $entry['phase'], 'at' => null];

                    continue;
                }
                $last = isset($runs[$key]['at']) ? CarbonImmutable::parse((string) $runs[$key]['at']) : null;
                if ($entry['cadence'] === 'weekly' && ! $all && $only === null && $last !== null && ! $now->isMonday() && $last->greaterThan($now->subDays(7))) {
                    continue;
                }
                $detector = app($entry['class']);
                try {
                    $candidates = $detector->detect($workspace, $now);
                } catch (DetectorSkipped $e) {
                    $runs[$key] = ['status' => 'skipped', 'reason' => $e->getMessage(), 'at' => $now->toIso8601String()];

                    continue;
                } catch (Throwable $e) {
                    Log::warning('igr.insights.detector_failed', ['detector' => $key, 'workspace' => $workspace->id, 'error' => mb_substr($e->getMessage(), 0, 300)]);
                    $runs[$key] = ['status' => 'failed', 'at' => $now->toIso8601String()];

                    continue;
                }
                $summary[$key] = $this->store($key, $entry['cadence'], $candidates, $now);
                $runs[$key] = ['status' => 'ok', 'found' => $summary[$key], 'at' => $now->toIso8601String()];
            }
            Insight::query()->whereIn('status', ['new', 'seen'])->whereNotNull('valid_until')->where('valid_until', '<', $now->utc())
                ->update(['status' => 'expired', 'updated_at' => now()]);
            Cache::put(RedisKey::for($workspace->id, 'insights:runs'), $runs, now()->addDays(30));

            return $summary;
        });
    }

    /** @return array<string, array<string, mixed>> el último resultado de cada detector */
    public static function runs(Workspace $workspace): array
    {
        $runs = Cache::get(RedisKey::for($workspace->id, 'insights:runs'));

        return is_array($runs) ? $runs : [];
    }

    /** @param list<InsightCandidate> $candidates */
    private function store(string $key, string $cadence, array $candidates, CarbonImmutable $now): int
    {
        $seen = [];
        foreach ($candidates as $c) {
            $fingerprint = $key.':'.$c->fingerprint;
            $seen[] = $fingerprint;
            /** @var Insight|null $latest */
            $latest = Insight::query()->where('detector_key', $key)->where('fingerprint', $fingerprint)->latest('created_at')->first();
            $impact = $c->impactValue();
            $facts = $c->facts + ($c->magnitude !== null ? ['magnitude' => round($c->magnitude, 4)] : []);
            $attributes = [
                'detector_key' => $key,
                'fingerprint' => $fingerprint,
                'severity' => $c->severity,
                'title' => mb_substr($c->title, 0, 255),
                'facts' => $facts,
                'estimated_impact_ws' => $impact === null ? null : (string) round($impact, 2),
                'impact_assumptions' => ['formula' => $c->formula?->toArray(), 'notes' => $c->assumptions],
                'recommended_action' => $c->action,
                'period_start' => $c->periodStart,
                'period_end' => $c->periodEnd,
                'valid_until' => $now->addDays($c->validDays ?? ($cadence === 'weekly' ? 14 : 7))->utc(),
            ];
            if ($latest !== null && in_array($latest->status, ['new', 'seen'], true)) {
                $latest->forceFill($attributes)->save();

                continue;
            }
            if ($latest !== null && in_array($latest->status, ['dismissed', 'actioned'], true)) {
                $closedAt = $latest->dismissed_at ?? $latest->updated_at;
                $recent = $closedAt !== null && $closedAt->greaterThan($now->subDays(30));
                if ($recent && ! self::worse($latest, $impact, $c->magnitude)) {
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

    /** Empeoró más de un 50 %: por el impacto en dinero o, sin él, por la magnitud del hallazgo. */
    private static function worse(Insight $latest, ?float $impact, ?float $magnitude): bool
    {
        if ($impact !== null && $latest->estimated_impact_ws !== null && (float) $latest->estimated_impact_ws > 0) {
            return $impact > 1.5 * (float) $latest->estimated_impact_ws;
        }
        $before = $latest->facts['magnitude'] ?? null;

        return $magnitude !== null && is_numeric($before) && (float) $before > 0 && $magnitude > 1.5 * (float) $before;
    }
}
