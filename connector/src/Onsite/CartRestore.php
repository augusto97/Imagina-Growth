<?php
/**
 * Carrito restaurable desde un mensaje (docs/api-connector.md §6.1, connector/CLAUDE.md §8).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Tracking\PageToken;

/**
 * `?igr_cart=<payload>.<firma>`: verifica la firma con el secreto actual o el
 * anterior y el vencimiento, arma el carrito (reemplaza o suma; omite lo que ya no
 * existe o no tiene stock), aplica el cupón y redirige al checkout conservando
 * `_igr` y los `utm_*`. **No llama al hub** y la respuesta nunca se cachea.
 * Si el enlace no sirve, lleva a la tienda sin mostrar errores.
 */
final class CartRestore {

	public const PARAM           = 'igr_cart';
	public const SESSION_RESTORE = 'igr_restored_from';

	private const MAX_ITEMS = 50;

	/**
	 * Registra el hook (antes que el `checkout.started` del CartCollector).
	 */
	public static function register(): void {
		add_action( 'template_redirect', array( self::class, 'on_template_redirect' ), 1, 0 );
	}

	/**
	 * Atiende la petición si trae `igr_cart`.
	 */
	public static function on_template_redirect(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- La firma HMAC del enlace es la verificación.
		$token = isset( $_GET[ self::PARAM ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::PARAM ] ) ) : '';
		if ( '' === $token ) {
			return;
		}
		self::no_cache();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Solo se copian los parámetros de seguimiento.
		$url = self::process( $token, wp_unslash( $_GET ) );
		wp_safe_redirect( $url, 302, 'Imagina Growth' );
		exit;
	}

	/**
	 * Restaura el carrito y devuelve la URL a la que se redirige.
	 *
	 * @param string               $token Token del enlace.
	 * @param array<string, mixed> $query Parámetros de la URL (para conservar `_igr` y `utm_*`).
	 */
	public static function process( string $token, array $query = array() ): string {
		$keep = self::tracking_params( $query );
		$home = add_query_arg( $keep, home_url( '/' ) );
		if ( ! Connection::is_connected() || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $home;
		}
		$data = PageToken::verify( $token, Connection::valid_secrets(), Connection::site_id() );
		if ( null === $data || ! is_array( $data['items'] ?? null ) ) {
			Logger::debug( 'Enlace de carrito inválido o vencido' );
			return $home;
		}
		try {
			$added = self::fill( $data );
		} catch ( \Throwable $e ) {
			Logger::error( 'No se pudo restaurar el carrito: ' . $e->getMessage() );
			return $home;
		}
		if ( 0 === $added && WC()->cart->is_empty() ) {
			return $home;
		}
		return add_query_arg( $keep, wc_get_checkout_url() );
	}

	/**
	 * Arma el carrito.
	 *
	 * @param array<string, mixed> $data Payload verificado.
	 * @return int Ítems agregados.
	 */
	private static function fill( array $data ): int {
		$cart = WC()->cart;
		if ( 'merge' !== ( $data['mode'] ?? 'replace' ) ) {
			$cart->empty_cart();
		}
		$added = 0;
		foreach ( array_slice( (array) $data['items'], 0, self::MAX_ITEMS ) as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$product_id   = (int) ( $item['product_id'] ?? 0 );
			$variation_id = (int) ( $item['variation_id'] ?? 0 );
			$qty          = max( 1, (int) round( (float) ( $item['qty'] ?? 1 ) ) );
			$product      = wc_get_product( $variation_id > 0 ? $variation_id : $product_id );
			if ( ! $product instanceof \WC_Product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
				continue;
			}
			if ( $product->managing_stock() && ! $product->backorders_allowed() ) {
				$qty = min( $qty, max( 1, (int) $product->get_stock_quantity() ) );
			}
			$attributes = $product instanceof \WC_Product_Variation ? $product->get_variation_attributes() : array();
			$parent     = $product instanceof \WC_Product_Variation ? $product->get_parent_id() : $product->get_id();
			if ( false !== $cart->add_to_cart( $parent, $qty, $product instanceof \WC_Product_Variation ? $product->get_id() : 0, $attributes ) ) {
				++$added;
			}
		}
		$coupon = isset( $data['coupon'] ) && is_string( $data['coupon'] ) ? wc_format_coupon_code( $data['coupon'] ) : '';
		if ( '' !== $coupon && ! $cart->has_discount( $coupon ) ) {
			$cart->apply_coupon( $coupon );
		}
		// Los avisos de «agregado al carrito» o de stock no tienen sentido para quien llega desde un mensaje.
		wc_clear_notices();
		if ( isset( $data['cart'] ) && is_string( $data['cart'] ) && 1 === preg_match( '/^[A-Za-z0-9_]{4,40}$/', $data['cart'] ) && WC()->session ) {
			WC()->session->set( self::SESSION_RESTORE, $data['cart'] );
		}
		$cart->calculate_totals();
		// El `cart.updated` con `restored_from` sale ya, sin esperar el límite de 30 s.
		CartCollector::record_now();
		return $added;
	}

	/**
	 * `_igr` y `utm_*` de la URL original.
	 *
	 * @param array<string, mixed> $query Parámetros.
	 * @return array<string, string>
	 */
	public static function tracking_params( array $query ): array {
		$keep = array();
		foreach ( $query as $key => $value ) {
			$key = (string) $key;
			if ( ! is_string( $value ) || ( '_igr' !== $key && 1 !== preg_match( '/^utm_(source|medium|campaign|content|term|id)$/', $key ) ) ) {
				continue;
			}
			$value = sanitize_text_field( $value );
			if ( '' !== $value && strlen( $value ) <= 200 ) {
				$keep[ $key ] = rawurlencode( $value );
			}
		}
		return $keep;
	}

	/**
	 * La respuesta es personal: ninguna caché la guarda.
	 */
	private static function no_cache(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de los plugins de caché.
		}
		do_action( 'litespeed_control_set_nocache', 'Imagina Growth: carrito restaurado' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de LiteSpeed Cache.
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		}
	}
}
