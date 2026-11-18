<?php

declare(strict_types=1);

namespace App\Domain\Sites\Exceptions;

use RuntimeException;

/** Error del canje con un código estable para el plugin (api-connector.md §3.3). */
final class ConnectException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
