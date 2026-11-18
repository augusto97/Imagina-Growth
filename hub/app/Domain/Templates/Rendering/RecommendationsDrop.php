<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use App\Domain\Commerce\Contracts\RecommendationService;
use App\Domain\Commerce\Data\RecommendationContext;
use App\Domain\Sites\Models\Site;
use Keepsuit\Liquid\Attributes\Cache;
use Keepsuit\Liquid\Drop;

/**
 * `recomendaciones.para_ti`, `recomendaciones.mas_vendidos` y
 * `recomendaciones.tendencia` (modulos.md M08). Perezoso: solo consulta si la
 * plantilla lo usa. Desde la Fase 3 sale del motor de recomendaciones de M16
 * (RecommendationService) con el primer sitio conectado del espacio de trabajo:
 * «para ti» es la afinidad del contacto (sin lo que ya compró); sin contacto o
 * sin historial, los más vendidos.
 */
final class RecommendationsDrop extends Drop
{
    private const LIMIT = 8;

    public function __construct(
        private readonly string $workspaceId,
        private readonly ?string $contactId,
        private readonly bool $escape,
    ) {}

    /** @return list<array<string, mixed>> */
    #[Cache]
    public function masVendidos(): array
    {
        return $this->present('best_sellers_category', null);
    }

    /** @return list<array<string, mixed>> */
    #[Cache]
    public function paraTi(): array
    {
        return $this->present('para_ti', $this->contactId);
    }

    /** @return list<array<string, mixed>> */
    #[Cache]
    public function tendencia(): array
    {
        return $this->present('trending', null);
    }

    /** @return list<array<string, mixed>> */
    private function present(string $kind, ?string $contactId): array
    {
        $siteId = Site::withoutWorkspaceScope()->where('workspace_id', $this->workspaceId)
            ->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->orderBy('created_at')->value('id');
        if (! is_string($siteId)) {
            return [];
        }
        $products = app(RecommendationService::class)->recommend($kind, new RecommendationContext(siteId: $siteId, contactId: $contactId), self::LIMIT);
        $e = fn (?string $v): string => $this->escape ? htmlspecialchars((string) $v, ENT_QUOTES) : (string) $v;

        return array_map(fn (array $p) => [
            'nombre' => $e($p['name']),
            'url' => $e($p['url']),
            'imagen' => $e($p['image']),
            'precio' => $p['sale_price'] ?? $p['price'],
            'precio_normal' => $p['regular_price'] ?? $p['price'],
            'en_oferta' => $p['sale_price'] !== null && $p['regular_price'] !== null && (float) $p['sale_price'] < (float) $p['regular_price'],
            'calificacion' => $p['rating_avg'],
        ], $products);
    }
}
