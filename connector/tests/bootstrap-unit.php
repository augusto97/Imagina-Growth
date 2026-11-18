<?php
/**
 * Arranque de la suite unitaria (sin WordPress).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'IGR_VERSION' ) ) {
	define( 'IGR_VERSION', '0.1.0' );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );
}
