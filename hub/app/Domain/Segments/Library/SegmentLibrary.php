<?php

declare(strict_types=1);

namespace App\Domain\Segments\Library;

use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Biblioteca de segmentos listos en español (modulos.md M04): los 11 de RFM
 * (inteligencia.md §3) y los de uso frecuente se crean en cada espacio de
 * trabajo como segmentos del sistema; las plantillas con parámetros se crean
 * a pedido desde el constructor.
 */
final class SegmentLibrary
{
    /** Nombres y acciones sugeridas de los segmentos RFM (inteligencia.md §3). */
    public const RFM = [
        'champions' => ['Campeones', 'Premiar, pedir reseñas y referidos, acceso anticipado. No regalar descuentos.'],
        'loyal' => ['Leales', 'Subir de nivel VIP y venta cruzada.'],
        'cant_lose' => ['No podemos perderlos', 'Contacto personal y oferta fuerte; aviso al dueño.'],
        'at_risk' => ['En riesgo', 'Recuperación escalonada.'],
        'potential_loyal' => ['Potencialmente leales', 'Invitar al programa de puntos y recomendar productos.'],
        'new' => ['Nuevos', 'Bienvenida y empujón a la segunda compra.'],
        'promising' => ['Prometedores', 'Flujo de segunda compra.'],
        'need_attention' => ['Necesitan atención', 'Oferta por tiempo limitado y novedades.'],
        'about_to_sleep' => ['A punto de dormirse', 'Reactivación con contenido y un beneficio moderado.'],
        'hibernating' => ['Hibernando', 'Campaña puntual de temporada.'],
        'lost' => ['Perdidos', 'Excluir de campañas caras; incluir solo en grandes fechas.'],
    ];

    /**
     * Segmentos del sistema.
     *
     * @return list<array{key: string, name: string, description: string, folder: string, definition: array<string, mixed>}>
     */
    public static function system(): array
    {
        $out = [];
        foreach (self::RFM as $key => [$name, $action]) {
            $out[] = [
                'key' => 'rfm_'.$key,
                'name' => $name,
                'description' => 'Segmento RFM. Acción sugerida: '.$action,
                'folder' => 'RFM',
                'definition' => self::and([['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'eq', 'value' => $key]]),
            ];
        }

        $out[] = [
            'key' => 'one_order_60d',
            'name' => 'Compraron una sola vez hace más de 60 días',
            'description' => 'Clientes de un solo pedido que no han vuelto en 60 días. Ideal para el flujo de segunda compra.',
            'folder' => 'Recompra',
            'definition' => self::and([
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'eq', 'value' => 1],
                ['type' => 'purchase', 'field' => 'last_order_at', 'operator' => 'more_than_days_ago', 'value' => 60],
            ]),
        ];
        $out[] = [
            'key' => 'visits_no_purchase',
            'name' => 'Visitaron 3+ veces este mes y nunca compraron',
            'description' => 'Interés alto sin compra: revisa precio, envío o confianza.',
            'folder' => 'Comportamiento',
            'definition' => self::and([
                ['type' => 'event', 'event' => 'page.viewed', 'filters' => [], 'measure' => 'sessions', 'operator' => 'count_gte', 'value' => 3, 'window' => ['kind' => 'this_month']],
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'eq', 'value' => 0],
            ]),
        ];
        $out[] = [
            'key' => 'vip_no_purchase_90d',
            'name' => 'Clientes VIP sin compra en 90 días',
            'description' => 'Campeones, leales o VIP que llevan 90 días sin comprar.',
            'folder' => 'Recompra',
            'definition' => self::and([
                ['op' => 'or', 'conditions' => [
                    ['type' => 'prediction', 'field' => 'rfm_segment', 'operator' => 'in', 'value' => ['champions', 'loyal']],
                    ['type' => 'attribute', 'field' => 'lifecycle_stage', 'operator' => 'eq', 'value' => 'vip'],
                ]],
                ['type' => 'purchase', 'field' => 'last_order_at', 'operator' => 'more_than_days_ago', 'value' => 90],
            ]),
        ];
        $out[] = [
            'key' => 'discount_only',
            'name' => 'Solo compran con descuento',
            'description' => 'El 80% o más de sus pedidos usan cupón.',
            'folder' => 'Descuentos',
            'definition' => self::and([
                ['type' => 'purchase', 'field' => 'coupon_ratio', 'operator' => 'gte', 'value' => 0.8],
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 2],
            ]),
        ];
        $out[] = [
            'key' => 'never_coupon',
            'name' => 'Nunca usan cupón',
            'description' => 'Compran a precio completo: no les regales margen.',
            'folder' => 'Descuentos',
            'definition' => self::and([
                ['type' => 'purchase', 'field' => 'coupon_ratio', 'operator' => 'eq', 'value' => 0],
                ['type' => 'purchase', 'field' => 'orders_count', 'operator' => 'gte', 'value' => 2],
            ]),
        ];
        $out[] = [
            'key' => 'abandoned_cart_7d',
            'name' => 'Carrito abandonado en los últimos 7 días',
            'description' => 'Agregaron productos o empezaron el pago y no terminaron la compra.',
            'folder' => 'Comportamiento',
            'definition' => self::and([['type' => 'cart', 'operator' => 'abandoned', 'window' => ['kind' => 'last_days', 'days' => 7]]]),
        ];

        return $out;
    }

    /**
     * Plantillas con parámetros (se crean desde el constructor).
     *
     * @return list<array{key: string, name: string, description: string, params: list<array{name: string, type: string, label: string}>}>
     */
    public static function templates(): array
    {
        return [
            [
                'key' => 'cross_sell_categories',
                'name' => 'Compradores de [categoría] que nunca compraron [categoría complementaria]',
                'description' => 'Venta cruzada: quienes ya compran una categoría y nunca la complementaria.',
                'params' => [
                    ['name' => 'bought', 'type' => 'category', 'label' => 'Compraron'],
                    ['name' => 'never', 'type' => 'category', 'label' => 'Nunca compraron'],
                ],
            ],
            [
                'key' => 'viewed_not_bought',
                'name' => 'Vieron [producto] 2+ veces en 7 días y no lo compraron',
                'description' => 'Navegación abandonada de un producto concreto.',
                'params' => [['name' => 'product', 'type' => 'product', 'label' => 'Producto']],
            ],
        ];
    }

    /**
     * Definición de una plantilla con sus parámetros (IDs externos).
     *
     * @param  array<string, list<string>|string>  $params
     * @return array<string, mixed>|null
     */
    public static function buildTemplate(string $key, array $params): ?array
    {
        $list = fn (string $name) => array_values(array_filter(array_map('strval', (array) ($params[$name] ?? [])), fn ($v) => $v !== ''));

        return match ($key) {
            'cross_sell_categories' => self::and([
                ['type' => 'purchase', 'scope' => 'category', 'ids' => $list('bought'), 'operator' => 'ever'],
                ['type' => 'purchase', 'scope' => 'category', 'ids' => $list('never'), 'operator' => 'never'],
            ]),
            'viewed_not_bought' => self::and([
                ['type' => 'event', 'event' => 'product.viewed', 'filters' => [['prop' => 'product_id', 'operator' => 'in', 'value' => $list('product')]],
                    'measure' => 'events', 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 7]],
                ['type' => 'purchase', 'scope' => 'product', 'ids' => $list('product'), 'operator' => 'never'],
            ]),
            default => null,
        };
    }

    /**
     * Crea los segmentos del sistema que falten. No los calcula (lo hace el
     * recálculo nocturno o el botón «Calcular»).
     */
    public function install(Workspace $workspace): int
    {
        return app(CurrentWorkspace::class)->run($workspace, function () use ($workspace): int {
            $existing = Segment::withTrashed()->whereNotNull('system_key')->pluck('system_key')->all();
            $created = 0;
            foreach (self::system() as $def) {
                if (in_array($def['key'], $existing, true)) {
                    continue;
                }
                Segment::query()->create([
                    'workspace_id' => $workspace->id,
                    'name' => $def['name'],
                    'description' => $def['description'],
                    'folder' => $def['folder'],
                    'definition' => $def['definition'],
                    'kind' => Segment::KIND_DYNAMIC,
                    'is_system' => true,
                    'system_key' => $def['key'],
                ]);
                $created++;
            }

            return $created;
        });
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array<string, mixed>
     */
    private static function and(array $conditions): array
    {
        return ['op' => 'and', 'conditions' => $conditions];
    }
}
