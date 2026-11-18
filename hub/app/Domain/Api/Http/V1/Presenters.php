<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Commerce\Models\Order;
use App\Domain\Commerce\Models\OrderItem;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Segments\Models\Segment;
use App\Domain\Services\Leads\Models\Lead;
use App\Support\Ids\PrefixedId;
use App\Support\Pii\Pii;
use Carbon\CarbonInterface;

/**
 * Cómo se ve cada recurso en la API pública (docs/api-publica.md §7). IDs con prefijo,
 * fechas ISO 8601 y montos como texto decimal. Sin el alcance `contacts:pii`, correos,
 * teléfonos y nombres van enmascarados.
 */
final class Presenters
{
    /** @return array<string, mixed> */
    public static function contact(Contact $c, bool $pii): array
    {
        $show = fn (?string $v): ?string => $v === null || $v === '' ? $v : ($pii ? $v : Pii::maskString($v));

        return [
            'id' => $c->publicId(),
            'email' => $show($c->email),
            'phone' => $show($c->phone),
            'first_name' => $show($c->first_name),
            'last_name' => $show($c->last_name),
            'city' => $c->getAttribute('city'),
            'region_code' => $c->getAttribute('region_code'),
            'country' => $c->getAttribute('country'),
            'locale' => $c->getAttribute('locale'),
            'lifecycle_stage' => $c->getAttribute('lifecycle_stage'),
            'tags' => array_values((array) ($c->getAttribute('tags') ?? [])),
            'orders_count' => (int) $c->getAttribute('orders_count'),
            'revenue_total' => self::money($c->getAttribute('revenue_total_ws')),
            'last_order_at' => self::date($c->getAttribute('last_order_at')),
            'rfm_segment' => $c->getAttribute('rfm_segment'),
            'churn_band' => $c->getAttribute('churn_band'),
            'lead_score' => $c->getAttribute('lead_score') !== null ? (int) $c->getAttribute('lead_score') : null,
            'points_balance' => (int) $c->getAttribute('points_balance'),
            'first_seen_at' => self::date($c->first_seen_at),
            'last_seen_at' => self::date($c->last_seen_at),
            'created_at' => self::date($c->getAttribute('created_at')),
            'updated_at' => self::date($c->getAttribute('updated_at')),
        ];
    }

    /** @return array<string, mixed> */
    public static function order(Order $o, bool $withItems = false): array
    {
        $data = [
            'id' => $o->publicId(),
            'number' => $o->getAttribute('number'),
            'external_id' => $o->external_id,
            'site_id' => PrefixedId::encode('st', $o->site_id),
            'contact_id' => $o->contact_id !== null ? PrefixedId::encode('ct', $o->contact_id) : null,
            'status' => $o->status,
            'is_paid' => (bool) $o->is_paid,
            'is_cod' => (bool) $o->is_cod,
            'paid_at' => self::date($o->getAttribute('paid_at')),
            'currency' => $o->getAttribute('currency'),
            'subtotal' => self::money($o->getAttribute('subtotal')),
            'discount' => self::money($o->getAttribute('discount')),
            'shipping' => self::money($o->getAttribute('shipping')),
            'tax' => self::money($o->getAttribute('tax')),
            'total' => self::money($o->total),
            'refunded' => self::money($o->refunded),
            'total_ws' => self::money($o->total_ws),
            'payment_method' => $o->getAttribute('payment_method_title'),
            'is_offline_payment' => (bool) $o->getAttribute('is_offline_payment'),
            'shipping_city' => $o->getAttribute('shipping_city'),
            'shipping_region_code' => $o->getAttribute('shipping_region_code'),
            'shipping_country' => $o->getAttribute('shipping_country'),
            'coupon_codes' => array_values((array) ($o->coupon_codes ?? [])),
            'items_count' => (int) $o->getAttribute('items_count'),
            'is_first_order' => (bool) $o->getAttribute('is_first_order'),
            'channel_first' => $o->getAttribute('channel_first'),
            'channel_last' => $o->getAttribute('channel_last'),
            'created_at' => self::date($o->getAttribute('created_at')),
            'updated_at' => self::date($o->getAttribute('updated_at')),
        ];
        if ($withItems) {
            $data['items'] = $o->items->map(fn (OrderItem $i) => [
                'sku' => $i->getAttribute('sku'),
                'name' => $i->getAttribute('name'),
                'product_id' => $i->getAttribute('external_product_id'),
                'variation_id' => $i->getAttribute('external_variation_id'),
                'qty' => (int) $i->getAttribute('qty'),
                'unit_price' => self::money($i->getAttribute('unit_price')),
                'line_total' => self::money($i->getAttribute('line_total')),
            ])->values()->all();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function segment(Segment $s): array
    {
        return [
            'id' => $s->publicId(),
            'name' => $s->name,
            'description' => $s->getAttribute('description'),
            'kind' => $s->getAttribute('kind'),
            'is_system' => (bool) $s->getAttribute('is_system'),
            'member_count' => (int) $s->getAttribute('member_count'),
            'revenue' => self::money($s->getAttribute('revenue_ws')),
            'channel_counts' => $s->getAttribute('channel_counts'),
            'last_computed_at' => self::date($s->getAttribute('last_computed_at')),
            'created_at' => self::date($s->getAttribute('created_at')),
        ];
    }

    /** @return array<string, mixed> */
    public static function lead(Lead $l): array
    {
        return [
            'id' => $l->publicId(),
            'contact_id' => PrefixedId::encode('ct', $l->contact_id),
            'pipeline_id' => $l->pipeline_id !== null ? PrefixedId::encode('lpl', $l->pipeline_id) : null,
            'stage' => $l->stage,
            'service' => $l->service,
            'value' => self::money($l->value_ws),
            'score' => (int) $l->score,
            'temperature' => $l->temperature,
            'origin' => $l->origin,
            'origin_ref' => $l->origin_ref,
            'source' => $l->source,
            'next_step' => $l->next_step,
            'next_step_at' => self::date($l->next_step_at),
            'won_at' => self::date($l->won_at),
            'lost_at' => self::date($l->lost_at),
            'lost_reason' => $l->lost_reason,
            'external_crm_id' => $l->external_crm_id,
            'last_activity_at' => self::date($l->last_activity_at),
            'created_at' => self::date($l->created_at),
            'updated_at' => self::date($l->updated_at),
        ];
    }

    /** @return array<string, mixed> */
    public static function campaign(Campaign $c): array
    {
        return [
            'id' => $c->publicId(),
            'name' => $c->name,
            'channel' => $c->channel,
            'status' => $c->status,
            'audience' => $c->getAttribute('audience'),
            'created_at' => self::date($c->getAttribute('created_at')),
        ];
    }

    public static function date(mixed $value): ?string
    {
        return $value instanceof CarbonInterface ? $value->toIso8601String() : (is_string($value) && $value !== '' ? $value : null);
    }

    public static function money(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) && preg_match('/^-?\d+\.\d{2}$/', $value) === 1 ? $value : number_format((float) $value, 2, '.', '');
    }
}
