<?php
/**
 * Fechas ISO 8601 del contrato.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Formato `2026-11-17T18:05:00.123-05:00`.
 */
final class Dates {

	public const FORMAT = 'Y-m-d\TH:i:s.vP';

	/**
	 * Formatea una fecha (o null).
	 *
	 * @param DateTimeInterface|int|string|null $value Fecha, hora Unix o texto.
	 */
	public static function iso( DateTimeInterface|int|string|null $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_int( $value ) ) {
			$value = ( new DateTimeImmutable( '@' . $value ) )->setTimezone( self::tz() );
		} elseif ( is_string( $value ) ) {
			try {
				$value = new DateTimeImmutable( $value, self::tz() );
			} catch ( \Exception $e ) {
				return null;
			}
		}
		return $value->format( self::FORMAT );
	}

	/**
	 * Ahora, en la zona del sitio.
	 */
	public static function now(): string {
		return ( new DateTimeImmutable( 'now', self::tz() ) )->format( self::FORMAT );
	}

	/**
	 * Fecha y hora MySQL en UTC (para la tabla del outbox).
	 *
	 * @param int|null $time Hora Unix.
	 */
	public static function mysql_utc( ?int $time = null ): string {
		return gmdate( 'Y-m-d H:i:s', $time ?? time() );
	}

	/**
	 * Zona horaria del sitio.
	 */
	public static function tz(): DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
