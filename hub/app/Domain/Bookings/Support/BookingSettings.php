<?php

declare(strict_types=1);

namespace App\Domain\Bookings\Support;

use App\Domain\Agency\Models\CustomDomain;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Ajustes de la agenda en `workspaces.settings.bookings` (M18).
 */
final class BookingSettings
{
    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'page_title' => null,              // por defecto, «Agenda tu cita con {negocio}»
            'intro' => null,
            'color' => null,                   // #RRGGBB de la página de reservas; sin él, el de la marca
            'ask_email' => true,
            'require_email' => false,
            'consent_channels' => ['email', 'whatsapp'],
            'whatsapp_templates' => [],        // {confirmation|reminder|rescheduled|cancelled: tpl_…} plantillas de utilidad aprobadas
            'auto_no_show' => true,            // sin marcar como cumplida 30 min después del final → no asistió
            'reminder_hours' => [24, 2],
        ];
    }

    /** @return array<string, mixed> */
    public static function for(Workspace $workspace): array
    {
        $saved = (array) (($workspace->settings ?? [])['bookings'] ?? []);

        return array_replace(self::defaults(), array_intersect_key($saved, self::defaults()));
    }

    /** @param array<string, mixed> $values */
    public static function save(Workspace $workspace, array $values): void
    {
        $settings = (array) ($workspace->settings ?? []);
        $settings['bookings'] = array_replace(self::for($workspace), array_intersect_key($values, self::defaults()));
        $workspace->forceFill(['settings' => $settings])->save();
    }

    /** El sitio con el que se registran los contactos de la agenda (el primero conectado). */
    public static function site(Workspace $workspace): ?Site
    {
        return Site::withoutWorkspaceScope()->where('workspace_id', $workspace->id)->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])
            ->orderBy('created_at')->first();
    }

    /** Clave pública con la que se arman los enlaces públicos (sitio, o el espacio de trabajo si no hay sitio). */
    public static function publicKey(Workspace $workspace): string
    {
        return (string) (self::site($workspace)?->getAttribute('public_key') ?? $workspace->publicId());
    }

    public static function pageUrl(Workspace $workspace, ?string $slug = null): string
    {
        return self::origin($workspace).'/p/'.self::publicKey($workspace).'/reservar'.($slug !== null ? '/'.$slug : '');
    }

    public static function manageUrl(Workspace $workspace, string $token): string
    {
        return self::origin($workspace).'/p/'.self::publicKey($workspace).'/cita/'.$token;
    }

    /**
     * Dónde viven las páginas públicas de la agenda: el dominio propio verificado para
     * reservas (M22, `custom_domains.purpose = booking`; el del espacio de trabajo antes
     * que el de toda la cuenta) o, si no hay, el del hub. Caddy emite su certificado
     * porque está verificado (CaddyAskController) y lleva `/p/*` al mismo hub.
     */
    public static function origin(Workspace $workspace): string
    {
        $host = CustomDomain::query()->where('purpose', 'booking')->where('status', 'verified')
            ->where('account_id', (string) $workspace->account_id)
            ->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhereNull('workspace_id'))
            ->orderByRaw('workspace_id IS NULL')->orderBy('verified_at')->value('host');

        return is_string($host) && $host !== '' ? 'https://'.$host : rtrim((string) config('app.url'), '/');
    }
}
