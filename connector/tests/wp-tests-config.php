<?php
/**
 * Configuración de la librería de pruebas de WordPress. Todo sale de variables de entorno
 * (sin secretos en el repositorio). Usa una base de datos dedicada: la suite la vacía.
 *
 * @package ImaginaGrowth\Connector
 */

// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

define( 'ABSPATH', rtrim( (string) ( getenv( 'WP_CORE_DIR' ) ?: '/tmp/wordpress' ), '/' ) . '/' );
define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );
define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wp_igr_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: 'root' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1:3306' );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'AUTH_KEY', 'igr-tests-auth' );
define( 'SECURE_AUTH_KEY', 'igr-tests-secure-auth' );
define( 'LOGGED_IN_KEY', 'igr-tests-logged-in' );
define( 'NONCE_KEY', 'igr-tests-nonce' );
define( 'AUTH_SALT', 'igr-tests-auth-salt' );
define( 'SECURE_AUTH_SALT', 'igr-tests-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'igr-tests-logged-in-salt' );
define( 'NONCE_SALT', 'igr-tests-nonce-salt' );
define( 'WP_TESTS_DOMAIN', 'tienda.test' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Tienda de prueba' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_ENVIRONMENT_TYPE', 'local' );
define( 'IGR_HUB_URL', 'http://hub.test' );

$table_prefix = 'wptests_';
