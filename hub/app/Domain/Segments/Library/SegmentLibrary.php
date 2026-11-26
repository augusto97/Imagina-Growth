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
 *
 * Cada uno es para tiendas, para servicios o para los dos (`audienceOf`): la
 * lista de grupos muestra solo los que aplican al negocio (los de compras no
 * dicen nada en una clínica; los de leads y citas, nada en una tienda).
 */
final class SegmentLibrary
{
    /** Los del sistema que usan leads y citas (rediseño v2 §11): solo tienen sentido en un negocio de servicios. */
    public const SERVICES = ['leads_hot', 'leads_open', 'appointment_upcoming', 'appointment_no_show', 'appointment_attended', 'leads_won_year', 'leads_lost_90d'];

    /** Los de compras: RFM y los de recompra, descuentos y carritos. */
    public const STORE = ['one_order_60d', 'visits_no_purchase', 'vip_no_purchase_90d', 'discount_only', 'never_coupon', 'abandoned_cart_7d'];

    /** Para quién es un segmento del sistema: `store`, `services` o null (los dos, o uno propio). */
    public static function audienceOf(?string $systemKey): ?string
    {
        return match (true) {
            $systemKey === null => null,
            str_starts_with($systemKey, 'rfm_') || in_array($systemKey, self::STORE, true) => 'store',
            in_array($systemKey, self::SERVICES, true) => 'services',
            default => null,
        };
    }

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

        // Servicios (M18): leads y citas.
        $lead = fn (string $field, string $operator, mixed $value = null) => ['type' => 'lead', 'field' => $field, 'operator' => $operator] + ($value === null ? [] : ['value' => $value]);
        $service = fn (string $field, string $operator, mixed $value = null) => ['type' => 'service', 'field' => $field, 'operator' => $operator] + ($value === null ? [] : ['value' => $value]);
        $out[] = [
            'key' => 'leads_hot',
            'name' => 'Interesados calientes',
            'description' => 'Tienen un proceso abierto y están cerca de contratar: escríbeles hoy.',
            'folder' => 'Servicios',
            'definition' => self::and([$lead('has_open_lead', 'is_true'), $lead('temperature', 'eq', 'hot')]),
        ];
        $out[] = [
            'key' => 'leads_open',
            'name' => 'Con un proceso abierto',
            'description' => 'Están en tu embudo y todavía no deciden: hazles seguimiento.',
            'folder' => 'Servicios',
            'definition' => self::and([$lead('has_open_lead', 'is_true')]),
        ];
        $out[] = [
            'key' => 'appointment_upcoming',
            'name' => 'Con una cita próxima',
            'description' => 'Recuérdales la cita y lo que deben tener listo.',
            'folder' => 'Servicios',
            'definition' => self::and([$service('has_upcoming', 'is_true')]),
        ];
        $out[] = [
            'key' => 'appointment_no_show',
            'name' => 'No asistieron y no han vuelto a agendar',
            'description' => 'Faltaron a una cita y no tienen otra: invítalos a reagendar.',
            'folder' => 'Servicios',
            'definition' => self::and([$service('appointment_status', 'eq', 'no_show'), $service('has_upcoming', 'is_false')]),
        ];
        $out[] = [
            'key' => 'appointment_attended',
            'name' => 'Ya fueron a una cita',
            'description' => 'Ya te conocen: pídeles una reseña o que te recomienden.',
            'folder' => 'Servicios',
            'definition' => self::and([$service('appointment_status', 'eq', 'completed')]),
        ];
        $out[] = [
            'key' => 'leads_won_year',
            'name' => 'Contrataron este año',
            'description' => 'Clientes ganados en los últimos 12 meses: bienvenida, controles y referidos.',
            'folder' => 'Servicios',
            'definition' => self::and([$lead('won_at', 'within_last_days', 365)]),
        ];
        $out[] = [
            'key' => 'leads_lost_90d',
            'name' => 'No continuaron hace más de 90 días',
            'description' => 'Vuelve a intentarlo con una novedad o un beneficio.',
            'folder' => 'Servicios',
            'definition' => self::and([$lead('lost_at', 'more_than_days_ago', 90), $lead('has_open_lead', 'is_false')]),
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
