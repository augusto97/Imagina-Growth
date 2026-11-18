<?php

use App\Analytics\Ch;
use App\Domain\Intelligence\Insights\InsightEngine;
use App\Domain\Intelligence\Models\Insight;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * Ayudas de las pruebas de hallazgos (prefijo «insights»). Las cargan los archivos
 * de tests/Feature/Insights con require_once.
 */

function insightsSetUp($test): void
{
    $test->useIngestInfrastructure();
    $test->owner = $test->userWith2fa();
    $test->ws = $test->workspaceFor($test->owner);
    [$test->site] = $test->connectedSite($test->ws);
    $test->now = CarbonImmutable::now($test->ws->timezone);
}

function insightsOf($test): Collection
{
    return app(CurrentWorkspace::class)->run($test->ws, fn () => Insight::query()->get());
}

/** @param list<string>|null $only */
function insightsRun($test, ?array $only = null, ?CarbonImmutable $now = null, bool $all = true): array
{
    return app(InsightEngine::class)->run($test->ws, $now ?? $test->now, $all, $only);
}

function insightsOrder($test, array $attrs): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('orders')->insert($attrs + ['id' => $id, 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => Str::random(10),
        'status' => 'completed', 'is_paid' => true, 'currency' => 'COP', 'total' => 100000, 'total_ws' => 100000, 'created_at' => now()->subDays(3)]);

    return $id;
}

function insightsItem($test, string $orderId, string $productId, string $name = 'Producto', float $price = 100000): void
{
    DB::table('order_items')->insert(['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'order_id' => $orderId, 'external_product_id' => $productId,
        'name' => $name, 'qty' => 1, 'unit_price' => $price, 'line_total' => $price]);
}

function insightsProduct($test, string $externalId, string $name, float $price = 100000, array $attrs = []): void
{
    DB::table('products')->insert($attrs + ['id' => strtoupper((string) Str::ulid()), 'workspace_id' => $test->ws->id, 'site_id' => $test->site->id, 'external_id' => $externalId,
        'type' => 'simple', 'name' => $name, 'price' => $price, 'stock_status' => 'instock', 'status' => 'publish', 'content_hash' => 'x']);
}

function insightsContact($test, array $attrs = []): string
{
    $id = strtoupper((string) Str::ulid());
    DB::table('contacts')->insert($attrs + ['id' => $id, 'workspace_id' => $test->ws->id, 'lifecycle_stage' => 'customer', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

/** Un evento en ClickHouse. */
function insightsEvent($test, string $event, CarbonImmutable $at, array $properties = [], array $columns = []): array
{
    return $columns + ['workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'event_id' => (string) Str::uuid(), 'event' => $event,
        'occurred_at' => $at->utc()->format('Y-m-d H:i:s'), 'received_at' => now()->utc()->format('Y-m-d H:i:s'), 'source_type' => 'browser',
        'properties' => (object) $properties, 'revenue' => '0'];
}

/** Una sesión en ClickHouse. */
function insightsSession($test, CarbonImmutable $at, bool $bought, array $columns = []): array
{
    return $columns + ['workspace_id' => $test->ws->ch_id, 'site_id' => $test->site->ch_id, 'session_id' => Str::random(12), 'anonymous_id' => Str::random(8),
        'contact_id' => '', 'started_at' => $at->utc()->format('Y-m-d H:i:s'), 'ended_at' => $at->utc()->format('Y-m-d H:i:s'), 'pageviews' => 3,
        'channel' => 'direct', 'device_type' => 'mobile', 'browser' => 'Chrome', 'viewed_product' => 1, 'added_to_cart' => 0, 'started_checkout' => 0,
        'purchased' => $bought ? 1 : 0, 'revenue' => '0'];
}

function insightsCh(string $table, array $rows): void
{
    foreach (array_chunk($rows, 1000) as $chunk) {
        app(Ch::class)->insert($table, $chunk);
    }
}
