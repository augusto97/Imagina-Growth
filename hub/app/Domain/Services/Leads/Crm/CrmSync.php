<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Crm;

use App\Domain\Services\Leads\Models\Lead;

/**
 * Envío del lead a un CRM externo (Imagina CRM, Imagina Base o Imagina
 * Proposals, M18). Devuelve el ID del lead en ese sistema (se guarda en
 * `leads.external_crm_id`) o null. Por defecto `NullCrmSync`: los eventos
 * `lead.*` ya salen por los webhooks salientes (M19) para quien los quiera.
 */
interface CrmSync
{
    /** @param 'created'|'stage_changed'|'won'|'lost' $event */
    public function push(Lead $lead, string $event): ?string;
}
