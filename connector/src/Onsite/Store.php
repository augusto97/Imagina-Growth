<?php
/**
 * Elementos automáticos en la tienda (connector/CLAUDE.md §10): «Avísame», «Gana N
 * puntos», banner superior, valor del carrito para los widgets y fotos de las reseñas.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Nada de esto consulta la base de datos en páginas cacheables: los interruptores vienen
 * de `igr_state` (autocargada) y el HTML es el mismo para todos los visitantes.
 */
final class Store {

	public const CART_COOKIE = '_igr_ct';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_single_product_summary', array( self::class, 'product_points' ), 11 );
		add_action( 'woocommerce_single_product_summary', array( self::class, 'product_watch' ), 35 );
		add_action( 'woocommerce_proceed_to_checkout', array( self::class, 'cart_points' ), 5 );
		add_action( 'woocommerce_set_cart_cookies', array( self::class, 'cart_cookie' ) );
		add_action( 'wp_body_open', array( self::class, 'top_banner' ) );
		add_action( 'woocommerce_review_before_comment_text', array( self::class, 'review_title' ) );
		add_action( 'woocommerce_review_after_comment_text', array( self::class, 'review_media' ) );
	}

	/**
	 * Producto de la ficha actual.
	 */
	private static function product(): ?\WC_Product {
		global $product;
		$p = $product instanceof \WC_Product ? $product : wc_get_product( get_queried_object_id() );
		return $p instanceof \WC_Product ? $p : null;
	}

	/**
	 * «Avísame cuando vuelva» si está agotado; «Avísame si baja de precio» si está activo.
	 */
	public static function product_watch(): void {
		$p = self::product();
		if ( null === $p ) {
			return;
		}
		$id = (string) $p->get_id();
		if ( ! $p->is_in_stock() && TrackerConfig::on( 'bis' ) ) {
			echo Slots::bis( $id, 'back_in_stock' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
		} elseif ( $p->is_in_stock() && TrackerConfig::on( 'pd' ) ) {
			echo Slots::bis( $id, 'price_drop' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
		}
	}

	/**
	 * «Gana N puntos» debajo del precio.
	 */
	public static function product_points(): void {
		$p = self::product();
		if ( null === $p || ! TrackerConfig::on( 'points' ) || '' === (string) $p->get_price() ) {
			return;
		}
		$ctx = array(
			'productId' => (string) $p->get_id(),
			'price'     => Money::format( $p->get_price() ),
		);
		echo Slots::points( $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
	}

	/**
	 * «Gana N puntos» en el carrito (la página del carrito no se cachea).
	 */
	public static function cart_points(): void {
		if ( ! TrackerConfig::on( 'points' ) || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return;
		}
		echo Slots::points( array( 'cartTotal' => Money::format( WC()->cart->get_total( 'edit' ) ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
	}

	/**
	 * Banner superior por segmento (flotante: no reserva espacio).
	 */
	public static function top_banner(): void {
		if ( TrackerConfig::on( 'top' ) && ! ( function_exists( 'is_checkout' ) && is_checkout() ) ) {
			echo Slots::banner( array( 'placement' => 'top' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
		}
	}

	/**
	 * Valor del carrito para la regla `cart_value_min` de los widgets: cookie de sesión
	 * `_igr_ct` que se escribe cuando WooCommerce escribe las suyas (no identifica a nadie).
	 *
	 * @param mixed $set true si el carrito tiene productos.
	 */
	public static function cart_cookie( mixed $set ): void {
		if ( headers_sent() || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart || ! Slots::available() ) {
			return;
		}
		$value = $set ? Money::format( (float) WC()->cart->get_subtotal() + (float) WC()->cart->get_subtotal_tax() ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Solo se compara.
		if ( ( $_COOKIE[ self::CART_COOKIE ] ?? '' ) === $value ) {
			return;
		}
		wc_setcookie( self::CART_COOKIE, $value, '' === $value ? time() - HOUR_IN_SECONDS : 0, is_ssl(), false );
		$_COOKIE[ self::CART_COOKIE ] = $value;
	}

	/**
	 * Título de una reseña publicada desde el hub.
	 *
	 * @param mixed $comment Comentario.
	 */
	public static function review_title( mixed $comment ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$title = (string) get_comment_meta( (int) $comment->comment_ID, 'igr_title', true );
		if ( '' !== $title ) {
			echo '<p class="igr-review-title"><strong>' . esc_html( $title ) . '</strong></p>';
		}
	}

	/**
	 * Fotos y videos de una reseña publicada desde el hub, debajo del texto.
	 *
	 * @param mixed $comment Comentario.
	 */
	public static function review_media( mixed $comment ): void {
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}
		$media = json_decode( (string) get_comment_meta( (int) $comment->comment_ID, 'igr_media', true ), true );
		if ( ! is_array( $media ) || array() === $media ) {
			return;
		}
		$out = '';
		foreach ( array_slice( $media, 0, 10 ) as $i => $m ) {
			$url = is_array( $m ) ? esc_url( (string) ( $m['url'] ?? '' ), array( 'http', 'https' ) ) : '';
			if ( '' === $url ) {
				continue;
			}
			$video = 'video' === ( $m['type'] ?? '' );
			$thumb = esc_url( (string) ( $m['thumb'] ?? ( $video ? '' : $m['url'] ) ), array( 'http', 'https' ) );
			/* translators: %d: número de la foto o del video. */
			$label = sprintf( $video ? __( 'Ver el video %d de la reseña', 'imagina-growth' ) : __( 'Ver la foto %d de la reseña', 'imagina-growth' ), $i + 1 );
			$inner = '' !== $thumb
				? '<img src="' . $thumb . '" alt="" loading="lazy" decoding="async" width="72" height="72" style="width:72px;height:72px;object-fit:cover;border-radius:6px">'
				: '<span>' . esc_html__( 'Video', 'imagina-growth' ) . '</span>';
			$out  .= '<a href="' . $url . '" target="_blank" rel="noopener" aria-label="' . esc_attr( $label ) . '">' . $inner . '</a>';
		}
		if ( '' !== $out ) {
			echo '<div class="igr-review-media" style="display:flex;flex-wrap:wrap;gap:8px;margin:8px 0">' . $out . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Cada parte se escapó arriba.
		}
	}
}
