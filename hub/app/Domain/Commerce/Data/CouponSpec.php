<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * Qué cupón crear (nodo «generar cupón» de un flujo, campañas, fidelización).
 * `prefix` va al inicio del código: `VUELVE-7K2Q9X`. Con `restrictToContact`, el
 * cupón solo sirve con el correo del contacto (`email_restrictions`).
 */
final class CouponSpec
{
    /**
     * @param  list<string>  $productIds
     * @param  list<string>  $categoryIds
     */
    public function __construct(
        public readonly string $kind,                 // percent | fixed_cart | fixed_product | free_shipping
        public readonly string $amount,               // "10.00" (porcentaje o monto en la moneda de la tienda)
        public readonly string $sourceType,           // flow | campaign | loyalty | referral | widget | wallet
        public readonly ?string $sourceId = null,
        public readonly ?string $contactId = null,
        public readonly int $expiresInDays = 7,
        public readonly string $prefix = 'IGR',
        public readonly ?string $minimumAmount = null,
        public readonly array $productIds = [],
        public readonly array $categoryIds = [],
        public readonly bool $individualUse = true,
        public readonly bool $restrictToContact = true,
        public readonly ?string $siteId = null,       // null: el primer sitio activo del espacio de trabajo
        public readonly ?string $description = null,
    ) {}
}
