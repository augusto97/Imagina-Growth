<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Conversions;

/**
 * Lo que sabemos de un evento para enviarlo como conversión, ya extraído del
 * sobre y del perfil. Vive en memoria; los payloads que salen de aquí se
 * encolan cifrados (DeliverConversion es ShouldBeEncrypted).
 */
final class ConversionInput
{
    /**
     * @param  list<array{id: string, name: string|null, price: string|null, quantity: float, category: string|null}>  $items
     */
    public function __construct(
        public readonly string $workspaceId,
        public readonly string $siteId,
        public readonly string $event,
        public readonly string $eventId,
        public readonly int $occurredAt,
        public readonly ?string $sourceUrl,
        public readonly ?string $referrer,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly ?string $contactId,
        public readonly ?string $anonymousId,
        public readonly ?string $sessionId,
        public readonly string $defaultCountry,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $firstName = null,
        public readonly ?string $lastName = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $zip = null,
        public readonly ?string $country = null,
        public readonly ?string $fbp = null,
        public readonly ?string $fbc = null,
        public readonly ?string $gaClientId = null,
        public readonly ?string $ttp = null,
        public readonly ?string $fbclid = null,
        public readonly ?string $gclid = null,
        public readonly ?string $gbraid = null,
        public readonly ?string $wbraid = null,
        public readonly ?string $ttclid = null,
        public readonly ?string $value = null,
        public readonly ?string $currency = null,
        public readonly ?string $orderId = null,
        public readonly array $items = [],
        public readonly ?string $ctwaClid = null,
        public readonly bool $consentGranted = false,
    ) {}

    public function numItems(): int
    {
        return (int) round(array_sum(array_map(fn (array $i) => $i['quantity'], $this->items)));
    }
}
