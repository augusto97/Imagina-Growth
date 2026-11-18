<?php
/**
 * Qué pedidos cuentan en la importación y la conciliación (docs/api-connector.md §1.2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Backfill;

/**
 * Pedidos `shop_order` con `date_created ≥ from`, excepto `checkout-draft`, `auto-draft` y `trash`.
 */
final class Scope {

	public const EXCLUDED = array( 'checkout-draft', 'auto-draft', 'trash' );

	/**
	 * Estados registrados que cuentan, con el prefijo `wc-` (para `wc_get_orders` y SQL).
	 *
	 * @return list<string>
	 */
	public static function statuses(): array {
		$out = array();
		foreach ( array_keys( wc_get_order_statuses() ) as $status ) {
			$plain = str_starts_with( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status;
			if ( ! in_array( $plain, self::EXCLUDED, true ) ) {
				$out[] = (string) $status;
			}
		}
		return $out;
	}

	/**
	 * Hora Unix de la medianoche de `from` en la zona del sitio.
	 *
	 * @param string $from Fecha `Y-m-d`.
	 */
	public static function from_timestamp( string $from ): int {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $from, wp_timezone() );
		return false === $date ? 0 : $date->getTimestamp();
	}

	/**
	 * Fecha inicial por defecto: hoy menos N meses.
	 *
	 * @param int $months Meses.
	 */
	public static function default_from( int $months ): string {
		return ( new \DateTimeImmutable( 'today', wp_timezone() ) )->modify( '-' . $months . ' months' )->format( 'Y-m-d' );
	}
}
