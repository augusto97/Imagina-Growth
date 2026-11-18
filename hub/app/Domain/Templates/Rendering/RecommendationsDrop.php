<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use Illuminate\Support\Facades\DB;
use Keepsuit\Liquid\Attributes\Cache;
use Keepsuit\Liquid\Drop;

/**
 * `recomendaciones.para_ti` y `recomendaciones.mas_vendidos` (modulos.md M08).
 * Perezoso: solo consulta si la plantilla lo usa. Fase 2: «para ti» son los más
 * vendidos de las categorías que el contacto ya compró y que todavía no tiene;
 * sin historial, los más vendidos de la tienda. M16 (Fase 3) lo reemplaza por
 * el motor de recomendaciones.
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
        return $this->present($this->bestSellers([], []));
    }

    /** @return list<array<string, mixed>> */
    #[Cache]
    public function paraTi(): array
    {
        if ($this->contactId === null) {
            return $this->masVendidos();
        }
        $bought = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $this->workspaceId)->where('o.contact_id', $this->contactId)
            ->get(['i.external_product_id', 'i.category_ids']);
        $categories = [];
        foreach ($bought as $row) {
            foreach ((array) json_decode((string) $row->category_ids, true) as $id) {
                $categories[(string) $id] = true;
            }
        }
        $owned = $bought->pluck('external_product_id')->map(fn ($v) => (string) $v)->all();
        $picked = $categories === [] ? [] : $this->bestSellers(array_keys($categories), $owned);

        return $this->present($picked === [] ? $this->bestSellers([], $owned) : $picked);
    }

    /**
     * @param  list<string>  $categories
     * @param  list<string>  $exclude
     * @return list<object>
     */
    private function bestSellers(array $categories, array $exclude): array
    {
        $sales = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
            ->where('o.workspace_id', $this->workspaceId)->where('o.is_paid', true)
            ->where('o.created_at', '>=', now()->subDays(90))
            ->groupBy('i.external_product_id')->selectRaw('i.external_product_id AS pid, SUM(i.qty) AS sold');
        $query = DB::table('products as p')->leftJoinSub($sales, 's', 's.pid', '=', 'p.external_id')
            ->where('p.workspace_id', $this->workspaceId)->where('p.status', 'publish')->whereNull('p.deleted_at')
            ->where('p.stock_status', '!=', 'outofstock')->whereNull('p.parent_external_id')
            ->when($exclude !== [], fn ($q) => $q->whereNotIn('p.external_id', $exclude))
            ->when($categories !== [], function ($q) use ($categories): void {
                $q->where(function ($w) use ($categories): void {
                    foreach (array_slice($categories, 0, 20) as $category) {
                        $w->orWhereJsonContains('p.category_ids', $category);
                    }
                });
            })
            ->orderByRaw('COALESCE(s.sold, 0) DESC')->orderByDesc('p.rating_avg')->limit(self::LIMIT);

        return $query->get(['p.name', 'p.permalink', 'p.image_url', 'p.price', 'p.regular_price', 'p.sale_price'])->all();
    }

    /**
     * @param  list<object>  $rows
     * @return list<array<string, mixed>>
     */
    private function present(array $rows): array
    {
        $e = fn (?string $v): string => $this->escape ? htmlspecialchars((string) $v, ENT_QUOTES) : (string) $v;

        return array_map(fn (object $p) => [
            'nombre' => $e($p->name ?? ''),
            'url' => $e($p->permalink ?? ''),
            'imagen' => $e($p->image_url ?? ''),
            'precio' => (string) ($p->sale_price ?? $p->price ?? ''),
            'precio_normal' => (string) ($p->regular_price ?? $p->price ?? ''),
            'en_oferta' => $p->sale_price !== null && $p->sale_price !== '' && (float) $p->sale_price < (float) ($p->regular_price ?? 0),
        ], $rows);
    }
}
