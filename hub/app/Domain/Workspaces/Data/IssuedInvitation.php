<?php

declare(strict_types=1);

namespace App\Domain\Workspaces\Data;

use App\Domain\Workspaces\Models\Invitation;

/**
 * Invitación recién emitida con su enlace en claro. El token solo existe aquí (en la base queda su hash): sirve para
 * mostrar «copiar enlace» a quien invitó, por si el correo no llega o la plataforma aún no tiene correo configurado.
 */
final class IssuedInvitation
{
    public function __construct(public readonly Invitation $invitation, public readonly string $url) {}
}
