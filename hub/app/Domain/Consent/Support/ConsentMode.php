<?php

declare(strict_types=1);

namespace App\Domain\Consent\Support;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Modo de consentimiento de cookies (cumplimiento.md §3.3), elegido en el panel:
 *
 * - `auto` (por defecto): según el sitio. Estricto si tiene banner, informado si no.
 * - `strict`: sin aceptar no hay identificador ni conversiones (sin banner, el plugin muestra el propio).
 * - `informed`: se mide desde la primera visita y se respeta el rechazo explícito.
 *
 * El plugin aplica el mismo criterio en el sitio (ConsentState del connector).
 */
final class ConsentMode
{
    public const AUTO = 'auto';

    public const STRICT = 'strict';

    public const INFORMED = 'informed';

    public const ALL = [self::AUTO, self::STRICT, self::INFORMED];

    public static function chosen(Workspace $workspace): string
    {
        $mode = $workspace->setting('consent_mode', self::AUTO);

        return in_array($mode, self::ALL, true) ? $mode : self::AUTO;
    }

    /**
     * Modo efectivo para un evento, con su `context.consent`. En `auto`, un evento
     * que dice que el sitio no tiene banner (`source: none`) es informado; uno con
     * banner, o sin señal (p. ej. un pedido creado en el admin), es estricto.
     *
     * @param  array<string, mixed>|null  $consent
     */
    public static function effective(Workspace $workspace, ?array $consent): string
    {
        $chosen = self::chosen($workspace);
        if ($chosen !== self::AUTO) {
            return $chosen;
        }

        return ($consent['source'] ?? null) === 'none' ? self::INFORMED : self::STRICT;
    }

    /**
     * ¿Hay consentimiento de marketing para este evento? Estricto: solo un «sí»
     * explícito. Informado: todo menos un «no» explícito.
     *
     * @param  array<string, mixed>|null  $consent
     */
    public static function allowsMarketing(Workspace $workspace, ?array $consent): bool
    {
        $marketing = $consent['marketing'] ?? null;

        return self::effective($workspace, $consent) === self::STRICT ? $marketing === true : $marketing !== false;
    }
}
