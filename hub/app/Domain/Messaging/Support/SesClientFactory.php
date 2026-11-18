<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Support;

use Aws\SesV2\SesV2Client;

/**
 * Cliente de SES v2 de la plataforma. Las pruebas lo reemplazan en el contenedor
 * por uno con Aws\MockHandler (sin red).
 */
class SesClientFactory
{
    public function make(): SesV2Client
    {
        $key = config('igr-messaging.ses.key');
        $secret = config('igr-messaging.ses.secret');

        return new SesV2Client(array_filter([
            'version' => '2019-09-27',
            'region' => (string) config('igr-messaging.ses.region'),
            'credentials' => is_string($key) && $key !== '' && is_string($secret) ? ['key' => $key, 'secret' => $secret] : null,
        ], fn ($v) => $v !== null));
    }
}
