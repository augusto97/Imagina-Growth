<?php
/**
 * `order.status` (docs/api-connector.md §6, Fase 2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Support\Phone;

/**
 * Estado de un pedido por número + correo o teléfono que coincida. Si el pedido no
 * existe o el dato no coincide responde lo mismo (`404 not_found`), así nadie
 * puede averiguar pedidos ajenos probando números.
 */
final class OrderStatus implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		if ( ! function_exists( 'wc_get_order' ) ) {
			throw new CommandError( 'woocommerce_missing', 'WooCommerce no está activo', 409 );
		}
		$a      = new Args( $args );
		$number = (string) $a->string( 'number', true, 64, '/^[A-Za-z0-9#_-]+$/' );
		$email  = $a->string( 'email', false, 254 );
		$phone  = $a->string( 'phone', false, 32 );
		if ( null === $email && null === $phone ) {
			throw Args::invalid( 'email', 'o phone es obligatorio' );
		}
		$order = self::find( ltrim( $number, '#' ) );
		if ( null === $order || ! self::matches( $order, $email, $phone ) ) {
			throw new CommandError( 'not_found', 'No hay un pedido con ese número y esos datos', 404 );
		}
		$out      = array(
			'status'       => (string) $order->get_status(),
			'status_label' => wc_get_order_status_name( $order->get_status() ),
			'total'        => Money::format( $order->get_total() ),
			'currency'     => (string) $order->get_currency(),
			'created_at'   => Dates::iso( $order->get_date_created() ),
		);
		$tracking = self::tracking( $order );
		if ( array() !== $tracking ) {
			$out['tracking'] = $tracking;
		}
		return $out;
	}

	/**
	 * Busca por número visible: el ID o `_order_number` (plugins de números secuenciales).
	 *
	 * @param string $number Número.
	 */
	private static function find( string $number ): ?\WC_Order {
		if ( 1 === preg_match( '/^\d+$/', $number ) ) {
			$order = wc_get_order( (int) $number );
			if ( $order instanceof \WC_Order && 'shop_order' === $order->get_type() && (string) $order->get_order_number() === $number ) {
				return $order;
			}
		}
		$found = wc_get_orders(
			array(
				'limit'      => 1,
				'type'       => 'shop_order',
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Solo con plugins de números secuenciales.
					array(
						'key'   => '_order_number',
						'value' => $number,
					),
				),
			)
		);
		$order = is_array( $found ) ? ( $found[0] ?? null ) : null;
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * ¿El correo o el teléfono coinciden con los de facturación?
	 *
	 * @param \WC_Order   $order Pedido.
	 * @param string|null $email Correo.
	 * @param string|null $phone Teléfono.
	 */
	private static function matches( \WC_Order $order, ?string $email, ?string $phone ): bool {
		if ( null !== $email ) {
			$billing = strtolower( trim( (string) $order->get_billing_email() ) );
			if ( '' !== $billing && hash_equals( $billing, strtolower( trim( $email ) ) ) ) {
				return true;
			}
		}
		if ( null !== $phone ) {
			$country = (string) ( $order->get_billing_country() ? $order->get_billing_country() : 'CO' );
			$billing = Phone::e164( (string) $order->get_billing_phone(), $country );
			$given   = Phone::e164( $phone, $country );
			if ( null !== $billing && null !== $given && hash_equals( $billing, $given ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Guías de envío del plugin WooCommerce Shipment Tracking, si existe.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return list<array<string, string>>
	 */
	private static function tracking( \WC_Order $order ): array {
		$items = $order->get_meta( '_wc_shipment_tracking_items', true );
		$out   = array();
		foreach ( is_array( $items ) ? array_slice( $items, 0, 5 ) : array() as $item ) {
			if ( ! is_array( $item ) || empty( $item['tracking_number'] ) ) {
				continue;
			}
			$out[] = array_filter(
				array(
					'provider' => mb_substr( (string) ( $item['tracking_provider'] ?? $item['custom_tracking_provider'] ?? '' ), 0, 100 ),
					'number'   => mb_substr( (string) $item['tracking_number'], 0, 100 ),
					'url'      => esc_url_raw( (string) ( $item['custom_tracking_link'] ?? '' ) ),
				),
				static fn( string $v ): bool => '' !== $v
			);
		}
		return $out;
	}
}
