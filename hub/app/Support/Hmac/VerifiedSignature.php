<?php

declare(strict_types=1);

namespace App\Support\Hmac;

/** Una firma válida: su versión (1 o 2), su marca de tiempo y, en v2, su nonce. */
final readonly class VerifiedSignature
{
    public function __construct(
        public int $version,
        public int $timestamp,
        public ?string $nonce,
    ) {}
}
