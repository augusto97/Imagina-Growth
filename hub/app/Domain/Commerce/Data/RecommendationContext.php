<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Data;

/**
 * Para quién y dónde se recomienda (M16). Los IDs de producto y categoría son los
 * de la tienda (externos). `contactId` y `anonymousId` solo cuentan en los tipos
 * personalizados (`para_ti`, `recently_viewed`, `complete_cart`).
 */
final class RecommendationContext
{
    /**
     * @param  list<string>  $cartProductIds
     * @param  list<string>  $excludeProductIds
     */
    public function __construct(
        public readonly string $siteId,
        public readonly ?string $contactId = null,
        public readonly ?string $anonymousId = null,
        public readonly ?string $productId = null,
        public readonly ?string $categoryId = null,
        public readonly array $cartProductIds = [],
        public readonly array $excludeProductIds = [],
    ) {}
}
