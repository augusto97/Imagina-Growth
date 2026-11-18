<?php
/**
 * Normalización ligera de teléfonos a E.164 (el hub normaliza en serio con libphonenumber).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Heurística para Colombia, México y Venezuela.
 */
final class Phone {

	private const CALLING_CODES = array(
		'CO' => '57',
		'MX' => '52',
		'VE' => '58',
		'PE' => '51',
		'EC' => '593',
		'CL' => '56',
		'AR' => '54',
		'US' => '1',
	);

	/**
	 * Devuelve E.164 (`+573001234567`) o null si no se puede asegurar.
	 *
	 * @param string $raw     Teléfono como lo escribió la persona.
	 * @param string $country País ISO-2 por defecto.
	 */
	public static function e164( string $raw, string $country = 'CO' ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		$plus   = str_starts_with( $raw, '+' ) || str_starts_with( $raw, '00' );
		$digits = preg_replace( '/\D/', '', $raw ) ?? '';
		if ( str_starts_with( $raw, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( $plus ) {
			return self::valid( '+' . $digits );
		}
		$cc = self::CALLING_CODES[ strtoupper( $country ) ] ?? null;
		if ( null === $cc ) {
			return null;
		}
		if ( 'CO' === strtoupper( $country ) ) {
			if ( 12 === strlen( $digits ) && str_starts_with( $digits, '57' ) ) {
				return self::valid( '+' . $digits );
			}
			if ( 10 === strlen( $digits ) && ( str_starts_with( $digits, '3' ) || str_starts_with( $digits, '60' ) ) ) {
				return self::valid( '+57' . $digits );
			}
			return null;
		}
		if ( str_starts_with( $digits, $cc ) && strlen( $digits ) > 10 ) {
			return self::valid( '+' . $digits );
		}
		$digits = ltrim( $digits, '0' );
		return self::valid( '+' . $cc . $digits );
	}

	/**
	 * Valida la forma E.164.
	 *
	 * @param string $e164 Candidato.
	 */
	private static function valid( string $e164 ): ?string {
		return 1 === preg_match( '/^\+[1-9]\d{6,14}$/', $e164 ) ? $e164 : null;
	}
}
