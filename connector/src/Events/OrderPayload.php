<?php
/**
 * Objeto `order` del contrato (docs/eventos.md §5).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Events;

use ImaginaGrowth\Connector\Catalog\ProductInfo;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Money;

/**
 * Foto completa del pedido, siempre con la API CRUD de WooCommerce (compatible con HPOS).
 */
final class OrderPayload {

	/** Metas de la atribución nativa de WooCommerce. */
	private const ATTRIBUTION_KEYS = array(
		'source_type'   => '_wc_order_attribution_source_type',
		'utm_source'    => '_wc_order_attribution_utm_source',
		'utm_medium'    => '_wc_order_attribution_utm_medium',
		'utm_campaign'  => '_wc_order_attribution_utm_campaign',
		'session_entry' => '_wc_order_attribution_session_entry',
		'session_pages' => '_wc_order_attribution_session_pages',
		'session_count' => '_wc_order_attribution_session_count',
		'device_type'   => '_wc_order_attribution_device_type',
	);

	/**
	 * Arma el objeto `order`.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>
	 */
	public static function from( \WC_Order $order ): array {
		$gateway  = (string) $order->get_payment_method();
		$shipping = null;
		foreach ( $order->get_items( 'shipping' ) as $item ) {
			if ( $item instanceof \WC_Order_Item_Shipping ) {
				$shipping = array(
					'id'    => mb_substr( (string) $item->get_method_id(), 0, 100 ),
					'title' => mb_substr( (string) $item->get_method_title(), 0, 200 ),
				);
				break;
			}
		}
		$user_id = (int) $order->get_customer_id();
		$cart    = (string) $order->get_meta( OrderEvents::CART_META, true );

		$out = array(
			'order_id'        => (string) $order->get_id(),
			'number'          => mb_substr( (string) $order->get_order_number(), 0, 64 ),
			'status'          => (string) $order->get_status(),
			'created_at'      => Dates::iso( $order->get_date_created() ) ?? Dates::now(),
			'paid_at'         => Dates::iso( $order->get_date_paid() ),
			'modified_at'     => Dates::iso( $order->get_date_modified() ),
			'created_via'     => mb_substr( (string) $order->get_created_via(), 0, 40 ),
			'currency'        => (string) $order->get_currency(),
			'totals'          => array(
				'subtotal' => Money::format( $order->get_subtotal() ),
				'discount' => Money::format( $order->get_discount_total() ),
				'shipping' => Money::format( $order->get_shipping_total() ),
				'tax'      => Money::format( $order->get_total_tax() ),
				'total'    => Money::format( $order->get_total() ),
				'refunded' => Money::format( $order->get_total_refunded() ),
			),
			'payment_method'  => array(
				'id'         => mb_substr( $gateway, 0, 100 ),
				'title'      => mb_substr( (string) $order->get_payment_method_title(), 0, 200 ),
				'is_offline' => RemoteConfig::is_offline( $gateway ),
			),
			'shipping_method' => $shipping,
			'coupons'         => array_values( array_map( static fn( $c ) => mb_substr( (string) $c, 0, 100 ), $order->get_coupon_codes() ) ),
			'items'           => self::items( $order ),
			'billing'         => Json::obj( self::address( $order, 'billing' ) ),
			'shipping'        => Json::obj(
				array_filter(
					array(
						'city'    => mb_substr( (string) $order->get_shipping_city(), 0, 120 ),
						'state'   => mb_substr( (string) $order->get_shipping_state(), 0, 32 ),
						'country' => mb_substr( (string) $order->get_shipping_country(), 0, 2 ),
					),
					static fn( $v ) => '' !== $v
				)
			),
			'customer_ref'    => $user_id > 0 ? 'wc:' . $user_id : null,
			'wc_attribution'  => self::attribution( $order ),
			'consents'        => self::consents( $order ),
			'meta'            => new \stdClass(),
		);
		if ( 1 === preg_match( '/^[A-Za-z0-9_-]{8,64}$/', $cart ) ) {
			$out['cart_token'] = $cart; // v3 (eventos.md §8).
		}
		return $out;
	}

	/**
	 * Líneas de producto. `unit_price` y `line_total` van antes de descuentos de cupón
	 * (como el ejemplo de eventos.md §5); `cost` es el costo **unitario** (el hub lo multiplica
	 * por `qty` para el costo del pedido).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return list<array<string, mixed>>
	 */
	public static function items( \WC_Order $order ): array {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$product_id   = (int) $item->get_product_id();
			$variation_id = (int) $item->get_variation_id();
			$qty          = (float) $item->get_quantity();
			$subtotal     = (float) $item->get_subtotal();
			$product      = $item->get_product();
			$lookup_id    = $variation_id > 0 ? $variation_id : $product_id;
			$sku          = $product instanceof \WC_Product ? (string) $product->get_sku() : '';
			$items[]      = array(
				'item_id'      => (string) $item->get_id(),
				'product_id'   => (string) $product_id,
				'variation_id' => $variation_id > 0 ? (string) $variation_id : null,
				'sku'          => '' !== $sku ? mb_substr( $sku, 0, 100 ) : null,
				'name'         => mb_substr( wp_strip_all_tags( (string) $item->get_name() ), 0, 300 ),
				'category_ids' => $product_id > 0 ? ProductInfo::category_ids( $lookup_id ) : array(),
				'brand'        => $product_id > 0 ? ProductInfo::brand( $lookup_id ) : null,
				'qty'          => floor( $qty ) === $qty ? (int) $qty : $qty,
				'unit_price'   => Money::format( $qty > 0 ? $subtotal / $qty : $subtotal ),
				'line_total'   => Money::format( $subtotal ),
				'cost'         => self::item_cost( $item, $product, $qty ),
			);
		}
		return $items;
	}

	/**
	 * Dirección de facturación.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $type  `billing`.
	 * @return array<string, string>
	 */
	private static function address( \WC_Order $order, string $type ): array {
		$fields = array(
			'first_name' => 120,
			'last_name'  => 120,
			'company'    => 200,
			'email'      => 254,
			'phone'      => 32,
			'city'       => 120,
			'state'      => 32,
			'country'    => 2,
			'postcode'   => 20,
		);
		$out    = array();
		foreach ( $fields as $field => $max ) {
			$getter = 'get_' . $type . '_' . $field;
			$value  = method_exists( $order, $getter ) ? trim( (string) $order->{$getter}() ) : '';
			if ( 'email' === $field ) {
				$value = is_email( $value ) ? strtolower( $value ) : '';
			}
			if ( '' !== $value ) {
				$out[ $field ] = mb_substr( $value, 0, $max );
			}
		}
		return $out;
	}

	/**
	 * Costo unitario: COGS nativo del ítem (total de la línea ÷ cantidad) o costo del producto.
	 *
	 * @param \WC_Order_Item_Product $item    Ítem.
	 * @param mixed                  $product Producto.
	 * @param float                  $qty     Cantidad.
	 */
	private static function item_cost( \WC_Order_Item_Product $item, mixed $product, float $qty ): ?string {
		if ( ProductInfo::cogs_enabled() && method_exists( $item, 'get_cogs_value' ) ) {
			$value = (float) $item->get_cogs_value();
			if ( $value > 0 ) {
				return Money::format( $qty > 0 ? $value / $qty : $value );
			}
		}
		$item_cost = $item->get_meta( '_wc_cog_item_total_cost', true );
		if ( is_numeric( $item_cost ) && (float) $item_cost > 0 ) {
			return Money::format( $qty > 0 ? (float) $item_cost / $qty : (float) $item_cost );
		}
		if ( $product instanceof \WC_Product ) {
			$unit = ProductInfo::cost( $product );
			if ( null !== $unit ) {
				return Money::format( $unit );
			}
		}
		return null;
	}

	/**
	 * Atribución nativa de WooCommerce (`_wc_order_attribution_*`).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>|null
	 */
	private static function attribution( \WC_Order $order ): ?array {
		$out = array();
		foreach ( self::ATTRIBUTION_KEYS as $key => $meta ) {
			$value = $order->get_meta( $meta, true );
			if ( '' === $value || null === $value ) {
				continue;
			}
			$out[ $key ] = in_array( $key, array( 'session_pages', 'session_count' ), true ) ? (int) $value : mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
		}
		return array() === $out ? null : $out;
	}

	/**
	 * Consentimientos del checkout (`_igr_consents`, Fase 2).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>|null
	 */
	private static function consents( \WC_Order $order ): ?array {
		$raw = $order->get_meta( '_igr_consents', true );
		if ( ! is_array( $raw ) ) {
			return null;
		}
		$out = array();
		foreach ( array( 'email_marketing', 'whatsapp_marketing', 'sms_marketing' ) as $k ) {
			if ( isset( $raw[ $k ] ) ) {
				$out[ $k ] = (bool) $raw[ $k ];
			}
		}
		if ( isset( $raw['text_version'] ) ) {
			$out['text_version'] = mb_substr( (string) $raw['text_version'], 0, 32 );
		}
		return array() === $out ? null : $out;
	}
}
