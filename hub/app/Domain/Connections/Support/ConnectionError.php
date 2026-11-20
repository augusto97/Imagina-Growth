<?php

declare(strict_types=1);

namespace App\Domain\Connections\Support;

use RuntimeException;

/**
 * Error de una conexión, con un mensaje para el dueño del negocio. `reason` es un código corto que la SPA
 * traduce (`state`, `cancelled`, `exchange`, `scopes`, `expired`, `provider`, `not_configured`, `asset`,
 * `forbidden`); `permanent` dice si hay que volver a conectar (token revocado o vencido) o si fue una falla
 * pasajera del proveedor. El mensaje nunca lleva tokens.
 */
final class ConnectionError extends RuntimeException
{
    public function __construct(string $message, public readonly string $reason = 'provider', public readonly bool $permanent = false)
    {
        parent::__construct($message);
    }

    public static function reconnect(string $provider): self
    {
        return new self(__('La conexión con :provider se cerró o venció. Vuelve a conectarla en Conexiones.', ['provider' => $provider]), 'expired', true);
    }

    public static function notConfigured(string $provider): self
    {
        return new self(__('Esta instalación todavía no tiene la app de :provider configurada. Quien administra la plataforma debe completarla; mientras tanto puedes pegar los datos a mano.', ['provider' => $provider]), 'not_configured');
    }
}
