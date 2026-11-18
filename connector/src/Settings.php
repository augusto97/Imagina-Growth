<?php
/**
 * Ajustes locales del sitio (pestaña «Datos» del admin).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector;

use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Opción `igr_settings` (autoload = no). Lo que necesita cada página se copia a
 * `igr_state` con TrackerConfig::rebuild() para no sumar consultas.
 */
final class Settings {

	public const OPTION = 'igr_settings';

	public const DEFAULTS = array(
		'tracker'        => true,
		'forms'          => true,
		'wa_ref'         => true,
		'wa_numbers'     => array(),
		'wa_format'      => '{text} (ref: {token})',
		'banner'         => false,
		'cookie_domain'  => '',
		'excluded_roles' => null, // null = los del hub (`/v1/s/config`).
	);

	/**
	 * Ajustes con valores por defecto.
	 *
	 * @return array<string, mixed>
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? array_intersect_key( $stored, self::DEFAULTS ) : array() );
	}

	/**
	 * Un ajuste.
	 *
	 * @param string $key Clave.
	 */
	public static function get( string $key ): mixed {
		return self::all()[ $key ] ?? null;
	}

	/**
	 * Guarda y reconstruye la configuración pública del tracker.
	 *
	 * @param array<string, mixed> $changes Cambios.
	 */
	public static function save( array $changes ): void {
		$all = array_merge( self::all(), array_intersect_key( $changes, self::DEFAULTS ) );
		update_option( self::OPTION, self::sanitize( $all ), false );
		TrackerConfig::rebuild();
	}

	/**
	 * Sanea los ajustes.
	 *
	 * @param array<string, mixed> $s Ajustes.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $s ): array {
		$numbers = is_array( $s['wa_numbers'] ) ? $s['wa_numbers'] : preg_split( '/[\s,;]+/', (string) $s['wa_numbers'] );
		$numbers = array_values( array_unique( array_filter( array_map( static fn( $n ): string => (string) preg_replace( '/\D/', '', (string) $n ), (array) $numbers ), static fn( string $n ): bool => strlen( $n ) >= 8 && strlen( $n ) <= 15 ) ) );
		$format  = sanitize_text_field( (string) $s['wa_format'] );
		$domain  = strtolower( trim( (string) $s['cookie_domain'] ) );
		$roles   = null === $s['excluded_roles'] ? null : array_values( array_filter( array_map( 'sanitize_key', (array) $s['excluded_roles'] ) ) );
		return array(
			'tracker'        => (bool) $s['tracker'],
			'forms'          => (bool) $s['forms'],
			'wa_ref'         => (bool) $s['wa_ref'],
			'wa_numbers'     => array_slice( $numbers, 0, 10 ),
			'wa_format'      => str_contains( $format, '{token}' ) ? mb_substr( $format, 0, 80 ) : self::DEFAULTS['wa_format'],
			'banner'         => (bool) $s['banner'],
			'cookie_domain'  => 1 === preg_match( '/^\.?[a-z0-9.-]{3,190}$/', $domain ) ? $domain : '',
			'excluded_roles' => $roles,
		);
	}
}
