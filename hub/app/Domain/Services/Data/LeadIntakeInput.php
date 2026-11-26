<?php

declare(strict_types=1);

namespace App\Domain\Services\Data;

final class LeadIntakeInput
{
    /**
     * @param  'form'|'whatsapp'|'messenger'|'instagram'|'quote'|'booking'|'manual'|'api'|'landing'|'meta_lead_ads'|'google_lead_form'  $origin
     * @param  array<string, mixed>  $source  canal, campaña, página, gclid/fbclid
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $contactId,
        public readonly string $origin,
        public readonly ?string $originRef = null,
        public readonly ?string $service = null,
        public readonly ?string $value = null,
        public readonly array $source = [],
        public readonly ?string $siteId = null,
        public readonly ?string $message = null,     // el texto que dejó la persona (para la calificación con IA); no se guarda en claro
    ) {}
}
