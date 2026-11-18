<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadPipeline;
use Illuminate\Support\Facades\DB;

/** Pipelines de leads: el simple se crea solo la primera vez. Corre con el espacio de trabajo activo. */
final class Pipelines
{
    public static function default(string $workspaceId): LeadPipeline
    {
        $found = LeadPipeline::query()->where('is_default', true)->orderBy('created_at')->first()
            ?? LeadPipeline::query()->orderBy('created_at')->first();
        if ($found !== null) {
            return $found;
        }

        return DB::transaction(fn () => LeadPipeline::query()->create([
            'workspace_id' => $workspaceId,
            'name' => __('Ventas'),
            'stages' => array_map(fn (array $s) => ['key' => $s['key'], 'label' => __($s['label']), 'kind' => $s['kind']], LeadDefaults::STAGES),
            'is_default' => true,
        ]));
    }

    public static function for(Lead $lead): LeadPipeline
    {
        $pipeline = $lead->pipeline_id !== null ? LeadPipeline::query()->find($lead->pipeline_id) : null;

        return $pipeline ?? self::default($lead->workspace_id);
    }

    /** Primera etapa abierta del pipeline (donde entra un lead nuevo o reabierto). */
    public static function firstOpen(LeadPipeline $pipeline): string
    {
        foreach ($pipeline->stages as $stage) {
            if ($stage['kind'] === 'open') {
                return $stage['key'];
            }
        }

        return 'new';
    }

    /** Etapa del tipo pedido (won | lost), la primera que exista. */
    public static function firstOfKind(LeadPipeline $pipeline, string $kind): ?string
    {
        foreach ($pipeline->stages as $stage) {
            if ($stage['kind'] === $kind) {
                return $stage['key'];
            }
        }

        return null;
    }
}
