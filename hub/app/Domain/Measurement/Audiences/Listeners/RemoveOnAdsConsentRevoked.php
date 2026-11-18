<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Listeners;

use App\Domain\Measurement\Audiences\Jobs\RemoveContactFromAudiencesJob;
use App\Ingest\Events\EventIngested;
use Illuminate\Support\Facades\DB;

/** Revocar el consentimiento de anuncios saca a la persona de las audiencias sin esperar a la sincronización de 12 h. */
final class RemoveOnAdsConsentRevoked
{
    public function handle(EventIngested $event): void
    {
        if ($event->event !== 'consent.revoked' || $event->contactId === null || ($event->envelope['properties']['channel'] ?? null) !== 'ads') {
            return;
        }
        $inAudience = DB::table('audience_members as m')->join('audience_syncs as s', 's.id', '=', 'm.audience_sync_id')
            ->where('s.workspace_id', $event->workspaceId)->where('m.contact_id', $event->contactId)->exists();
        if ($inAudience) {
            RemoveContactFromAudiencesJob::dispatch($event->workspaceId, $event->contactId);
        }
    }
}
