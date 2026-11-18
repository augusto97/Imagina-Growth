<?php

declare(strict_types=1);

namespace App\Domain\Services\Contracts;

use App\Domain\Services\Data\LeadIntakeInput;

/**
 * Entrada de leads (M18): la usan el cotizador, la agenda, la API pública y la
 * bandeja para crear o actualizar el lead de un contacto. Idempotente por
 * (origin, originRef). Devuelve el ID del lead o null si el módulo de leads no
 * está activo. Los formularios del sitio entran solos (form.submitted).
 * Por defecto `NullLeadIntake`.
 */
interface LeadIntake
{
    public function capture(LeadIntakeInput $input): ?string;
}
