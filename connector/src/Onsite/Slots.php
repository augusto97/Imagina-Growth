<?php
/**
 * Espacios (slots) para los widgets de la Fase 3 (connector/CLAUDE.md §10, api-connector.md §7).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * `<div class="igr-slot" data-igr-slot="…" data-igr-ctx='{…}' style="min-height:…">`: el
 * HTML no depende del visitante (cacheable) y reserva su altura para no causar CLS. El
 * tracker lo llena después de cargar la página. Lo usan los bloques, los *shortcodes*,
 * los widgets de Elementor y los ganchos de WooCommerce.
 */
final class Slots {

	public const RECO_KINDS = array( 'para_ti', 'bought_together', 'recently_viewed', 'best_sellers_category', 'trending', 'complete_cart' );
	public const LAYOUTS    = array( 'list', 'carousel', 'gallery', 'badge' );

	/**
	 * Altura reservada por defecto (px).
	 */
	private const HEIGHTS = array(
		'form'     => 320,
		'quiz'     => 360,
		'reco'     => 360,
		'list'     => 420,
		'carousel' => 300,
		'gallery'  => 280,
		'badge'    => 22,
		'bis'      => 44,
		'points'   => 24,
		'loyalty'  => 480,
		'banner'   => 56,
	);

	/**
	 * ¿Se pueden pintar slots? (sitio conectado, tracker activo y URL de widgets).
	 */
	public static function available(): bool {
		$trk = TrackerConfig::get();
		return null !== $trk && ! empty( $trk['on'] ) && '' !== ( $trk['wu'] ?? '' );
	}

	/**
	 * HTML de un slot.
	 *
	 * @param string               $slot   Tipo (form, quiz, reco, reviews, bis, points, loyalty, banner, wallet).
	 * @param array<string, mixed> $ctx    `data-igr-ctx` (no puede depender del visitante en páginas cacheables).
	 * @param int                  $height Altura reservada en px (0 = sin reservar).
	 */
	public static function html( string $slot, array $ctx, int $height ): string {
		if ( ! self::available() ) {
			return '';
		}
		$json  = (string) wp_json_encode( (object) $ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$style = $height > 0 ? ' style="min-height:' . $height . 'px"' : '';
		return '<div class="igr-slot igr-slot-' . esc_attr( $slot ) . '" data-igr-slot="' . esc_attr( $slot ) . '" data-igr-ctx="' . esc_attr( $json ) . '"' . $style . '></div>';
	}

	/**
	 * Altura: la indicada (entre 0 y 2000) o la de por defecto.
	 *
	 * @param mixed  $value Valor del atributo.
	 * @param string $kind  Clave en HEIGHTS.
	 */
	public static function height( mixed $value, string $kind ): int {
		if ( is_numeric( $value ) && '' !== (string) $value ) {
			return max( 0, min( 2000, (int) $value ) );
		}
		return self::HEIGHTS[ $kind ] ?? 0;
	}

	/**
	 * ID de un widget del hub (`wg_…`), o null.
	 *
	 * @param mixed $id Valor.
	 */
	public static function widget_id( mixed $id ): ?string {
		$id = is_string( $id ) ? trim( $id ) : '';
		return 1 === preg_match( '/^wg_[A-Za-z0-9_-]{1,60}$/', $id ) ? $id : null;
	}

	/**
	 * ID numérico como texto, o null.
	 *
	 * @param mixed $id Valor.
	 */
	public static function numeric_id( mixed $id ): ?string {
		$id = is_int( $id ) ? (string) $id : ( is_string( $id ) ? trim( $id ) : '' );
		return 1 === preg_match( '/^[1-9]\d{0,19}$/', $id ) ? $id : null;
	}

	/**
	 * Producto de la página actual (ficha), o null.
	 */
	public static function current_product(): ?string {
		if ( function_exists( 'is_product' ) && is_product() ) {
			return self::numeric_id( (string) get_queried_object_id() );
		}
		return null;
	}

	/**
	 * Formulario incrustado.
	 *
	 * @param array<string, mixed> $atts `id`, `height`.
	 */
	public static function form( array $atts ): string {
		$id = self::widget_id( $atts['id'] ?? '' );
		return null === $id ? '' : self::html( 'form', array( 'widgetId' => $id ), self::height( $atts['height'] ?? null, 'form' ) );
	}

	/**
	 * Quiz incrustado.
	 *
	 * @param array<string, mixed> $atts `id`, `height`.
	 */
	public static function quiz( array $atts ): string {
		$id = self::widget_id( $atts['id'] ?? '' );
		return null === $id ? '' : self::html( 'quiz', array( 'widgetId' => $id ), self::height( $atts['height'] ?? null, 'quiz' ) );
	}

	/**
	 * Recomendaciones. En la ficha toma el producto actual; en el carrito (página que no
	 * se cachea), `complete_cart` lleva los productos del carrito.
	 *
	 * @param array<string, mixed> $atts `type`, `product_id`, `category_id`, `limit`, `title`, `height`.
	 */
	public static function reco( array $atts ): string {
		$kind = sanitize_key( (string) ( $atts['type'] ?? 'trending' ) );
		$kind = in_array( $kind, self::RECO_KINDS, true ) ? $kind : 'trending';
		$ctx  = array(
			'kind'  => $kind,
			'limit' => max( 1, min( 12, (int) ( $atts['limit'] ?? 4 ) ) ),
		);
		$pid  = self::numeric_id( $atts['product_id'] ?? '' ) ?? self::current_product();
		if ( null !== $pid ) {
			$ctx['productId'] = $pid;
		}
		$cat = self::numeric_id( $atts['category_id'] ?? '' );
		if ( null === $cat && function_exists( 'is_product_category' ) && is_product_category() ) {
			$cat = self::numeric_id( (string) get_queried_object_id() );
		}
		if ( null !== $cat ) {
			$ctx['categoryId'] = $cat;
		}
		$title = trim( sanitize_text_field( (string) ( $atts['title'] ?? '' ) ) );
		if ( '' !== $title ) {
			$ctx['title'] = mb_substr( $title, 0, 120 );
		}
		if ( 'complete_cart' === $kind && function_exists( 'is_cart' ) && is_cart() && function_exists( 'WC' ) && WC()->cart instanceof \WC_Cart ) {
			$ids = array();
			foreach ( WC()->cart->get_cart() as $item ) {
				$ids[] = (string) (int) ( $item['product_id'] ?? 0 );
			}
			$ctx['cart'] = array_values( array_unique( array_filter( $ids ) ) );
		}
		return self::html( 'reco', $ctx, self::height( $atts['height'] ?? null, 'reco' ) );
	}

	/**
	 * Reseñas.
	 *
	 * @param array<string, mixed> $atts `product_id`, `layout`, `title`, `height`.
	 */
	public static function reviews( array $atts ): string {
		$layout = sanitize_key( (string) ( $atts['layout'] ?? 'list' ) );
		$layout = in_array( $layout, self::LAYOUTS, true ) ? $layout : 'list';
		$ctx    = array( 'layout' => $layout );
		$pid    = self::numeric_id( $atts['product_id'] ?? '' ) ?? self::current_product();
		if ( null !== $pid ) {
			$ctx['productId'] = $pid;
		}
		$title = trim( sanitize_text_field( (string) ( $atts['title'] ?? '' ) ) );
		if ( '' !== $title ) {
			$ctx['title'] = mb_substr( $title, 0, 120 );
		}
		return self::html( 'reviews', $ctx, self::height( $atts['height'] ?? null, $layout ) );
	}

	/**
	 * Mis recompensas (necesita `igrUser`, que solo existe en Mi cuenta y el checkout).
	 *
	 * @param array<string, mixed> $atts `height`.
	 */
	public static function loyalty( array $atts = array() ): string {
		return self::html( 'loyalty', array(), self::height( $atts['height'] ?? null, 'loyalty' ) );
	}

	/**
	 * Banner por segmento.
	 *
	 * @param array<string, mixed> $atts `placement` (top o account), `height`.
	 */
	public static function banner( array $atts = array() ): string {
		$placement = 'top' === ( $atts['placement'] ?? 'account' ) ? 'top' : 'account';
		// Arriba es flotante (no empuja la página): no reserva espacio.
		$height = 'top' === $placement ? 0 : self::height( $atts['height'] ?? null, 'banner' );
		return self::html( 'banner', array( 'placement' => $placement ), $height );
	}

	/**
	 * «Avísame»: vuelve a tener stock o baja de precio.
	 *
	 * @param string $product_id Producto.
	 * @param string $kind       back_in_stock o price_drop.
	 */
	public static function bis( string $product_id, string $kind ): string {
		return self::html(
			'bis',
			array(
				'productId' => $product_id,
				'kind'      => 'price_drop' === $kind ? 'price_drop' : 'back_in_stock',
			),
			self::HEIGHTS['bis']
		);
	}

	/**
	 * «Gana N puntos».
	 *
	 * @param array<string, string> $ctx `productId`, `price` o `cartTotal`.
	 */
	public static function points( array $ctx ): string {
		return self::html( 'points', $ctx, self::HEIGHTS['points'] );
	}
}
