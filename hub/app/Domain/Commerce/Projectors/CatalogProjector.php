<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Projectors;

use App\Domain\Commerce\Models\Product;
use App\Domain\Commerce\Models\ProductCategory;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;

/**
 * Proyección de catalog.* en products y product_categories. Aplica solo
 * eventos más nuevos que el último aplicado (source_updated_at).
 */
final class CatalogProjector
{
    /**
     * @param  array<string, mixed>  $envelope
     */
    public function project(Workspace $workspace, Site $site, array $envelope): void
    {
        $p = $envelope['properties'] ?? [];
        $at = CarbonImmutable::parse((string) $envelope['occurred_at'])->utc();

        match ($envelope['event']) {
            'catalog.product_upserted' => $this->upsertProduct($workspace, $site, $p, $at),
            'catalog.product_deleted' => $this->touchProduct($site, (string) $p['product_id'], $at, ['deleted_at' => $at, 'status' => 'deleted']),
            'catalog.stock_changed' => $this->touchProduct($site, (string) $p['product_id'], $at, array_filter([
                'stock_status' => (string) $p['to_status'],
                'stock_qty' => isset($p['qty']) ? Decimal::of((string) $p['qty']) : null,
            ], fn ($v) => $v !== null)),
            'catalog.price_changed' => $this->touchProduct($site, (string) $p['product_id'], $at, ['price' => Decimal::of((string) $p['to'])]),
            'catalog.category_upserted' => $this->upsertCategory($workspace, $site, $p),
            'catalog.category_deleted' => ProductCategory::query()->where('site_id', $site->id)->where('external_id', (string) $p['category_id'])->update(['deleted_at' => $at]),
            default => null,
        };
    }

    /** @param array<string, mixed> $p */
    private function upsertProduct(Workspace $workspace, Site $site, array $p, CarbonImmutable $at): void
    {
        /** @var Product|null $product */
        $product = Product::query()->where('site_id', $site->id)->where('external_id', (string) $p['product_id'])->first();
        if ($product !== null && $product->source_updated_at !== null && $product->source_updated_at->gt($at)) {
            return;
        }
        $money = fn ($v) => $v === null ? null : Decimal::of((string) $v);
        $attributes = [
            'workspace_id' => $workspace->id,
            'site_id' => $site->id,
            'external_id' => (string) $p['product_id'],
            'parent_external_id' => isset($p['parent_id']) ? (string) $p['parent_id'] : null,
            'type' => (string) $p['type'],
            'sku' => $p['sku'] ?? null,
            'name' => mb_substr((string) $p['name'], 0, 300),
            'slug' => $p['slug'] ?? null,
            'permalink' => $p['permalink'] ?? null,
            'image_url' => $p['image_url'] ?? null,
            'category_ids' => array_values(array_map('strval', (array) ($p['category_ids'] ?? []))),
            'brand' => $p['brand'] ?? null,
            'price' => Decimal::of((string) $p['price']),
            'regular_price' => $money($p['regular_price'] ?? null),
            'sale_price' => $money($p['sale_price'] ?? null),
            'currency' => $p['currency'] ?? $site->currency ?? $workspace->currency,
            'stock_status' => (string) $p['stock_status'],
            'stock_qty' => isset($p['stock_qty']) ? Decimal::of((string) $p['stock_qty']) : null,
            'rating_avg' => $p['rating_avg'] ?? null,
            'rating_count' => $p['rating_count'] ?? null,
            'cost' => $money($p['cost'] ?? null),
            'status' => (string) $p['status'],
            'content_hash' => (string) $p['hash'],
            'source_updated_at' => $at,
            'deleted_at' => null,
        ];
        if ($product === null) {
            Product::query()->create($attributes);
        } else {
            $product->forceFill($attributes)->save();
        }
    }

    /** @param array<string, mixed> $changes */
    private function touchProduct(Site $site, string $externalId, CarbonImmutable $at, array $changes): void
    {
        $product = Product::query()->where('site_id', $site->id)->where('external_id', $externalId)->first();
        if ($product === null || ($product->source_updated_at !== null && $product->source_updated_at->gt($at))) {
            return;
        }
        $product->forceFill($changes + ['source_updated_at' => $at])->save();
    }

    /** @param array<string, mixed> $p */
    private function upsertCategory(Workspace $workspace, Site $site, array $p): void
    {
        ProductCategory::query()->updateOrCreate(
            ['site_id' => $site->id, 'external_id' => (string) $p['category_id']],
            [
                'workspace_id' => $workspace->id,
                'parent_external_id' => isset($p['parent_id']) ? (string) $p['parent_id'] : null,
                'name' => mb_substr((string) $p['name'], 0, 200),
                'slug' => $p['slug'] ?? null,
                'content_hash' => hash('sha256', json_encode([$p['name'], $p['slug'] ?? null, $p['parent_id'] ?? null]) ?: ''),
                'deleted_at' => null,
            ],
        );
    }
}
