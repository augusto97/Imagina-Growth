<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Sending;

use RuntimeException;

/**
 * El SMS ya armado no cabe en las partes permitidas ni sin tildes: no se envía cortado ni partido. Deshace la
 * transacción del despachador (los enlaces rastreados que se hayan creado) y termina en `denied(sms_too_long)`.
 */
final class SmsTooLong extends RuntimeException {}
