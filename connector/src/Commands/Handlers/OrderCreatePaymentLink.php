<?php
/**
 * `order.create_payment_link` (docs/api-connector.md §6, Fase 4).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Pedido pendiente con los productos que armó la bandeja (o el agente de IA) y su
 * enlace de pago (`get_checkout_payment_url()`). Solo productos publicados, que se
 * pueden comprar y con stock suficiente; el precio es el de la tienda en ese
 * momento. El pedido sale con su propio `order.created`, como cualquier otro.
 * El comando es idempotente por `request_id` (CommandsController).
 */
final class OrderCreatePaymentLink implements Command {

	private const MAX_ITEMS = 20;

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		if ( ! function_exists( 'wc_create_order' ) ) {
			throw new CommandError( 'woocommerce_missing', 'WooCommerce no está activo', 409 );
		}
		$lines = $this->lines( $args['items'] ?? null );
		$a     = new Args( $args );

		$order = wc_create_order(
			array(
				'status'      => 'pending',
				'created_via' => 'imagina-growth',
			)
		);
		if ( ! $order instanceof \WC_Order ) {
			throw new CommandError( 'save_failed', 'WooCommerce no pudo crear el pedido', 500 );
		}
		foreach ( $lines as $line ) {
			$order->add_product( $line['product'], $line['qty'] );
		}
		$billing = is_array( $args['billing'] ?? null ) ? $args['billing'] : array();
		$email   = sanitize_email( (string) ( $billing['email'] ?? '' ) );
		if ( '' !== $email && is_email( $email ) ) {
			$order->set_billing_email( $email );
		}
		foreach ( array( 'phone', 'first_name', 'last_name' ) as $field ) {
			$value = sanitize_text_field( (string) ( $billing[ $field ] ?? '' ) );
			if ( '' !== $value ) {
				$order->{'set_billing_' . $field}( mb_substr( $value, 0, 100 ) );
			}
		}
		$ref = (string) $a->string( 'customer_ref', false, 20, '/^wc:\d+$/' );
		if ( '' !== $ref ) {
			$user_id = (int) substr( $ref, 3 );
			if ( get_userdata( $user_id ) instanceof \WP_User ) {
				$order->set_customer_id( $user_id );
			}
		}
		$coupon = (string) $a->string( 'coupon', false, 60, '/^[A-Za-z0-9_\-]+$/' );
		if ( '' !== $coupon ) {
			$applied = $order->apply_coupon( $coupon );
			if ( is_wp_error( $applied ) ) {
				$order->delete( true );
				throw new CommandError( 'coupon_invalid', 'El cupón no se puede usar en este pedido', 422 );
			}
		}
		$order->calculate_totals();
		$order->update_meta_data( 'igr_payment_link', '1' );
		$order->add_order_note( 'Enlace de pago creado desde Imagina Growth.', 0, false );
		$order->save();

		return array(
			'order_id' => (string) $order->get_id(),
			'number'   => (string) $order->get_order_number(),
			'url'      => $order->get_checkout_payment_url(),
			'total'    => wc_format_decimal( (string) $order->get_total(), 2 ),
			'currency' => $order->get_currency(),
		);
	}

	/**
	 * Valida los ítems y resuelve cada producto.
	 *
	 * @param mixed $items Ítems del hub.
	 * @return list<array{product: \WC_Product, qty: int}>
	 */
	private function lines( mixed $items ): array {
		if ( ! is_array( $items ) || array() === $items || count( $items ) > self::MAX_ITEMS ) {
			throw Args::invalid( 'items', 'debe traer entre 1 y 20 productos' );
		}
		$lines = array();
		foreach ( array_values( $items ) as $i => $item ) {
			$id        = is_array( $item ) ? (string) ( $item['product_id'] ?? '' ) : '';
			$variation = is_array( $item ) ? (string) ( $item['variation_id'] ?? '' ) : '';
			$qty       = is_array( $item ) ? (int) ( $item['qty'] ?? 1 ) : 0;
			if ( 1 !== preg_match( '/^\d{1,20}$/', $id ) || ( '' !== $variation && 1 !== preg_match( '/^\d{1,20}$/', $variation ) ) || $qty < 1 || $qty > 99 ) {
				throw Args::invalid( "items.{$i}", 'producto o cantidad no válidos' ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- respuesta JSON al hub, no HTML.
			}
			$product = wc_get_product( (int) ( '' !== $variation ? $variation : $id ) );
			$parent  = $product instanceof \WC_Product_Variation ? wc_get_product( $product->get_parent_id() ) : $product;
			if ( ! $product instanceof \WC_Product || ! $parent instanceof \WC_Product
				|| 'publish' !== $parent->get_status() || '' !== (string) $parent->get_post_password()
				|| ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
				throw new CommandError( 'product_unavailable', "El producto {$id} no se puede comprar", 422 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- respuesta JSON al hub, no HTML.
			}
			if ( ! $product->is_in_stock() || ( $product->managing_stock() && ! $product->backorders_allowed() && (int) $product->get_stock_quantity() < $qty ) ) {
				throw new CommandError( 'out_of_stock', "No hay stock suficiente del producto {$id}", 422 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- respuesta JSON al hub, no HTML.
			}
			$lines[] = array(
				'product' => $product,
				'qty'     => $qty,
			);
		}
		return $lines;
	}
}
