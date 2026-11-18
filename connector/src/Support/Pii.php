<?php
/**
 * Enmascarado de datos personales para logs (CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * `Igr\Pii::mask()`: nunca se escribe un correo, teléfono o nombre en claro en un log.
 */
final class Pii {

	/** Claves cuyo valor se enmascara siempre. */
	private const SENSITIVE_KEYS = array(
		'email',
		'phone',
		'first_name',
		'last_name',
		'name',
		'company',
		'address_1',
		'address_2',
		'postcode',
		'ip',
		'user_agent',
		'secret',
		'password',
		'token',
		'code',
		'link_token',
	);

	/**
	 * Enmascara un valor: arreglos de forma recursiva (por clave) y textos con
	 * correos o teléfonos.
	 *
	 * @param mixed $value Valor.
	 * @return mixed
	 */
	public static function mask( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $k => $v ) {
				if ( is_string( $k ) && in_array( strtolower( $k ), self::SENSITIVE_KEYS, true ) && is_scalar( $v ) && '' !== (string) $v ) {
					$out[ $k ] = self::mask_scalar( (string) $k, (string) $v );
				} else {
					$out[ $k ] = self::mask( $v );
				}
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return self::mask_text( $value );
		}
		return $value;
	}

	/**
	 * Enmascara un correo: `a***@e***.com`.
	 *
	 * @param string $email Correo.
	 */
	public static function email( string $email ): string {
		$parts = explode( '@', $email, 2 );
		if ( 2 !== count( $parts ) ) {
			return '***';
		}
		$domain = explode( '.', $parts[1] );
		$tld    = count( $domain ) > 1 ? '.' . end( $domain ) : '';
		return mb_substr( $parts[0], 0, 1 ) . '***@' . mb_substr( $parts[1], 0, 1 ) . '***' . $tld;
	}

	/**
	 * Enmascara un teléfono dejando los 2 últimos dígitos.
	 *
	 * @param string $phone Teléfono.
	 */
	public static function phone( string $phone ): string {
		$digits = preg_replace( '/\D/', '', $phone ) ?? '';
		return '***' . substr( $digits, -2 );
	}

	/**
	 * Enmascara correos y secuencias de 7+ dígitos dentro de un texto libre.
	 *
	 * @param string $text Texto.
	 */
	public static function mask_text( string $text ): string {
		$text = (string) preg_replace_callback(
			'/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/',
			static fn( array $m ): string => self::email( $m[0] ),
			$text
		);
		$text = (string) preg_replace_callback(
			'/\+?\d[\d\s\-]{6,}\d/',
			static fn( array $m ): string => self::phone( $m[0] ),
			$text
		);
		$text = (string) preg_replace( '/sk_[A-Za-z0-9_\-]{8,}/', 'sk_***', $text );
		return $text;
	}

	/**
	 * Enmascara un valor según su clave.
	 *
	 * @param string $key   Clave.
	 * @param string $value Valor.
	 */
	private static function mask_scalar( string $key, string $value ): string {
		$key = strtolower( $key );
		if ( 'email' === $key ) {
			return self::email( $value );
		}
		if ( 'phone' === $key ) {
			return self::phone( $value );
		}
		return mb_substr( $value, 0, 1 ) . '***';
	}
}
