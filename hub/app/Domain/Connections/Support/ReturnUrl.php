<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

/**
 * A dónde vuelve la persona después de autorizar: siempre el dominio del panel (APP_URL) y una pantalla fija de
 * `igr-connections.return_paths`. Solo lleva el resultado (`estado`, `motivo`, el ID público de la conexión),
 * nunca códigos ni tokens. La agenda conserva su parámetro de siempre (`google=ok|cancelado|error`).
 */
final class ReturnUrl
{
    public static function home(): string
    {
        return rtrim((string) config('app.url'), '/').'/';
    }

    public static function invalid(): string
    {
        return self::home().'?conexion=invalida';
    }

    /** @param array<string, string> $extra */
    public static function to(string $workspacePublicId, string $return, string $provider, string $status, array $extra = []): string
    {
        $paths = (array) config('igr-connections.return_paths');
        $path = (string) ($paths[$return] ?? $paths['conexiones'] ?? 'conexiones');
        $query = $return === 'agenda'
            ? ['google' => $status]
            : ['conexion' => $provider, 'estado' => $status] + $extra;

        return self::home().'w/'.$workspacePublicId.'/'.$path.(str_contains($path, '?') ? '&' : '?').http_build_query($query);
    }
}
