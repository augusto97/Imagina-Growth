<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences;

use App\Domain\Measurement\Audiences\Models\AudienceMember;
use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Audiences\Platforms\AudiencePlatforms;
use App\Domain\Measurement\Models\AdDestination;
use Illuminate\Support\Facades\Log;

/**
 * Saca a un contacto de todas las audiencias del espacio de trabajo activo, con
 * los identificadores que se le enviaron. Se usa al revocar el consentimiento de
 * anuncios y sirve para la supresión de punta a punta (cumplimiento.md §9,
 * paso 4). Si una plataforma falla, el contacto queda pendiente y la próxima
 * sincronización lo saca (ya no cumple la regla o ya no existe).
 */
final class RemoveContactFromAudiences
{
    public function __construct(private readonly AudiencePlatforms $platforms) {}

    /** @return int audiencias de las que se sacó */
    public function handle(string $contactId): int
    {
        $removed = 0;
        foreach (AudienceSync::query()->whereNotNull('platform_audience_id')->get() as $sync) {
            $member = AudienceMember::query()->where('audience_sync_id', $sync->id)->where('contact_id', $contactId)->first();
            if ($member === null) {
                continue;
            }
            $destination = AdDestination::query()->find($sync->destination_id);
            if ($destination === null) {
                continue;
            }
            $identifiers = (array) $member->identifiers;
            $result = $identifiers === [] ? null : $this->platforms->for($destination->platform)->remove($destination, (string) $sync->platform_audience_id, [$identifiers]);
            if ($result !== null && ! $result->isDelivered()) {
                Log::warning('igr.audiences.remove_failed', ['audience' => $sync->id, 'code' => $result->errorCode]);

                continue;
            }
            AudienceMember::query()->where('audience_sync_id', $sync->id)->where('contact_id', $contactId)->delete();
            $sync->forceFill(['members_count' => max(0, $sync->members_count - 1)])->save();
            $removed++;
        }

        return $removed;
    }
}
