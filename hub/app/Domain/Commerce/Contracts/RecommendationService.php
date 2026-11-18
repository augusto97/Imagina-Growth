<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Contracts;

use App\Domain\Commerce\Data\RecommendationContext;

/**
 * Recomendaciones de productos (M16): las usan el endpoint público `/p/{key}/reco`,
 * los quiz (M12), las plantillas (`recomendaciones.*`, M08) y los flujos.
 *
 * Tipos: `para_ti` (afinidad e historial), `bought_together` (reglas de asociación),
 * `recently_viewed` (historial del visitante), `best_sellers_category`, `trending`
 * (ventas recientes) y `complete_cart`. Solo devuelve productos publicados y con
 * stock. Debe correr dentro del contexto del espacio de trabajo.
 */
interface RecommendationService
{
    public const KINDS = ['para_ti', 'bought_together', 'recently_viewed', 'best_sellers_category', 'trending', 'complete_cart'];

    /**
     * @return list<array{id: string, name: string, price: string, regular_price: string|null, sale_price: string|null, currency: string, image: string|null, url: string|null, rating_avg: float|null, rating_count: int}>
     */
    public function recommend(string $kind, RecommendationContext $context, int $limit = 4): array;
}
