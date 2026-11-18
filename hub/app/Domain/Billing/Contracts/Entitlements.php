<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Qué incluye el plan de un espacio de trabajo (M21): módulos activables (Pennant)
 * y límites. Sin plan (por defecto `UnlimitedEntitlements`) todo está permitido;
 * planes y facturación lo reemplazan. Nunca corta de golpe: pasado el límite hay
 * periodo de gracia y aviso (M21).
 *
 * Módulos: onsite, loyalty, referrals, reviews, inbox, ai, ai_agent, services,
 * bookings, quotes, api, mcp, agency_reports, white_label.
 * Límites: orders, contacts, emails, ai_budget_usd, users, api_requests_per_minute.
 */
interface Entitlements
{
    public function allows(Workspace $workspace, string $module): bool;

    /** null = sin límite */
    public function limit(Workspace $workspace, string $key): ?int;

    /** Clave del plan, o null si no tiene. */
    public function planFor(Workspace $workspace): ?string;
}
