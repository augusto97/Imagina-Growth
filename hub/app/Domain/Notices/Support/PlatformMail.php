<?php

declare(strict_types=1);

namespace App\Domain\Notices\Support;

use App\Domain\Billing\Support\PlatformSending;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Messaging\Models\ChannelAccount;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Workspace;

/**
 * El correo de la plataforma (Plataforma → Ajustes → Correo, el de las invitaciones) como respaldo de los avisos
 * (lote 0.4.0, docs/avisos.md §3):
 *
 * - Al **cliente**: un correo transaccional o de utilidad (la confirmación y los avisos de una cita) que no tiene por
 *   dónde salir porque el negocio no conectó su correo, sale por aquí con el nombre del negocio como remitente visible
 *   y su correo de contacto en «Responder a». Nunca marketing. Solo si el plan incluye correo por la plataforma
 *   (`PlatformSending`, como el SES de la plataforma) y si el correo de la plataforma está configurado.
 * - Al **equipo**: los avisos de «Mis avisos» salen siempre por aquí (no dependen de los canales del negocio).
 *
 * El mensaje del cliente queda en `messages` como cualquier otro, con `provider = platform_mail` y sin cuenta del
 * canal: `account()` da una cuenta en memoria (no se guarda) para la política de envío y el driver.
 */
final class PlatformMail
{
    public const PROVIDER = 'platform_mail';

    /** ¿Hay un correo de la plataforma que de verdad envía? (no «Solo registro» ni un remitente de ejemplo). */
    public static function available(): bool
    {
        $mailer = (string) config('mail.default');
        $from = mb_strtolower((string) config('mail.from.address'));

        return $mailer !== '' && $mailer !== 'log' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false && ! str_ends_with($from, '@example.com');
    }

    /** ¿Este mensaje del cliente puede salir por el correo de la plataforma? */
    public static function covers(Workspace $workspace, Channel $channel, MessageKind $kind): bool
    {
        return $channel === Channel::Email && ! $kind->isCommercial() && self::available() && PlatformSending::emailAllowed($workspace);
    }

    /** La cuenta en memoria del correo de la plataforma (no se guarda: los mensajes quedan sin `channel_account_id`). */
    public static function account(Workspace $workspace): ChannelAccount
    {
        $account = new ChannelAccount;
        $account->forceFill([
            'workspace_id' => $workspace->id,
            'channel' => Channel::Email,
            'provider' => self::PROVIDER,
            'display_name' => __('Correo de la plataforma'),
            'identity' => (string) config('mail.from.address'),
            'status' => 'active',
            'is_default' => false,
        ]);

        return $account;
    }

    /** El nombre visible del remitente: el del negocio. */
    public static function fromName(Workspace $workspace): string
    {
        $name = trim(BusinessProfile::for($workspace)['name']);

        return $name !== '' ? $name : (string) $workspace->name;
    }

    /** Dónde llegan las respuestas: el correo de contacto del negocio o, si no hay, el del primer dueño. */
    public static function replyTo(Workspace $workspace): ?string
    {
        $contact = trim(BusinessProfile::for($workspace)['contact']);
        if (filter_var($contact, FILTER_VALIDATE_EMAIL) !== false) {
            return $contact;
        }
        $owner = $workspace->users()->wherePivot('role', WorkspaceRole::Owner->value)->orderBy('workspace_user.created_at')->value('email');

        return is_string($owner) && filter_var($owner, FILTER_VALIDATE_EMAIL) !== false ? $owner : null;
    }
}
