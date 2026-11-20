<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Workspaces\Models\User;
use App\Support\Pii\Pii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

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

    /** POST /api/platform/mail/test — envía en el momento (sin cola) un correo de prueba al superadmin. */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        try {
            Mail::raw(__('Este es un correo de prueba de Imagina Growth. Si lo recibiste, el correo de la plataforma funciona.'), function ($message) use ($user): void {
                $message->to($user->email)->subject(__('Correo de prueba de Imagina Growth'));
            });
        } catch (Throwable $e) {
            report($e);

            return response()->json(['sent' => false, 'message' => __('No se pudo enviar: :error', ['error' => mb_substr($e->getMessage(), 0, 300)])], 422);
        }

        return response()->json(['sent' => true, 'to' => Pii::maskEmail($user->email)]);
    }
}
