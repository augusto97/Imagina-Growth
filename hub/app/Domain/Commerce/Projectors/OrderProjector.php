<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Projectors;

use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Commerce\Models\Product;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Money\Decimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Proyección de order.* en orders y order_items (hub/CLAUDE.md §5), con
 * upsert por (site_id, external_id). Nunca reemplaza una foto más nueva con
 * una más vieja: compara modified_at (o occurred_at si falta).
 */
final class OrderProjector
{
    /** @var array<string, array<string, string>> sitio => (id externo => id interno) de productos ya vistos */
    private array $productIds = [];

    public function __construct(private readonly FxConverter $fx) {}

    /**
     * @param  array<string, mixed>  $envelope
     * @return Order|null el pedido proyectado (null si no había nada que aplicar)
     */
    public function project(Workspace $workspace, Site $site, array $envelope, ?string $contactId): ?Order
    {
        $event = (string) $envelope['event'];
        $props = $envelope['properties'] ?? [];
        $occurredAt = CarbonImmutable::parse((string) $envelope['occurred_at'])->utc();
        $snapshot = is_array($props['order'] ?? null) ? $props['order'] : null;

        if ($snapshot !== null) {
            return $this->applySnapshot($workspace, $site, $event, $snapshot, $occurredAt, $contactId);
        }

        // Evento de estado sin foto: solo cambia el estado si es más nuevo.
        $externalId = (string) ($props['order_id'] ?? '');
        $order = Order::query()->where('site_id', $site->id)->where('external_id', $externalId)->first();
        if ($order === null) {
            return null;
        }
        $newStatus = match ($event) {
            'order.status_changed' => (string) ($props['to'] ?? $order->status),
            'order.completed' => 'completed',
            'order.cancelled' => 'cancelled',
            'order.failed' => 'failed',
            default => null,
        };
        if ($newStatus === null || ($order->updated_at !== null && $order->updated_at->gt($occurredAt))) {
            return $order;
        }
        $order->status = $newStatus;
        $order->is_paid = $this->isPaid($site, $newStatus, (bool) $order->is_cod);
        if ($order->is_paid && $order->paid_at === null) {
            $order->paid_at = $occurredAt;
        }
        $order->updated_at = $occurredAt;
        $order->projected_at = now();
        $order->save();

        return $order;
    }

    /**
     * @param  array<string, mixed>  $o
     */
    private function applySnapshot(Workspace $workspace, Site $site, string $event, array $o, CarbonImmutable $occurredAt, ?string $contactId): Order
    {
        $settings = $site->effectiveSettings();
        $externalId = (string) $o['order_id'];
        $modifiedAt = isset($o['modified_at']) && is_string($o['modified_at']) ? CarbonImmutable::parse($o['modified_at'])->utc() : $occurredAt;

        // La misma foto (con los mismos ajustes del sitio) no se vuelve a escribir.
        $hash = md5((string) json_encode([$o, $settings['paid_statuses'], $settings['cod_gateways'], $settings['cod_paid_statuses'], $workspace->currency]));

        /** @var Order|null $existing */
        $existing = Order::query()->where('site_id', $site->id)->where('external_id', $externalId)->first();
        if ($existing !== null && $existing->snapshot_hash === $hash) {
            if ($existing->contact_id === null && $contactId !== null) {
                $existing->forceFill(['contact_id' => $contactId])->save();
            }

            return $existing;
        }
        if ($existing !== null && $existing->updated_at !== null && $existing->updated_at->gt($modifiedAt)) {
            // Foto vieja: solo completa el contacto si faltaba.
            if ($existing->contact_id === null && $contactId !== null) {
                $existing->forceFill(['contact_id' => $contactId])->save();
            }

            return $existing;
        }

        $totals = is_array($o['totals'] ?? null) ? $o['totals'] : [];
        $payment = is_array($o['payment_method'] ?? null) ? $o['payment_method'] : [];
        $shipping = is_array($o['shipping'] ?? null) ? $o['shipping'] : [];
        $billing = is_array($o['billing'] ?? null) ? $o['billing'] : [];
        $currency = (string) $o['currency'];
        $status = (string) $o['status'];
        $paymentId = isset($payment['id']) ? (string) $payment['id'] : null;
        $isCod = $paymentId !== null && in_array($paymentId, (array) $settings['cod_gateways'], true);
        $isPaid = $this->isPaid($site, $status, $isCod);

        $paidAt = null;
        if ($isPaid) {
            $paidAt = isset($o['paid_at']) && is_string($o['paid_at'])
                ? CarbonImmutable::parse($o['paid_at'])->utc()
                : ($existing !== null && $existing->paid_at !== null ? $existing->paid_at : ($event === 'order.paid' ? $occurredAt : $modifiedAt));
        }

        $total = Decimal::of($totals['total'] ?? '0');
        $refunded = Decimal::of($totals['refunded'] ?? '0');
        $shippingAmount = Decimal::of($totals['shipping'] ?? '0');
        $tax = Decimal::of($totals['tax'] ?? '0');
        $createdAt = CarbonImmutable::parse((string) $o['created_at'])->utc();
        $rateDate = ($paidAt ?? $createdAt);
        $rate = $this->fx->rate($currency, $workspace->currency, $rateDate);

        /** @var list<array<string, mixed>> $items */
        $items = is_array($o['items'] ?? null) ? array_values($o['items']) : [];
        $costTotal = null;
        $allCost = $items !== [];
        foreach ($items as $item) {
            if (! isset($item['cost'])) {
                $allCost = false;
                break;
            }
            $costTotal = Decimal::add($costTotal ?? '0', Decimal::mul((string) $item['cost'], (string) ($item['qty'] ?? 0)));
        }

        $shipCity = $shipping['city'] ?? null ?: ($billing['city'] ?? null);
        $shipRegion = $shipping['state'] ?? null ?: ($billing['state'] ?? null);
        $shipCountry = $shipping['country'] ?? null ?: ($billing['country'] ?? null);

        $attributes = [
            'workspace_id' => $workspace->id,
            'site_id' => $site->id,
            'external_id' => $externalId,
            'number' => isset($o['number']) ? (string) $o['number'] : $externalId,
            'contact_id' => $contactId ?? $existing?->contact_id,
            'status' => $status,
            'is_paid' => $isPaid,
            'is_cod' => $isCod,
            'paid_at' => $paidAt,
            'currency' => $currency,
            'subtotal' => Decimal::of($totals['subtotal'] ?? '0'),
            'discount' => Decimal::of($totals['discount'] ?? '0'),
            'shipping' => $shippingAmount,
            'tax' => $tax,
            'total' => $total,
            'refunded' => $refunded,
            'total_ws' => $rate === null ? null : Decimal::mul($total, $rate),
            'net_ws' => $rate === null ? null : Decimal::mul(Decimal::sub(Decimal::sub(Decimal::sub($total, $refunded), $shippingAmount), $tax), $rate),
            'cost_ws' => ($rate === null || ! $allCost || $costTotal === null) ? null : Decimal::mul($costTotal, $rate),
            'payment_method_id' => $paymentId,
            'payment_method_title' => isset($payment['title']) ? mb_substr((string) $payment['title'], 0, 200) : null,
            'is_offline_payment' => (bool) ($payment['is_offline'] ?? false) || ($paymentId !== null && in_array($paymentId, (array) $settings['offline_gateways'], true)),
            'shipping_city' => $shipCity !== null ? mb_substr((string) $shipCity, 0, 120) : null,
            'shipping_region_code' => $shipRegion !== null ? mb_substr((string) $shipRegion, 0, 32) : null,
            'shipping_country' => is_string($shipCountry) && strlen($shipCountry) === 2 ? strtoupper($shipCountry) : null,
            'coupon_codes' => array_values(array_map('strval', (array) ($o['coupons'] ?? []))),
            'items_count' => (int) round(array_sum(array_map(fn ($i) => (float) ($i['qty'] ?? 0), $items))),
            'created_via' => isset($o['created_via']) ? mb_substr((string) $o['created_via'], 0, 40) : null,
            'customer_ref' => isset($o['customer_ref']) && is_string($o['customer_ref']) ? $o['customer_ref'] : null,
            'wc_attribution' => is_array($o['wc_attribution'] ?? null) ? $o['wc_attribution'] : null,
            'created_at' => $createdAt,
            'updated_at' => $modifiedAt,
            'projected_at' => now(),
            'snapshot_hash' => $hash,
        ];

        return DB::transaction(function () use ($existing, $attributes, $items, $workspace, $site): Order {
            if ($existing === null) {
                $order = Order::query()->create($attributes);
            } else {
                $existing->forceFill($attributes)->save();
                $order = $existing;
                OrderItem::query()->where('order_id', $order->id)->delete();
            }
            $this->insertItems($workspace, $site, $order, $items);

            return $order;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function insertItems(Workspace $workspace, Site $site, Order $order, array $items): void
    {
        if ($items === []) {
            return;
        }
        $externalIds = [];
        foreach ($items as $item) {
            $externalIds[] = (string) $item['product_id'];
            if (! empty($item['variation_id'])) {
                $externalIds[] = (string) $item['variation_id'];
            }
        }
        $known = $this->productIds[$site->id] ?? [];
        $missing = array_values(array_diff(array_unique($externalIds), array_keys($known)));
        if ($missing !== []) {
            foreach (Product::query()->where('site_id', $site->id)->whereIn('external_id', $missing)->pluck('id', 'external_id') as $ext => $id) {
                $known[(string) $ext] = (string) $id;
            }
            $this->productIds[$site->id] = count($known) > 50_000 ? [] : $known;
        }
        $products = collect($known);

        $rows = [];
        foreach ($items as $item) {
            $variation = ! empty($item['variation_id']) ? (string) $item['variation_id'] : null;
            $rows[] = [
                'id' => strtoupper((string) Str::ulid()),
                'workspace_id' => $workspace->id,
                'order_id' => $order->id,
                'product_id' => ($variation !== null ? $products->get($variation) : null) ?? $products->get((string) $item['product_id']),
                'external_item_id' => isset($item['item_id']) ? (string) $item['item_id'] : null,
                'external_product_id' => (string) $item['product_id'],
                'external_variation_id' => $variation,
                'sku' => isset($item['sku']) ? mb_substr((string) $item['sku'], 0, 100) : null,
                'name' => mb_substr((string) ($item['name'] ?? ''), 0, 300),
                'category_ids' => json_encode(array_values(array_map('strval', (array) ($item['category_ids'] ?? [])))),
                'brand' => isset($item['brand']) ? mb_substr((string) $item['brand'], 0, 120) : null,
                'qty' => Decimal::of((string) ($item['qty'] ?? 0)),
                'unit_price' => Decimal::of($item['unit_price'] ?? '0'),
                'line_total' => Decimal::of($item['line_total'] ?? '0'),
                'cost' => isset($item['cost']) ? Decimal::of((string) $item['cost']) : null,
            ];
        }
        OrderItem::query()->insert($rows);
    }

    /**
     * Pedido válido (inteligencia.md §1): estado pagado según el sitio. La
     * contraentrega solo cuenta cuando se cobra (eventos.md §5).
     */
    public function isPaid(Site $site, string $status, bool $isCod): bool
    {
        $settings = $site->effectiveSettings();
        if (! in_array($status, (array) $settings['paid_statuses'], true)) {
            return false;
        }

        return ! $isCod || in_array($status, (array) $settings['cod_paid_statuses'], true);
    }
}
