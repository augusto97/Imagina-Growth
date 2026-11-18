<?php
/**
 * Configuración pública del tracker.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Settings;

/**
 * Copia en `igr_state['trk']` (autocargada, < 1 KB) lo que el tracker necesita en
 * cada página: así las páginas cacheables no hacen ninguna consulta extra
 * (connector/CLAUDE.md §13). Se reconstruye al conectar, al leer /v1/s/config y
 * al guardar los ajustes.
 */
final class TrackerConfig {

	/**
	 * Reconstruye la copia.
	 */
	public static function rebuild(): void {
		if ( ! Connection::is_connected() ) {
			Connection::update_state( array( 'trk' => null ) );
			return;
		}
		$data     = Connection::data();
		$settings = Settings::all();
		$remote   = get_option( RemoteConfig::OPTION, array() );
		$remote   = is_array( $remote ) ? $remote : array();
		$browser  = (string) ( $data['endpoints']['ingest_browser'] ?? '' );
		$key      = (string) ( $data['public_key'] ?? '' );
		$origin   = self::origin( $browser );
		$pixels   = array();
		if ( 'full' === ( $remote['measurement']['mode'] ?? '' ) && is_array( $remote['measurement']['pixels'] ?? null ) ) {
			foreach ( array( 'meta', 'tiktok', 'google' ) as $platform ) {
				$id = $remote['measurement']['pixels'][ $platform ] ?? '';
				if ( is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9_-]{4,40}$/', $id ) ) {
					$pixels[ $platform ] = $id;
				}
			}
		}
		$roles  = $settings['excluded_roles'] ?? null;
		$mode   = (string) ( $remote['consent']['mode'] ?? 'auto' );
		$onsite = is_array( $remote['onsite'] ?? null ) ? $remote['onsite'] : array();
		Connection::update_state(
			array(
				'trk' => array(
					'on'    => (bool) $settings['tracker'] && '' !== $browser && '' !== $key,
					'site'  => Connection::site_id(),
					'key'   => $key,
					'ep'    => $browser,
					'wu'    => '' !== $origin && '' !== $key ? $origin . '/p/' . $key . '/widgets.json' : '',
					'px'    => $pixels,
					'wa'    => array(
						'enabled' => (bool) $settings['wa_ref'] && array() !== $settings['wa_numbers'],
						'format'  => (string) $settings['wa_format'],
						'numbers' => array_values( (array) $settings['wa_numbers'] ),
						// El widget de WhatsApp del hub usa la referencia aunque no haya números locales.
						'widgets' => (bool) $settings['wa_ref'],
					),
					'cd'    => (string) $settings['cookie_domain'],
					'cur'   => (string) ( $remote['workspace']['currency'] ?? '' ),
					'ban'   => (bool) $settings['banner'],
					'cm'    => in_array( $mode, array( 'strict', 'informed' ), true ) ? $mode : 'auto',
					'forms' => (bool) $settings['forms'],
					'roles' => null === $roles ? null : array_values( (array) $roles ),
					'ons'   => self::onsite( $settings, $onsite ),
				),
			)
		);
	}

	/**
	 * Elementos en el sitio activos (connector/CLAUDE.md §10): el ajuste local y, para lo
	 * que depende de un módulo del hub, `onsite` de `/v1/s/config` (`loyalty`, `wallet`,
	 * `product_watch`). Sin esa señal del hub, puntos, recompensas y saldo quedan apagados
	 * para no reservar espacio que nadie va a llenar.
	 *
	 * @param array<string, mixed> $settings Ajustes locales.
	 * @param array<string, mixed> $onsite   Módulos activos según el hub.
	 * @return array<string, bool>
	 */
	public static function onsite( array $settings, array $onsite ): array {
		$loyalty = ! empty( $onsite['loyalty'] );
		$watch   = $onsite['product_watch'] ?? true;
		return array(
			'bis'    => (bool) $settings['bis'] && (bool) $watch,
			'pd'     => (bool) $settings['price_drop'] && (bool) $watch,
			'points' => (bool) $settings['points'] && $loyalty,
			'rw'     => (bool) $settings['rewards'] && $loyalty,
			'wallet' => (bool) $settings['wallet'] && ! empty( $onsite['wallet'] ),
			'top'    => (bool) $settings['top_banner'],
		);
	}

	/**
	 * ¿Está activo un elemento en el sitio? (sin consultas: viene de `igr_state`).
	 *
	 * @param string $key bis, pd, points, rw, wallet o top.
	 */
	public static function on( string $key ): bool {
		$trk = self::get();
		return null !== $trk && ! empty( $trk['on'] ) && '' !== ( $trk['wu'] ?? '' ) && ! empty( $trk['ons'][ $key ] );
	}

	/**
	 * Copia guardada, o null si el tracker está apagado o el sitio desconectado.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get(): ?array {
		$state = Connection::state();
		$trk   = $state['trk'] ?? null;
		return is_array( $trk ) && ! empty( $trk['site'] ) ? $trk : null;
	}

	/**
	 * Origen (`https://in.growth.imagina.cloud`) de una URL.
	 *
	 * @param string $url URL.
	 */
	public static function origin( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		return ( $parts['scheme'] ?? 'https' ) . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
	}
}
