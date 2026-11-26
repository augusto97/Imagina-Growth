<?php

declare(strict_types=1);

namespace App\Domain\Billing\Platform;

use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * La prueba del correo de la plataforma (Plataforma → Ajustes → Correo): envía en el momento, sin cola, a la dirección
 * que elija el administrador, con un tiempo máximo corto, y dice en palabras qué pasó. Si el servidor lo aceptó, devuelve
 * su respuesta (`250 …`) y el ID del mensaje para buscarlo en el proveedor; si no, el motivo con lo que suele
 * arreglarlo (conexión, usuario y contraseña, remitente no verificado, cifrado) y el detalle técnico. Nunca devuelve
 * credenciales: de la conversación SMTP solo sale la última respuesta del servidor.
 */
final class MailTest
{
    public const TIMEOUT = 20;

    /**
     * @return array{sent: bool, reason?: string, message: string, detail?: string, to: string, from: string, mailer: string, host: string|null, server_response?: string|null, message_id?: string|null}
     */
    public function send(string $to): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        $host = $mailer === 'smtp' ? (string) config('mail.mailers.smtp.host').':'.(string) config('mail.mailers.smtp.port') : null;
        $base = ['to' => $to, 'from' => $from, 'mailer' => $mailer, 'host' => $host];
        if ($from === '' || str_ends_with(mb_strtolower($from), '@example.com')) {
            return $base + ['sent' => false, 'reason' => 'from_missing',
                'message' => __('Falta el correo del remitente: escríbelo en «Correo del remitente», con una dirección de un dominio verificado en tu proveedor de correo, y guarda.')];
        }
        if ($mailer === 'smtp') {
            // Sin tiempo máximo, un puerto bloqueado deja la prueba colgada hasta que el servidor web corta la petición.
            config(['mail.mailers.smtp.timeout' => self::TIMEOUT]);
            Mail::purge('smtp');
        }
        try {
            $sent = Mail::raw(__("Este es un correo de prueba de Imagina Growth.\n\nSi lo recibiste, el correo de la plataforma funciona: con él salen las invitaciones, la recuperación de contraseña, los informes y los avisos."), function ($message) use ($to): void {
                $message->to($to)->subject(__('Correo de prueba de Imagina Growth'));
            });
        } catch (Throwable $e) {
            report($e);

            return $base + ['sent' => false, 'reason' => self::reason($e->getMessage()), 'message' => self::hint(self::reason($e->getMessage()), $from, $host),
                'detail' => mb_substr(self::clean($e->getMessage()), 0, 400)];
        }

        return $base + [
            'sent' => true,
            'message' => $mailer === 'log'
                ? __('El correo está en «Solo registro»: no sale nada, queda en el registro del servidor.')
                : __('El servidor de correo aceptó el mensaje. Si no llega en unos minutos, revisa la carpeta de spam y que el remitente :from esté verificado en tu proveedor.', ['from' => $from]),
            'server_response' => $sent instanceof SentMessage ? self::serverResponse($sent->getSymfonySentMessage()->getDebug()) : null,
            'message_id' => $sent instanceof SentMessage ? $sent->getSymfonySentMessage()->getMessageId() : null,
        ];
    }

    /** Qué tipo de problema dice el error del proveedor. */
    public static function reason(string $error): string
    {
        $e = mb_strtolower($error);

        return match (true) {
            str_contains($e, 'starttls'), str_contains($e, 'crypto'), str_contains($e, 'certificate'), str_contains($e, 'ssl operation'),
            str_contains($e, 'wrong version number') => 'tls',
            str_contains($e, 'connection could not be established'), str_contains($e, 'timed out'), str_contains($e, 'getaddrinfo'),
            str_contains($e, 'connection refused'), str_contains($e, 'unable to connect'), str_contains($e, 'network is unreachable') => 'connection',
            str_contains($e, '535'), str_contains($e, 'authenticat'), str_contains($e, 'username and password'), str_contains($e, 'invalidclienttokenid'),
            str_contains($e, 'signaturedoesnotmatch') => 'auth',
            str_contains($e, 'not verified'), str_contains($e, 'sender'), str_contains($e, 'from address'), str_contains($e, 'not allowed to send'),
            str_contains($e, 'domain'), preg_match('/\b55[0-4]\b/', $e) === 1 => 'sender',
            default => 'other',
        };
    }

    private static function hint(string $reason, string $from, ?string $host): string
    {
        return match ($reason) {
            'connection' => __('No se pudo conectar con el servidor de correo :host. Revisa la dirección y el puerto (casi siempre 587 con STARTTLS o 465 con SSL); algunos servidores bloquean la salida por el 25 y el 465.', ['host' => $host ?? '']),
            'auth' => __('El servidor de correo rechazó el usuario o la contraseña. Cópialos otra vez desde tu proveedor (algunos piden una clave de aplicación o de SMTP, no la del buzón).'),
            'tls' => self::tlsHint(),
            'sender' => __('El servidor de correo no acepta el remitente :from. Verifica ese dominio o esa dirección en tu proveedor de correo.', ['from' => $from]),
            default => __('No se pudo enviar el correo de prueba.'),
        };
    }

    /** El cifrado que no va con el puerto es la causa más común: se dice cuál elegir. */
    private static function tlsHint(): string
    {
        $port = (int) config('mail.mailers.smtp.port');
        $ssl = config('mail.mailers.smtp.scheme') === 'smtps';

        return match (true) {
            $ssl && $port !== 465 => __('Elegiste «SSL/TLS» con el puerto :port, pero ese puerto usa STARTTLS: en «Cifrado» elige «STARTTLS» y guarda (o usa el puerto 465 con «SSL/TLS»).', ['port' => $port]),
            ! $ssl && $port === 465 => __('El puerto 465 usa «SSL/TLS»: en «Cifrado» elige «SSL/TLS» y guarda (o usa el puerto 587 con «STARTTLS»).'),
            default => __('Falló el cifrado con el servidor de correo. Prueba STARTTLS con el puerto 587 o SSL con el 465, según lo que indique tu proveedor.'),
        };
    }

    /** La última respuesta 2xx del servidor (p. ej. `250 2.0.0 Ok: queued as …`), sin nada de lo que mandó el hub. */
    private static function serverResponse(string $debug): ?string
    {
        $last = null;
        foreach (preg_split('/\R/', $debug) ?: [] as $line) {
            if (preg_match('/^<\s*(2\d\d[ -].*)$/', trim($line), $m) === 1) {
                $last = $m[1];
            }
        }

        return $last !== null ? mb_substr(trim($last), 0, 200) : null;
    }

    /**
     * El detalle técnico sin la contraseña ni el usuario que algunos errores repiten. Un usuario que es parte del
     * servidor (Emailit usa «emailit») no es secreto: taparlo dejaba «smtp.***.com».
     */
    private static function clean(string $error): string
    {
        $user = (string) config('mail.mailers.smtp.username');
        $password = (string) config('mail.mailers.smtp.password');
        $host = mb_strtolower((string) config('mail.mailers.smtp.host'));
        $secrets = [$password, base64_encode($password)];
        if ($user !== '' && ! str_contains($host, mb_strtolower($user))) {
            array_push($secrets, $user, base64_encode($user));
        }
        foreach (array_filter($secrets, fn (string $v) => strlen($v) >= 3) as $secret) {
            $error = str_replace($secret, '***', $error);
        }

        return $error;
    }
}
