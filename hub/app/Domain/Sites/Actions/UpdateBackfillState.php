<?php

declare(strict_types=1);

namespace App\Domain\Sites\Actions;

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

        return $site;
    }
}
