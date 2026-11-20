<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

use App\Domain\Intelligence\Jobs\ProcessImportedHistory;
use App\Domain\Sites\Models\Site;

/** Guarda el progreso de la importación histórica que informa el plugin (api-connector.md §5.3). */
final class UpdateBackfillState
{
    /**
     * @param  array<string, mixed>  $state  validado
     */
    public function handle(Site $site, array $state): Site
    {
        $previous = $site->backfill_state ?? [];
        // La conciliación se conserva si un reporte intermedio no la trae.
        if (! isset($state['reconciliation']) && isset($previous['reconciliation'])) {
            $state['reconciliation'] = $previous['reconciliation'];
        }
        $state['reported_at'] = now()->toIso8601String();

        $site->forceFill(['backfill_state' => $state, 'last_seen_at' => now()])->save();

        // Terminó de importar: atribución, KPI, RFM y hallazgos de toda la historia ya, no hasta la noche.
        if (($state['status'] ?? null) === 'completed' && ($previous['status'] ?? null) !== 'completed') {
            ProcessImportedHistory::dispatch($site->workspace_id)->delay(now()->addMinutes(2));
        }

        return $site;
    }
}
