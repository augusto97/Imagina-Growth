<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences;

use App\Domain\Consent\Support\ConsentMode;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Database\Query\Builder;

/**
 * Quién se puede subir a una audiencia de anuncios (modulos.md M06,
 * cumplimiento.md §3.3 y §9):
 *
 * - Modo **estricto** elegido en el panel (Consentimiento): solo contactos con
 *   consentimiento `ads` concedido.
 * - **Según el sitio** o **informado**: todos menos quien revocó `ads`
 *   explícitamente.
 * - Siempre fuera: contactos borrados o fusionados, quien no tiene correo ni
 *   teléfono (no hay con qué coincidir) y quien existe solo por la captura
 *   temprana del checkout (su dato sirve para recuperar ese carrito,
 *   cumplimiento.md §3.2).
 *
 * En `auto` no hay una señal por persona de si el sitio tenía banner, así que se
 * sigue la regla del modo elegido, no la del evento [validar con el abogado].
 */
final class AudienceEligibility
{
    public static function requiresExplicitConsent(Workspace $workspace): bool
    {
        return ConsentMode::chosen($workspace) === ConsentMode::STRICT;
    }

    /** Aplica la regla a una consulta con los contactos en el alias `c`. */
    public static function apply(Builder $query, Workspace $workspace): Builder
    {
        $consent = fn (Builder $q, string $status) => $q->selectRaw('1')->from('contact_consents as cc')
            ->whereColumn('cc.contact_id', 'c.id')->where('cc.workspace_id', $workspace->id)
            ->where('cc.channel', 'ads')->where('cc.purpose', 'marketing')->where('cc.status', $status);

        $query->whereNull('c.early_capture_at');

        return self::requiresExplicitConsent($workspace)
            ? $query->whereExists(fn (Builder $q) => $consent($q, 'granted'))
            : $query->whereNotExists(fn (Builder $q) => $consent($q, 'revoked'));
    }
}
