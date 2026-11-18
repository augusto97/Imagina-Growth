<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Crm;

use App\Domain\Services\Leads\Models\Lead;

/** Sin CRM externo conectado. */
final class NullCrmSync implements CrmSync
{
    public function push(Lead $lead, string $event): ?string
    {
        return null;
    }
}
