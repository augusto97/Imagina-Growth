<?php
/**
 * Plugin Name:       Imagina Growth Connector
 * Plugin URI:        https://growth.imagina.cloud
 * Description:       Conecta tu sitio WordPress o tu tienda WooCommerce con Imagina Growth: datos de clientes, analítica, mensajería y fidelización, sin poner lento tu sitio.
 * Version:           0.1.3
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Imagina La Web
 * Author URI:        https://imaginaweb.co
 * License:           Propietaria
 * Text Domain:       imagina-growth
 * Domain Path:       /languages
 * WC requires at least: 8.5
 * WC tested up to:   11.3
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

define( 'IGR_VERSION', '0.1.3' );
define( 'IGR_PLUGIN_FILE', __FILE__ );
define( 'IGR_PLUGIN_DIR', __DIR__ );
define( 'IGR_DB_VERSION', '1' );

if ( ! defined( 'IGR_HUB_URL' ) ) {
	define( 'IGR_HUB_URL', 'https://growth.imagina.cloud' );
}

// Autoload: el de Composer si existe (desarrollo), o un PSR-4 mínimo (el plugin no tiene dependencias en producción).
if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class_name ): void {
			$prefix = 'ImaginaGrowth\\Connector\\';
			if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
				return;
			}
			$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	);
}

// Compatibilidad declarada con HPOS y el checkout de bloques.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', IGR_PLUGIN_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', IGR_PLUGIN_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( \ImaginaGrowth\Connector\Installer::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \ImaginaGrowth\Connector\Installer::class, 'deactivate' ) );

\ImaginaGrowth\Connector\Plugin::instance()->boot();
