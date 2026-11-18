<?php
/**
 * Detección de capacidades del sitio (M02): se envía al conectar y en cada latido.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Connection;

/**
 * WooCommerce, HPOS, checkout de bloques, suscripciones, COGS, formularios, banner, caché y pasarelas.
 */
final class Capabilities {

	/** Plugins de formularios: clave → archivo principal. */
	private const FORMS = array(
		'cf7'            => 'contact-form-7/wp-contact-form-7.php',
		'wpforms'        => array( 'wpforms-lite/wpforms.php', 'wpforms/wpforms.php' ),
		'gravityforms'   => 'gravityforms/gravityforms.php',
		'fluentform'     => 'fluentform/fluentform.php',
		'elementor_pro'  => 'elementor-pro/elementor-pro.php',
		'jetformbuilder' => 'jetformbuilder/jet-form-builder.php',
		'formidable'     => 'formidable/formidable.php',
		'ninja_forms'    => 'ninja-forms/ninja-forms.php',
	);

	private const BANNERS = array(
		'complianz'          => array( 'complianz-gdpr/complianz-gpdr.php', 'complianz-gdpr-premium/complianz-gpdr-premium.php' ),
		'cookieyes'          => 'cookie-law-info/cookie-law-info.php',
		'cookiebot'          => 'cookiebot/cookiebot.php',
		'real_cookie_banner' => array( 'real-cookie-banner/index.php', 'real-cookie-banner-pro/index.php' ),
	);

	private const CACHES = array(
		'litespeed'     => 'litespeed-cache/litespeed-cache.php',
		'wp_rocket'     => 'wp-rocket/wp-rocket.php',
		'w3tc'          => 'w3-total-cache/w3-total-cache.php',
		'wp_super'      => 'wp-super-cache/wp-cache.php',
		'autoptimize'   => 'autoptimize/autoptimize.php',
		'perfmatters'   => 'perfmatters/perfmatters.php',
		'flyingpress'   => 'flying-press/flying-press.php',
		'imagina_turbo' => 'imagina-turbo/imagina-turbo.php',
	);

	/**
	 * Detecta las capacidades.
	 *
	 * @return array<string, mixed>
	 */
	public static function detect(): array {
		$active = array_map( 'strval', (array) get_option( 'active_plugins', array() ) );
		$wc     = class_exists( 'WooCommerce' );
		return array(
			'woocommerce'     => $wc,
			'hpos'            => $wc && class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),
			'checkout_blocks' => $wc && self::checkout_blocks(),
			'subscriptions'   => class_exists( 'WC_Subscriptions' ),
			'cogs'            => 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' ),
			'forms'           => self::matches( self::FORMS, $active ),
			'cookie_banner'   => function_exists( 'wp_has_consent' ) ? 'wp-consent-api' : ( self::matches( self::BANNERS, $active )[0] ?? null ),
			'cache'           => self::matches( self::CACHES, $active )[0] ?? null,
			'gateways'        => $wc ? self::gateways() : array(),
			'imagina_search'  => (bool) array_filter( $active, static fn( string $f ): bool => str_starts_with( $f, 'imagina-search/' ) ),
		);
	}

	/**
	 * ¿El checkout usa el bloque?
	 */
	private static function checkout_blocks(): bool {
		$page_id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		if ( $page_id <= 0 ) {
			return false;
		}
		$post = get_post( $page_id );
		return $post instanceof \WP_Post && has_block( 'woocommerce/checkout', $post );
	}

	/**
	 * Pasarelas habilitadas.
	 *
	 * @return list<string>
	 */
	private static function gateways(): array {
		if ( ! function_exists( 'WC' ) || null === WC()->payment_gateways() ) {
			return array();
		}
		$ids = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
			if ( 'yes' === $gateway->enabled ) {
				$ids[] = (string) $gateway->id;
			}
		}
		return $ids;
	}

	/**
	 * Claves cuyos plugins están activos.
	 *
	 * @param array<string, string|list<string>> $map    Mapa.
	 * @param list<string>                       $active Plugins activos.
	 * @return list<string>
	 */
	private static function matches( array $map, array $active ): array {
		$found = array();
		foreach ( $map as $key => $files ) {
			foreach ( (array) $files as $file ) {
				if ( in_array( $file, $active, true ) ) {
					$found[] = $key;
					break;
				}
			}
		}
		return $found;
	}
}
