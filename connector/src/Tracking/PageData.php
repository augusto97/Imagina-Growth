<?php
/**
 * Carga del tracker y datos de la página (connector/CLAUDE.md §9, api-connector.md §7).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

use ImaginaGrowth\Connector\Collectors\Roles;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Consent\ConsentState;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Support\Uuid;

/**
 * `igrConfig` e `igrPage` no dependen del visitante (cacheables). `igrUser` e
 * `igrOrder` solo se imprimen en páginas que WooCommerce no deja cachear
 * (Mi cuenta, checkout y gracias).
 */
final class PageData {

	private const JSON = JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 20 );
		add_action( 'woocommerce_thankyou', array( self::class, 'thankyou_slot' ), 99 );
	}

	/**
	 * Encola el tracker (`defer`, pie de página) y su configuración en línea.
	 */
	public static function enqueue(): void {
		$trk = TrackerConfig::get();
		if ( null === $trk || empty( $trk['on'] ) || self::excluded( $trk ) ) {
			return;
		}
		$url = static fn( string $file ): string => plugins_url( 'assets/' . $file, IGR_PLUGIN_FILE );
		wp_register_script(
			'igr-tracker',
			$url( 'tracker.min.js' ),
			array(),
			IGR_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
		wp_enqueue_script( 'igr-tracker' );
		wp_add_inline_script( 'igr-tracker', self::inline( $trk ), 'before' );

		if ( 'igr-banner' === ConsentState::banner() ) {
			wp_enqueue_script(
				'igr-banner',
				$url( 'igr-banner.js' ),
				array(),
				IGR_VERSION,
				array(
					'strategy'  => 'defer',
					'in_footer' => true,
				)
			);
		}
	}

	/**
	 * JavaScript en línea con los datos de la página.
	 *
	 * @param array<string, mixed> $trk Configuración guardada.
	 */
	public static function inline( array $trk ): string {
		$js   = 'window.igrConfig=' . wp_json_encode( self::config( $trk ), self::JSON ) . ';window.igrPage=' . wp_json_encode( (object) self::page(), self::JSON ) . ';';
		$user = self::user();
		if ( null !== $user ) {
			$js .= 'window.igrUser=' . wp_json_encode( $user, self::JSON ) . ';';
		}
		$order = self::order();
		if ( null !== $order ) {
			$js .= 'window.igrOrder=' . wp_json_encode( $order, self::JSON ) . ';';
		}
		return $js;
	}

	/**
	 * `window.igrConfig` (igual para todos los visitantes).
	 *
	 * @param array<string, mixed> $trk Configuración guardada.
	 * @return array<string, mixed>
	 */
	public static function config( array $trk ): array {
		$asset = static fn( string $file ): string => plugins_url( 'assets/' . $file, IGR_PLUGIN_FILE ) . '?ver=' . IGR_VERSION;
		$cfg   = array(
			'siteId'      => (string) $trk['site'],
			'siteKey'     => (string) $trk['key'],
			'endpoint'    => (string) $trk['ep'],
			'platform'    => class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress', // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Valor del contrato (source.platform).
			'consentMode' => ConsentState::mode(),
			'consent'     => ConsentState::defaults(),
			'pingUrl'     => home_url( '/?igr_ping=1' ),
			'waRef'       => $trk['wa'] ?? array( 'enabled' => false ),
		);
		if ( '' !== ( $trk['cd'] ?? '' ) ) {
			$cfg['cookieDomain'] = (string) $trk['cd'];
		}
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : (string) ( $trk['cur'] ?? '' );
		if ( '' !== $currency ) {
			$cfg['currency'] = $currency;
		}
		if ( '' !== ( $trk['wu'] ?? '' ) ) {
			$cfg['widgetsUrl'] = (string) $trk['wu'];
			$cfg['widgetsJs']  = $asset( 'igr-widgets.js' );
		}
		if ( ! empty( $trk['px'] ) ) {
			$cfg['pixels']   = $trk['px'];
			$cfg['pixelsJs'] = $asset( 'igr-pixels.js' );
		}
		if ( 'igr-banner' === ( $cfg['consent']['source'] ?? '' ) ) {
			$privacy       = get_privacy_policy_url();
			$cfg['banner'] = array(
				'text'         => __( 'Usamos cookies para medir las visitas y mostrarte ofertas que te interesen. Puedes aceptarlas o seguir solo con las necesarias.', 'imagina-growth' ),
				'accept'       => __( 'Aceptar', 'imagina-growth' ),
				'reject'       => __( 'Solo las necesarias', 'imagina-growth' ),
				'privacyUrl'   => '' !== $privacy ? $privacy : null,
				'privacyLabel' => __( 'Política de privacidad', 'imagina-growth' ),
			);
		}
		return $cfg;
	}

	/**
	 * `window.igrPage`: tipo de página y objeto (no depende del visitante).
	 *
	 * @return array<string, mixed>
	 */
	public static function page(): array {
		$out = array( 'type' => 'other' );
		$woo = function_exists( 'is_woocommerce' );
		if ( $woo && is_order_received_page() ) {
			$out['type'] = 'thankyou';
		} elseif ( $woo && is_checkout() ) {
			$out['type'] = 'checkout';
		} elseif ( $woo && is_cart() ) {
			$out['type'] = 'cart';
		} elseif ( $woo && is_account_page() ) {
			$out['type'] = 'account';
		} elseif ( $woo && is_product() ) {
			$out = array_merge( $out, self::product_page() );
		} elseif ( $woo && ( is_product_category() || is_product_tag() || is_shop() ) ) {
			$out['type'] = 'category';
			$term        = get_queried_object();
			if ( $term instanceof \WP_Term ) {
				$out['objectId']   = (string) $term->term_id;
				$out['objectType'] = $term->taxonomy;
				$out['name']       = mb_substr( html_entity_decode( $term->name, ENT_QUOTES ), 0, 200 );
			}
		} elseif ( is_search() ) {
			global $wp_query;
			$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Lectura de la búsqueda pública.
			$out['type']   = 'search';
			$out['search'] = array(
				'query'   => mb_substr( get_search_query( false ), 0, 300 ),
				'results' => (int) ( $wp_query->found_posts ?? 0 ),
				'engine'  => 'product' === $post_type ? 'wc' : 'wp',
			);
		} elseif ( is_front_page() ) {
			$out['type'] = 'home';
		} elseif ( is_singular( 'post' ) || is_home() || is_category() || is_tag() || is_author() || is_date() ) {
			$out['type'] = 'blog';
		} elseif ( is_page() ) {
			$out['type'] = 'landing';
		}
		if ( ! isset( $out['objectId'] ) && is_singular() ) {
			$out['objectId']   = (string) get_queried_object_id();
			$out['objectType'] = (string) get_post_type();
		}
		return $out;
	}

	/**
	 * Ficha de producto.
	 *
	 * @return array<string, mixed>
	 */
	private static function product_page(): array {
		$out     = array( 'type' => 'product' );
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product instanceof \WC_Product ) {
			return $out;
		}
		$out['objectId']   = (string) $product->get_id();
		$out['objectType'] = 'product';
		$out['product']    = array(
			'id'         => (string) $product->get_id(),
			'sku'        => '' !== $product->get_sku() ? mb_substr( $product->get_sku(), 0, 100 ) : null,
			'name'       => mb_substr( html_entity_decode( $product->get_name(), ENT_QUOTES ), 0, 300 ),
			'price'      => Money::format( $product->get_price() ),
			'currency'   => get_woocommerce_currency(),
			'categories' => array_map( 'strval', $product->get_category_ids() ),
			'inStock'    => $product->is_in_stock(),
		);
		return $out;
	}

	/**
	 * `window.igrUser` (solo Mi cuenta y checkout con sesión).
	 *
	 * @return array{ref: string, token: string}|null
	 */
	public static function user(): ?array {
		if ( ! is_user_logged_in() || ! function_exists( 'is_account_page' ) || ! ( is_account_page() || is_checkout() ) ) {
			return null;
		}
		$secret = Connection::secret();
		if ( '' === $secret ) {
			return null;
		}
		$ref = 'wc:' . get_current_user_id();
		return array(
			'ref'   => $ref,
			'token' => PageToken::user( $ref, Connection::site_id(), $secret ),
		);
	}

	/**
	 * `window.igrOrder` en la página de gracias (pedido y clave verificados).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function order(): ?array {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return null;
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$key      = isset( $_GET['key'] ) ? wc_clean( wp_unslash( (string) $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- La clave del pedido es la verificación.
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof \WC_Order || ! is_string( $key ) || '' === $key || ! hash_equals( $order->get_order_key(), $key ) ) {
			return null;
		}
		return self::order_data( $order );
	}

	/**
	 * Datos de `igrOrder` para un pedido.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>|null
	 */
	public static function order_data( \WC_Order $order ): ?array {
		$secret  = Connection::secret();
		$site_id = Connection::site_id();
		if ( '' === $secret || '' === $site_id ) {
			return null;
		}
		$paid = RemoteConfig::is_paid( (string) $order->get_status(), (string) $order->get_payment_method() );
		$id   = (string) $order->get_id();
		return array(
			'eventId'  => Uuid::event( $site_id, 'order:' . $id . ':' . ( $paid ? 'paid' : 'created' ) ),
			'orderId'  => $id,
			'value'    => Money::format( $order->get_total() ),
			'currency' => $order->get_currency(),
			'paid'     => $paid,
			'token'    => PageToken::order( $id, $site_id, $secret ),
		);
	}

	/**
	 * Espacio para la encuesta «¿cómo nos conociste?» (se llena por JS si está activa).
	 */
	public static function thankyou_slot(): void {
		$trk = TrackerConfig::get();
		if ( null === $trk || empty( $trk['on'] ) || '' === ( $trk['wu'] ?? '' ) ) {
			return;
		}
		echo '<div class="igr-slot" data-igr-slot="thankyou"></div>';
	}

	/**
	 * ¿El usuario actual está excluido de la medición? (administradores por defecto).
	 *
	 * @param array<string, mixed> $trk Configuración guardada.
	 */
	private static function excluded( array $trk ): bool {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		if ( is_array( $trk['roles'] ?? null ) ) {
			$user = wp_get_current_user();
			return array() !== array_intersect( (array) $user->roles, $trk['roles'] );
		}
		return Roles::is_excluded();
	}
}
