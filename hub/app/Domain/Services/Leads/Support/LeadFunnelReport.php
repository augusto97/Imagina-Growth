<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Services\Leads\Models\LeadPipeline;
use App\Domain\Services\Leads\Models\LeadSettings;
use App\Domain\Services\Leads\Queries\LeadFunnel;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;

/**
 * El embudo de leads del lunes para el informe semanal por correo (M05/M18): un embudo
 * por pipeline con movimiento (como máximo 3), cada uno con su conclusión escrita solo
 * con cifras de los hechos. Sin el módulo de servicios, o sin leads, no agrega nada.
 */
final class LeadFunnelReport
{
    public const MAX_PIPELINES = 3;

    public function __construct(
        private readonly LeadFunnel $funnel,
        private readonly FunnelNarrative $narrative,
        private readonly Entitlements $entitlements,
        private readonly CurrentWorkspace $current,
    ) {}

    /** @return list<array<string, mixed>> */
    public function forWeek(Workspace $workspace, CarbonImmutable $weekStart): array
    {
        if (! $this->entitlements->allows($workspace, 'services')) {
            return [];
        }

        return $this->current->run($workspace, function () use ($workspace, $weekStart): array {
            $staleDays = LeadSettings::for($workspace->id)->assistantRules()['stale_days'];
            $out = [];
            foreach (LeadPipeline::query()->orderByDesc('is_default')->orderBy('created_at')->get() as $pipeline) {
                $f = $this->funnel->week($workspace->id, $pipeline, $weekStart->setTimezone($workspace->timezone ?: 'America/Bogota')->startOfDay(), null, $staleDays);
                if (! self::hasMovement($f)) {
                    continue;
                }
                $conclusion = $this->narrative->write($workspace->id, $f, (string) $workspace->currency);
                $out[] = $f + ['conclusion' => ['text' => $conclusion['text'], 'source' => $conclusion['source']]];
                if (count($out) >= self::MAX_PIPELINES) {
                    break;
                }
            }

            return $out;
        });
    }

    /** @param array<string, mixed> $f */
    public static function hasMovement(array $f): bool
    {
        foreach ((array) $f['stages'] as $s) {
            if (($s['reached'] ?? 0) > 0 || ($s['previous'] ?? 0) > 0) {
                return true;
            }
        }

        return ($f['lost']['count'] ?? 0) > 0 || (array) ($f['stalled'] ?? []) !== [];
    }
}
