<?php

declare(strict_types=1);

namespace App\Support\Net;

use RuntimeException;

/** La URL o el host de salida no es público (o no resuelve). `reason`: url_not_public, dns_unresolved, invalid_url, https_required. */
final class UnsafeUrl extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct(match ($reason) {
            'dns_unresolved' => __('El nombre del servidor no existe o no responde.'),
            'https_required' => __('La URL debe empezar por https://.'),
            'invalid_url' => __('La URL no es válida.'),
            default => __('La dirección debe ser pública: no se aceptan servidores locales ni de redes internas.'),
        });
    }
}
