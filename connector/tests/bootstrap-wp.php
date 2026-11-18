<?php
/**
 * Arranque de la suite con WordPress y WooCommerce reales.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$igr_wp_tests = getenv( 'WP_PHPUNIT__DIR' );
if ( ! is_string( $igr_wp_tests ) || ! is_dir( $igr_wp_tests ) ) {
	fwrite( STDERR, "Falta wp-phpunit/wp-phpunit (composer install).\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	exit( 1 );
}
$igr_wc_dir = rtrim( (string) ( getenv( 'WC_PLUGIN_DIR' ) ?: '' ), '/' );
if ( '' === $igr_wc_dir ) {
	$igr_core   = rtrim( (string) ( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress' ), '/' );
	$igr_wc_dir = $igr_core . '/wp-content/plugins/woocommerce';
}

require_once $igr_wp_tests . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $igr_wc_dir ): void {
		// HPOS activo por defecto, como en las tiendas nuevas; las pruebas pueden cambiarlo.
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		require_once $igr_wc_dir . '/woocommerce.php';
		// Plugins de formularios de wordpress.org, si están instalados junto a WooCommerce
		// (las pruebas de cada adaptador se saltan si falta su plugin).
		foreach ( array( 'contact-form-7/wp-contact-form-7.php', 'wpforms-lite/wpforms.php' ) as $igr_form_plugin ) {
			if ( '1' !== getenv( 'IGR_TESTS_NO_FORMS' ) && is_readable( dirname( $igr_wc_dir ) . '/' . $igr_form_plugin ) ) {
				require_once dirname( $igr_wc_dir ) . '/' . $igr_form_plugin;
			}
		}
		require_once dirname( __DIR__ ) . '/imagina-growth-connector.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		// Instala las tablas de WooCommerce y las del plugin en la base de pruebas.
		\WC_Install::install();
		if ( class_exists( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class ) ) {
			$sync = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class );
			$sync->create_database_tables();
		}
		update_option( 'woocommerce_custom_orders_table_enabled', 'yes' );
		update_option( 'woocommerce_currency', 'COP' );
		update_option( 'woocommerce_default_country', 'CO:CO-CAL' );
		update_option( 'timezone_string', 'America/Bogota' );
		$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		wp_roles();
		\ImaginaGrowth\Connector\Installer::activate();
	}
);

require_once $igr_wp_tests . '/includes/bootstrap.php';
require_once __DIR__ . '/Support/Schema.php';
require_once __DIR__ . '/Support/WpTestCase.php';
