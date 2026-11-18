<?php
/**
 * UUID v4, v5 y v7 sin dependencias.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Generador de UUID.
 */
final class Uuid {

	/** Espacio de nombres de los event_id deterministas (docs/api-connector.md §1.1). */
	public const EVENT_NAMESPACE = '4a9293ae-2f42-5b83-905c-3b8de1f000cb';

	/**
	 * UUID v4 aleatorio.
	 */
	public static function v4(): string {
		$bytes    = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return self::format( $bytes );
	}

	/**
	 * UUID v7: 48 bits de milisegundos Unix + aleatorio. Ordenable por tiempo.
	 *
	 * @param int|null $ms Milisegundos Unix; por defecto, ahora.
	 */
	public static function v7( ?int $ms = null ): string {
		$ms    = $ms ?? (int) floor( microtime( true ) * 1000 );
		$time  = substr( pack( 'J', $ms ), 2, 6 );
		$bytes = $time . random_bytes( 10 );
		// Versión 7 y variante RFC 4122.
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x70 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return self::format( $bytes );
	}

	/**
	 * UUID v5 (SHA-1 de espacio de nombres + nombre).
	 *
	 * @param string $name_space UUID del espacio de nombres.
	 * @param string $name       Nombre.
	 */
	public static function v5( string $name_space, string $name ): string {
		$ns    = (string) hex2bin( str_replace( '-', '', $name_space ) );
		$hash  = sha1( $ns . $name, true );
		$bytes = substr( $hash, 0, 16 );
		// Versión 5 y variante RFC 4122.
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x50 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		return self::format( $bytes );
	}

	/**
	 * `event_id` determinista: uuid5(EVENT_NAMESPACE, "{site_id}:{clave}").
	 *
	 * @param string $site_id ID del sitio (`st_…`).
	 * @param string $key     Clave del objeto (p. ej. `order:1234:paid`).
	 */
	public static function event( string $site_id, string $key ): string {
		return self::v5( self::EVENT_NAMESPACE, $site_id . ':' . $key );
	}

	/**
	 * ¿Es un UUID con formato válido?
	 *
	 * @param string $value Valor.
	 */
	public static function is_valid( string $value ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * Formatea 16 bytes como UUID.
	 *
	 * @param string $bytes 16 bytes.
	 */
	private static function format( string $bytes ): string {
		$hex = bin2hex( $bytes );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}
}
