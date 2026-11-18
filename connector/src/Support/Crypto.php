<?php
/**
 * Cifrado del secreto del sitio en reposo (connector/CLAUDE.md §4).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * `sodium_crypto_secretbox` con una clave derivada de `wp_salt('auth')`.
 */
final class Crypto {

	private const PREFIX = 'igr1:';

	/**
	 * Cifra un texto.
	 *
	 * @param string      $plain Texto en claro.
	 * @param string|null $salt  Sal base (por defecto `wp_salt('auth')`).
	 */
	public static function encrypt( string $plain, ?string $salt = null ): string {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = sodium_crypto_secretbox( $plain, $nonce, self::key( $salt ) );
		return self::PREFIX . base64_encode( $nonce . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Descifra. Devuelve null si el dato no es válido o la sal cambió.
	 *
	 * @param string      $cipher Texto cifrado.
	 * @param string|null $salt   Sal base.
	 */
	public static function decrypt( string $cipher, ?string $salt = null ): ?string {
		if ( 0 !== strncmp( $cipher, self::PREFIX, strlen( self::PREFIX ) ) ) {
			return null;
		}
		$raw = base64_decode( substr( $cipher, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$box   = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$plain = sodium_crypto_secretbox_open( $box, $nonce, self::key( $salt ) );
		return false === $plain ? null : $plain;
	}

	/**
	 * Deriva la clave de 32 bytes.
	 *
	 * @param string|null $salt Sal base.
	 */
	private static function key( ?string $salt ): string {
		$base = $salt ?? ( function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : '' );
		return hash_hkdf( 'sha256', $base, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'imagina-growth:connection' );
	}
}
