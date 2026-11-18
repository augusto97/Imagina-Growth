<?php

declare(strict_types=1);

namespace App\Analytics\Semantic;

/**
 * Capa semántica (hub/CLAUDE.md §10): una sola definición por métrica, la
 * misma en tableros, exportaciones, «Pregúntale a tus datos» (Fase 4) y API.
 * El texto `definition` es el «¿Cómo se calcula?» de inteligencia.md §1.
 */
final class Catalog
{
    /**
     * @var array<string, array{label: string, unit: string, source: string, definition: string}>
     */
    public const METRICS = [
        'revenue' => ['label' => 'Ventas', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Suma del total de los pedidos válidos (estado pagado del sitio; excluye fallidos, cancelados, pendientes y en espera hasta que se pagan) por fecha del pedido, en la moneda del negocio. La contraentrega cuenta cuando se cobra. Es la misma definición que el reporte de WooCommerce por estado pagado.'],
        'orders' => ['label' => 'Pedidos', 'unit' => 'count', 'source' => 'orders',
            'definition' => 'Pedidos válidos: en estado pagado del sitio (por defecto «procesando» y «completado»).'],
        'aov' => ['label' => 'Ticket promedio', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Ventas ÷ pedidos válidos.'],
        'new_customers' => ['label' => 'Clientes nuevos', 'unit' => 'count', 'source' => 'orders',
            'definition' => 'Clientes cuyo primer pedido válido cae en el periodo.'],
        'returning_customers' => ['label' => 'Clientes recurrentes', 'unit' => 'count', 'source' => 'orders',
            'definition' => 'Clientes con un pedido válido en el periodo que ya habían comprado antes.'],
        'cod_pending_orders' => ['label' => 'Contraentregas por confirmar', 'unit' => 'count', 'source' => 'orders',
            'definition' => 'Pedidos contraentrega creados en el periodo que todavía no se cobran (no cuentan como venta).'],
        'cod_pending_revenue' => ['label' => 'Venta por confirmar', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Total de los pedidos contraentrega pendientes de cobro.'],
        'refunded' => ['label' => 'Reembolsos', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Monto reembolsado de los pedidos del periodo.'],
        'discount' => ['label' => 'Descuentos', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Descuentos aplicados a los pedidos válidos.'],
        'net_revenue' => ['label' => 'Ventas netas', 'unit' => 'money', 'source' => 'orders',
            'definition' => 'Ventas − reembolsos − envío − impuestos.'],
        'sessions' => ['label' => 'Sesiones', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Visitas al sitio: una sesión termina tras 30 minutos sin actividad o cuando cambia la campaña.'],
        'users' => ['label' => 'Visitantes', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Navegadores únicos (cookie de primera parte) con al menos una sesión.'],
        'product_views' => ['label' => 'Sesiones con vista de producto', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Sesiones en las que se vio al menos un producto.'],
        'add_to_carts' => ['label' => 'Sesiones con carrito', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Sesiones en las que se agregó al menos un producto al carrito.'],
        'checkouts' => ['label' => 'Sesiones con checkout', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Sesiones que llegaron a la página de pago.'],
        'purchase_sessions' => ['label' => 'Sesiones con compra', 'unit' => 'count', 'source' => 'traffic',
            'definition' => 'Sesiones que terminaron en un pedido.'],
        'conversion_rate' => ['label' => 'Conversión', 'unit' => 'percent', 'source' => 'traffic',
            'definition' => 'Sesiones con compra ÷ sesiones.'],
        'revenue_per_visitor' => ['label' => 'Ingreso por visitante', 'unit' => 'money', 'source' => 'mixed',
            'definition' => 'Ventas ÷ visitantes únicos.'],
    ];

    /** @var array<string, array{label: string, orders: string|null, traffic: string|null}> expresión por fuente */
    public const DIMENSIONS = [
        'date' => ['label' => 'Fecha', 'orders' => 'date', 'traffic' => 'date'],
        'channel' => ['label' => 'Canal', 'orders' => "COALESCE(channel_last, 'unknown')", 'traffic' => 'channel'],
        'device' => ['label' => 'Dispositivo', 'orders' => null, 'traffic' => 'device_type'],
        'city' => ['label' => 'Ciudad', 'orders' => "COALESCE(shipping_city, '')", 'traffic' => 'city'],
        'region' => ['label' => 'Departamento o estado', 'orders' => "COALESCE(shipping_region_code, '')", 'traffic' => 'region'],
        'payment_method' => ['label' => 'Método de pago', 'orders' => "COALESCE(payment_method_title, payment_method_id, '')", 'traffic' => null],
        'site' => ['label' => 'Sitio', 'orders' => 'site_id', 'traffic' => 'site_id'],
    ];

    /** @return array<string, array{label: string, unit: string, source: string, definition: string}> */
    public static function metrics(): array
    {
        return self::METRICS;
    }

    public static function has(string $metric): bool
    {
        return isset(self::METRICS[$metric]);
    }

    public static function source(string $metric): string
    {
        return self::METRICS[$metric]['source'] ?? 'orders';
    }
}
