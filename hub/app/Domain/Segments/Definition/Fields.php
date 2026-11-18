<?php

declare(strict_types=1);

namespace App\Domain\Segments\Definition;

/**
 * Catálogo de campos que puede usar el constructor (modulos.md M04). El tipo
 * decide los operadores. La columna es la de `contacts`; nunca viene del cliente.
 */
final class Fields
{
    public const STRING_OPS = ['eq', 'neq', 'in', 'not_in', 'contains', 'starts_with', 'is_set', 'is_not_set'];

    public const ENUM_OPS = ['eq', 'neq', 'in', 'not_in', 'is_set', 'is_not_set'];

    public const NUMBER_OPS = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'between', 'is_set', 'is_not_set'];

    public const DATE_OPS = ['before', 'after', 'between', 'within_last_days', 'more_than_days_ago', 'within_next_days', 'is_set', 'is_not_set'];

    public const TAG_OPS = ['contains_any', 'contains_all', 'not_contains', 'is_set', 'is_not_set'];

    public const BIRTHDAY_OPS = ['this_month', 'within_next_days', 'is_set', 'is_not_set'];

    public const BOOL_OPS = ['is_true', 'is_false', 'is_set', 'is_not_set'];

    /** Estados del ciclo de vida (modelo-de-datos.md §2). */
    public const STAGES = ['visitor', 'subscriber', 'lead', 'customer', 'repeat', 'vip', 'at_risk', 'lost'];

    /**
     * Claves de los 11 segmentos RFM (inteligencia.md §3), en el orden de evaluación.
     */
    public const RFM_SEGMENTS = [
        'champions', 'loyal', 'cant_lose', 'at_risk', 'potential_loyalists', 'new',
        'promising', 'need_attention', 'about_to_sleep', 'hibernating', 'lost',
    ];

    /**
     * Atributos del perfil.
     *
     * @var array<string, array{column: string, type: string, values?: list<string>}>
     */
    public const ATTRIBUTES = [
        'city' => ['column' => 'city', 'type' => 'string'],
        'region_code' => ['column' => 'region_code', 'type' => 'string'],
        'country' => ['column' => 'country', 'type' => 'string'],
        'locale' => ['column' => 'locale', 'type' => 'string'],
        'lifecycle_stage' => ['column' => 'lifecycle_stage', 'type' => 'enum', 'values' => self::STAGES],
        'hdyhau' => ['column' => 'hdyhau', 'type' => 'string'],
        'tags' => ['column' => 'tags', 'type' => 'tags'],
        'birthday' => ['column' => 'birthday', 'type' => 'birthday'],
        'first_seen_at' => ['column' => 'first_seen_at', 'type' => 'date'],
        'last_seen_at' => ['column' => 'last_seen_at', 'type' => 'date'],
    ];

    /**
     * Agregados de compra del contacto (los mantiene RecalculateContactStats).
     *
     * @var array<string, array{column: string, type: string}>
     */
    public const PURCHASE_FIELDS = [
        'orders_count' => ['column' => 'orders_count', 'type' => 'number'],
        'total_spent' => ['column' => 'revenue_total_ws', 'type' => 'number'],
        'aov' => ['column' => 'aov_ws', 'type' => 'number'],
        'refunds_count' => ['column' => 'refunds_count', 'type' => 'number'],
        'coupon_ratio' => ['column' => 'coupon_orders_ratio', 'type' => 'number'],
        'avg_days_between_orders' => ['column' => 'avg_days_between_orders', 'type' => 'number'],
        'first_order_at' => ['column' => 'first_order_at', 'type' => 'date'],
        'last_order_at' => ['column' => 'last_order_at', 'type' => 'date'],
    ];

    /**
     * Campos calculados por inteligencia (RFM nocturno; predicciones en la Fase 4).
     *
     * @var array<string, array{column: string, type: string, values?: list<string>}>
     */
    public const PREDICTIONS = [
        'rfm_segment' => ['column' => 'rfm_segment', 'type' => 'enum', 'values' => self::RFM_SEGMENTS],
        'rfm_r' => ['column' => 'rfm_r', 'type' => 'number'],
        'rfm_f' => ['column' => 'rfm_f', 'type' => 'number'],
        'rfm_m' => ['column' => 'rfm_m', 'type' => 'number'],
        'churn_band' => ['column' => 'churn_band', 'type' => 'enum', 'values' => ['low', 'medium', 'high']],
        'churn_risk' => ['column' => 'churn_risk', 'type' => 'number'],
        'predicted_clv_12m' => ['column' => 'predicted_clv_12m_ws', 'type' => 'number'],
        'expected_next_order_at' => ['column' => 'expected_next_order_at', 'type' => 'date'],
        'engagement_score' => ['column' => 'engagement_score', 'type' => 'number'],
        'price_band' => ['column' => 'price_band', 'type' => 'enum', 'values' => ['low', 'mid', 'high']],
        'preferred_channel' => ['column' => 'preferred_channel', 'type' => 'enum', 'values' => ['whatsapp', 'email', 'sms']],
    ];

    /** Columnas de `events` que se pueden filtrar directo (más rápido que el JSON). */
    public const EVENT_COLUMNS = [
        'product_id', 'order_id', 'path', 'channel', 'utm_source', 'utm_medium', 'utm_campaign',
        'device_type', 'country', 'region', 'city', 'page_type', 'referrer_host',
    ];

    /** Eventos que cuentan como carrito en «carrito abandonado». */
    public const CART_EVENTS = ['cart.item_added', 'cart.updated', 'checkout.started'];

    /** @return list<string> */
    public static function operatorsFor(string $type): array
    {
        return match ($type) {
            'string' => self::STRING_OPS,
            'enum' => self::ENUM_OPS,
            'number' => self::NUMBER_OPS,
            'date' => self::DATE_OPS,
            'tags' => self::TAG_OPS,
            'birthday' => self::BIRTHDAY_OPS,
            'bool' => self::BOOL_OPS,
            default => [],
        };
    }

    /** Tipo de un atributo personalizado (custom_attribute_defs.type) en el catálogo de tipos. */
    public static function customType(string $defType): string
    {
        return match ($defType) {
            'number' => 'number',
            'date' => 'date',
            'bool' => 'bool',
            'enum' => 'enum',
            default => 'string',
        };
    }
}
