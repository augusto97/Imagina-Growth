<?php
/**
 * Eventos del carrito y del inicio del checkout.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Onsite\CartRestore;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Tracking\RequestContext;

/**
 * `cart.item_added`, `cart.item_removed`, `cart.updated` (throttle de 30 s y solo si cambió),
 * `checkout.started` (una vez por carrito) y `coupon.applied`.
 */
final class CartCollector {

	private const SESSION_TOKEN    = 'igr_cart_token';
	private const SESSION_HASH     = 'igr_cart_hash';
	private const SESSION_CHECKOUT = 'igr_checkout_started';
	private const THROTTLE_SECONDS = 30;

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_add_to_cart', array( self::class, 'on_item_added' ), 20, 6 );
		add_action( 'woocommerce_cart_item_removed', array( self::class, 'on_item_removed' ), 20, 2 );
		add_action( 'woocommerce_cart_updated', array( self::class, 'on_cart_updated' ), 20, 0 );
		add_action( 'woocommerce_applied_coupon', array( self::class, 'on_coupon_applied' ), 20, 1 );
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ), 20, 0 );
	}

	/**
	 * Producto agregado (también AJAX y Store API).
	 *
	 * @param mixed $key          Clave del ítem.
	 * @param mixed $product_id   Producto.
	 * @param mixed $quantity     Cantidad.
	 * @param mixed $variation_id Variación.
	 */
	public static function on_item_added( mixed $key, mixed $product_id, mixed $quantity, mixed $variation_id = 0 ): void {
		if ( ! self::active() ) {
			return;
		}
		$item    = WC()->cart->get_cart_item( (string) $key );
		$product = is_array( $item ) && ( $item['data'] ?? null ) instanceof \WC_Product ? $item['data'] : wc_get_product( (int) ( $variation_id ? $variation_id : $product_id ) );
		self::record(
			'cart.item_added',
			array(
				'cart_token'   => self::token(),
				'product_id'   => (string) (int) $product_id,
				'variation_id' => (int) $variation_id > 0 ? (string) (int) $variation_id : null,
				'qty'          => (float) $quantity == (int) $quantity ? (int) $quantity : (float) $quantity, // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				'price'        => Money::format( $product instanceof \WC_Product ? $product->get_price() : 0 ),
				'cart_total'   => Money::format( WC()->cart->get_cart_contents_total() + WC()->cart->get_cart_contents_tax() ),
				'currency'     => get_woocommerce_currency(),
			)
		);
	}

	/**
	 * Producto quitado.
	 *
	 * @param mixed $key  Clave del ítem.
	 * @param mixed $cart Carrito.
	 */
	public static function on_item_removed( mixed $key, mixed $cart = null ): void {
		if ( ! self::active() || ! $cart instanceof \WC_Cart ) {
			return;
		}
		$item = $cart->removed_cart_contents[ (string) $key ] ?? null;
		if ( ! is_array( $item ) ) {
			return;
		}
		self::record(
			'cart.item_removed',
			array(
				'cart_token'   => self::token(),
				'product_id'   => (string) (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => ! empty( $item['variation_id'] ) ? (string) (int) $item['variation_id'] : null,
				'qty'          => (int) ( $item['quantity'] ?? 0 ),
			)
		);
	}

	/**
	 * Carrito actualizado: solo si cambió y como máximo cada 30 s por sesión.
	 */
	public static function on_cart_updated(): void {
		if ( ! self::active() || ( WC()->cart->is_empty() && null === WC()->session->get( self::SESSION_HASH ) ) ) {
			return;
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$product = $item['data'] ?? null;
			$qty     = (float) ( $item['quantity'] ?? 0 );
			$items[] = array(
				'product_id'   => (string) (int) ( $item['product_id'] ?? 0 ),
				'variation_id' => ! empty( $item['variation_id'] ) ? (string) (int) $item['variation_id'] : null,
				'sku'          => $product instanceof \WC_Product && '' !== $product->get_sku() ? mb_substr( (string) $product->get_sku(), 0, 100 ) : null,
				'name'         => $product instanceof \WC_Product ? mb_substr( wp_strip_all_tags( (string) $product->get_name() ), 0, 300 ) : '',
				'qty'          => floor( $qty ) === $qty ? (int) $qty : $qty,
				'price'        => Money::format( $product instanceof \WC_Product ? $product->get_price() : 0 ),
				'line_total'   => Money::format( $item['line_subtotal'] ?? 0 ),
			);
		}
		$coupons = array_values( array_map( 'strval', WC()->cart->get_applied_coupons() ) );
		$hash    = md5( (string) wp_json_encode( array( $items, $coupons ) ) );
		$last    = WC()->session->get( self::SESSION_HASH );
		if ( is_array( $last ) && $last['hash'] === $hash ) {
			return;
		}
		if ( is_array( $last ) && ( time() - (int) $last['at'] ) < self::THROTTLE_SECONDS ) {
			return;
		}
		WC()->session->set(
			self::SESSION_HASH,
			array(
				'hash' => $hash,
				'at'   => time(),
			)
		);
		$props    = array(
			'cart_token' => self::token(),
			'items'      => $items,
			'subtotal'   => Money::format( WC()->cart->get_subtotal() ),
			'coupons'    => $coupons,
			'currency'   => get_woocommerce_currency(),
		);
		$restored = WC()->session->get( CartRestore::SESSION_RESTORE );
		if ( is_string( $restored ) && '' !== $restored ) {
			// v3: el carrito salió de un enlace de un mensaje (api-connector.md §6.1).
			$props['restored_from'] = $restored;
		}
		self::record( 'cart.updated', $props );
	}

	/**
	 * Cupón aplicado.
	 *
	 * @param mixed $code Código.
	 */
	public static function on_coupon_applied( mixed $code ): void {
		if ( ! self::active() ) {
			return;
		}
		self::record(
			'coupon.applied',
			array(
				'cart_token' => self::token(),
				'code'       => mb_substr( (string) $code, 0, 100 ),
			)
		);
	}

	/**
	 * Inicio del checkout (clásico o de bloques), una vez por carrito.
	 */
	public static function on_template_redirect(): void {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( ! self::active() || WC()->cart->is_empty() ) {
			return;
		}
		$token = self::token();
		if ( WC()->session->get( self::SESSION_CHECKOUT ) === $token ) {
			return;
		}
		WC()->session->set( self::SESSION_CHECKOUT, $token );
		self::record(
			'checkout.started',
			array(
				'cart_token'  => $token,
				'subtotal'    => Money::format( WC()->cart->get_subtotal() ),
				'items_count' => (int) WC()->cart->get_cart_contents_count(),
				'currency'    => get_woocommerce_currency(),
			),
			'checkout'
		);
	}

	/**
	 * Token del carrito, guardado en la sesión de WooCommerce.
	 */
	public static function token(): string {
		$token = WC()->session->get( self::SESSION_TOKEN );
		if ( ! is_string( $token ) || 1 !== preg_match( '/^[a-f0-9]{16,64}$/', $token ) ) {
			$token = bin2hex( random_bytes( 16 ) );
			WC()->session->set( self::SESSION_TOKEN, $token );
		}
		return $token;
	}

	/**
	 * Registra ya el `cart.updated`, sin esperar el límite de 30 s (carrito restaurado desde un enlace).
	 */
	public static function record_now(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_HASH, null );
		}
		self::on_cart_updated();
	}

	/**
	 * Token del carrito de la sesión actual, sin crear uno nuevo (para guardarlo en el pedido).
	 */
	public static function current_token(): ?string {
		if ( ! function_exists( 'WC' ) || null === WC()->session ) {
			return null;
		}
		$token = WC()->session->get( self::SESSION_TOKEN );
		return is_string( $token ) && 1 === preg_match( '/^[a-f0-9]{16,64}$/', $token ) ? $token : null;
	}

	/**
	 * Al convertirse el carrito en pedido se empieza uno nuevo.
	 */
	public static function reset_token(): void {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::SESSION_TOKEN, null );
			WC()->session->set( self::SESSION_HASH, null );
			WC()->session->set( self::SESSION_CHECKOUT, null );
			WC()->session->set( CartRestore::SESSION_RESTORE, null );
		}
	}

	/**
	 * ¿Hay carrito y sesión, y el usuario no está excluido?
	 */
	private static function active(): bool {
		return function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart && null !== WC()->session && ! Roles::is_excluded() && ! Recorder::is_suppressed();
	}

	/**
	 * Registra un evento de carrito con la identidad de la persona.
	 *
	 * @param string               $event     Evento.
	 * @param array<string, mixed> $props     Propiedades.
	 * @param string|null          $page_type Tipo de página.
	 */
	private static function record( string $event, array $props, ?string $page_type = null ): void {
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		try {
			$ctx      = RequestContext::capture();
			$identity = $ctx['identity'];
			$user_id  = get_current_user_id();
			if ( $user_id > 0 ) {
				$identity['customer_ref'] = 'wc:' . $user_id;
			}
			$email = WC()->customer instanceof \WC_Customer ? strtolower( trim( (string) WC()->customer->get_billing_email() ) ) : '';
			if ( '' !== $email && is_email( $email ) ) {
				$identity['email'] = $email;
			}
			$context = $ctx['context'];
			if ( null !== $page_type ) {
				$context['page_type'] = $page_type;
			}
			$context['currency'] = get_woocommerce_currency();
			Recorder::record(
				$factory->make(
					$event,
					$props,
					array(
						'identity' => $identity,
						'context'  => $context,
					)
				)
			);
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar un evento del carrito: ' . $e->getMessage(), array( 'event' => $event ) );
		}
	}
}
