<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;

/**
 * Quién puede crear una cuenta (docs/auditoria-2026-11.md S3). Por defecto, solo por invitación: los clientes los
 * da de alta la plataforma o la agencia. La primera persona de una instalación nueva siempre puede registrarse
 * (es quien administra), y `IGR_OPEN_REGISTRATION=true` abre el registro a todos.
 */
final class Registration
{
    public static function isOpen(): bool
    {
        return (bool) config('igr.registration_open') || ! User::query()->exists();
    }

    public static function allows(string $email): bool
    {
        if (self::isOpen()) {
            return true;
        }

        return Invitation::withoutWorkspaceScope()
            ->where('email', mb_strtolower(trim($email)))
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();
    }
}
