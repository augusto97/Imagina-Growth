<?php
/**
 * Formato de montos del contrato: decimal en texto con 2 decimales.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Montos como texto (`"159900.00"`), docs/api-connector.md §1.2.
 */
final class Money {

	/**
	 * Formatea con 2 decimales y punto.
	 *
	 * @param mixed $value Valor numérico o texto.
	 */
	public static function format( mixed $value ): string {
		if ( null === $value || '' === $value ) {
			return '0.00';
		}
		return number_format( (float) $value, 2, '.', '' );
	}

	/**
	 * Como format(), pero devuelve null si el valor está vacío.
	 *
	 * @param mixed $value Valor.
	 */
	public static function format_or_null( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		return self::format( $value );
	}

	/**
	 * Suma dos montos decimales en texto sin pasar por float (sin bcmath).
	 *
	 * @param string $a Monto con hasta 2 decimales.
	 * @param string $b Monto con hasta 2 decimales.
	 */
	public static function add( string $a, string $b ): string {
		return self::from_cents( self::to_cents( $a ) + self::to_cents( $b ) );
	}

	/**
	 * Texto decimal → centavos enteros (redondeo a 2 decimales).
	 *
	 * @param string $value Monto.
	 */
	public static function to_cents( string $value ): int {
		$value = trim( $value );
		$neg   = str_starts_with( $value, '-' );
		$value = ltrim( $value, '-+' );
		$parts = explode( '.', $value, 2 );
		$int   = '' === $parts[0] ? '0' : $parts[0];
		$frac  = str_pad( $parts[1] ?? '', 3, '0' );
		$cents = ( (int) $int ) * 100 + (int) substr( $frac, 0, 2 );
		if ( (int) $frac[2] >= 5 ) {
			++$cents;
		}
		return $neg ? -$cents : $cents;
	}

	/**
	 * Centavos → texto con 2 decimales.
	 *
	 * @param int $cents Centavos.
	 */
	public static function from_cents( int $cents ): string {
		$neg = $cents < 0;
		$abs = abs( $cents );
		return ( $neg ? '-' : '' ) . intdiv( $abs, 100 ) . '.' . str_pad( (string) ( $abs % 100 ), 2, '0', STR_PAD_LEFT );
	}
}
