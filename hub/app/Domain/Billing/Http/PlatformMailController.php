<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Platform\MailTest;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Correo del sistema (invitaciones, recuperación de contraseña, informes, avisos). El instalador lo deja en `log`
 * hasta que el administrador ponga sus datos de SMTP o SES en Plataforma → Ajustes → Correo (F17): el panel lo avisa y
 * permite probarlo.
 */
final class PlatformMailController
{
    /** GET /api/platform/mail */
    public function show(): JsonResponse
    {
        $mailer = (string) config('mail.default');

        return response()->json([
            'configured' => ! in_array($mailer, ['log', 'array'], true),
            'mailer' => $mailer,
            'from_address' => (string) config('mail.from.address'),
            'from_name' => (string) config('mail.from.name'),
        ]);
    }

    /**
     * POST /api/platform/mail/test {to?} — envía en el momento (sin cola) un correo de prueba a `to` o, sin él, al
     * administrador, y dice en palabras qué pasó (MailTest). 422 si no salió.
     */
    public function test(Request $request, MailTest $mail): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $data = $request->validate(['to' => ['nullable', 'email:rfc', 'max:190']]);
        $result = $mail->send($data['to'] ?? $user->email);

        return response()->json($result, $result['sent'] ? 200 : 422);
    }
}
