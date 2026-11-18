<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Data;

/**
 * Datos que deja una persona en el sitio: popup, formulario, quiz, «avísame»,
 * reseña o regalo de referido. `consents` son las casillas que marcó por canal
 * (`email`, `whatsapp`, `sms`); `source` queda como origen del consentimiento
 * (`widget:wg_…`, `watch:88`, `review:rv_…`).
 */
final class CaptureInput
{
    /**
     * @param  array<string, bool>  $consents
     */
    public function __construct(
        public readonly string $source,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $anonymousId = null,
        public readonly array $consents = [],
        public readonly ?string $pageUrl = null,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly bool $doubleOptInEmail = false,
        public readonly ?string $country = null,
    ) {}
}
