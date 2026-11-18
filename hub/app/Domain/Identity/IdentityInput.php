<?php

declare(strict_types=1);

namespace App\Domain\Identity;

/**
 * Identificadores y datos de perfil extraídos de un evento.
 */
final class IdentityInput
{
    public function __construct(
        public ?string $customerRef = null,
        public ?string $email = null,
        public ?string $phone = null,          // tal como llegó; se normaliza a E.164
        public ?string $anonymousId = null,
        public ?string $linkToken = null,       // `_igr` de un enlace rastreado (eventos.md §6.2, n.º 5)
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $city = null,
        public ?string $regionCode = null,
        public ?string $country = null,
        public ?string $locale = null,
        public bool $overwriteProfile = false,  // customer.updated reemplaza; el resto solo llena vacíos
    ) {}

    public function hasStrongIdentifier(): bool
    {
        return $this->customerRef !== null || $this->email !== null || $this->phone !== null;
    }

    /**
     * Construye la entrada desde el sobre del evento (arreglo asociativo).
     *
     * @param  array<string, mixed>  $envelope
     */
    public static function fromEnvelope(array $envelope): self
    {
        $identity = $envelope['identity'] ?? [];
        $props = $envelope['properties'] ?? [];
        $event = (string) ($envelope['event'] ?? '');
        $in = new self(
            customerRef: self::str($identity['customer_ref'] ?? null),
            email: self::str($identity['email'] ?? null),
            phone: self::str($identity['phone'] ?? null),
            anonymousId: self::str($identity['anonymous_id'] ?? null),
            linkToken: self::str($identity['link_token'] ?? null),
        );

        $order = $props['order'] ?? null;
        if (is_array($order)) {
            $billing = is_array($order['billing'] ?? null) ? $order['billing'] : [];
            $shipping = is_array($order['shipping'] ?? null) ? $order['shipping'] : [];
            $in->customerRef ??= self::str($order['customer_ref'] ?? null);
            $in->email ??= self::str($billing['email'] ?? null);
            $in->phone ??= self::str($billing['phone'] ?? null);
            $in->firstName = self::str($billing['first_name'] ?? null);
            $in->lastName = self::str($billing['last_name'] ?? null);
            $in->city = self::str($billing['city'] ?? null) ?? self::str($shipping['city'] ?? null);
            $in->regionCode = self::str($billing['state'] ?? null) ?? self::str($shipping['state'] ?? null);
            $in->country = self::str($billing['country'] ?? null) ?? self::str($shipping['country'] ?? null);
        }

        if (str_starts_with($event, 'customer.')) {
            $billing = is_array($props['billing'] ?? null) ? $props['billing'] : [];
            $in->customerRef ??= self::str($props['customer_ref'] ?? null);
            $in->email ??= self::str($props['email'] ?? null) ?? self::str($billing['email'] ?? null);
            $in->phone ??= self::str($props['phone'] ?? null) ?? self::str($billing['phone'] ?? null);
            $in->firstName = self::str($props['first_name'] ?? null) ?? self::str($billing['first_name'] ?? null);
            $in->lastName = self::str($props['last_name'] ?? null) ?? self::str($billing['last_name'] ?? null);
            $in->city = self::str($billing['city'] ?? null);
            $in->regionCode = self::str($billing['state'] ?? null);
            $in->country = self::str($billing['country'] ?? null);
            $in->locale = self::str($props['locale'] ?? null);
            $in->overwriteProfile = $event === 'customer.updated';
        }

        if ($event === 'form.submitted' && is_array($props['fields'] ?? null)) {
            $f = $props['fields'];
            $in->email ??= self::str($f['email'] ?? null);
            $in->phone ??= self::str($f['phone'] ?? null);
            $in->firstName = self::str($f['first_name'] ?? null);
            $in->lastName = self::str($f['last_name'] ?? null);
            $in->city = self::str($f['city'] ?? null);
        }

        return $in;
    }

    private static function str(mixed $v): ?string
    {
        if (! is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
