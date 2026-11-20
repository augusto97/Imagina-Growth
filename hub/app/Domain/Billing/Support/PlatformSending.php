<?php

declare(strict_types=1);

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Contracts\Entitlements;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Envío de correo con la cuenta de Amazon SES de la plataforma (S3): lo paga la plataforma, así que solo lo tienen
 * los planes con correos incluidos. `emails = 0` (el plan gratuito Diagnóstico) lo apaga: el cliente puede conectar
 * su propio SMTP. Sin límite (null) o con un número mayor que cero, sí.
 */
final class PlatformSending
{
    public const DENIED = 'plan_no_platform_email';

    public static function emailAllowed(Workspace $workspace): bool
    {
        return app(Entitlements::class)->limit($workspace, 'emails') !== 0;
    }

    public static function message(): string
    {
        return __('Tu plan no incluye el envío de correo por la plataforma. Conecta tu propio servidor de correo (SMTP) o cambia de plan en Plan y facturación.');
    }
}
