<?php
/**
 * Estado de la conexión con el hub.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Connection;

use ImaginaGrowth\Connector\Support\Crypto;

/**
 * Lee y guarda `igr_connection` (autoload = no, secreto cifrado) y el estado mínimo
 * autocargado `igr_state` (< 1 KB) que decide si se registran los recolectores
 * sin consultas extra en cada petición.
 */
final class Connection {

	public const OPTION       = 'igr_connection';
	public const STATE_OPTION = 'igr_state';
	public const ROTATION_TTL = DAY_IN_SECONDS;

	/**
	 * Caché por petición.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $cache = null;

	/**
	 * Estado mínimo autocargado.
	 *
	 * @return array{site_id?:string, db?:string, checked_at?:int}
	 */
	public static function state(): array {
		$state = get_option( self::STATE_OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Actualiza claves del estado mínimo.
	 *
	 * @param array<string, mixed> $changes Cambios (null borra la clave).
	 */
	public static function update_state( array $changes ): void {
		$state = self::state();
		foreach ( $changes as $k => $v ) {
			if ( null === $v ) {
				unset( $state[ $k ] );
			} else {
				$state[ $k ] = $v;
			}
		}
		update_option( self::STATE_OPTION, $state, true );
	}

	/**
	 * ¿Está conectado? (sin consultas: usa la opción autocargada).
	 */
	public static function is_connected(): bool {
		return '' !== self::site_id();
	}

	/**
	 * ID del sitio (`st_…`) o cadena vacía.
	 */
	public static function site_id(): string {
		$state = self::state();
		return isset( $state['site_id'] ) && is_string( $state['site_id'] ) ? $state['site_id'] : '';
	}

	/**
	 * Datos completos de la conexión.
	 *
	 * @return array<string, mixed>
	 */
	public static function data(): array {
		if ( null === self::$cache ) {
			$data        = get_option( self::OPTION, array() );
			self::$cache = is_array( $data ) ? $data : array();
		}
		return self::$cache;
	}

	/**
	 * Secreto actual en claro.
	 */
	public static function secret(): string {
		$data = self::data();
		if ( empty( $data['secret'] ) || ! is_string( $data['secret'] ) ) {
			return '';
		}
		return (string) Crypto::decrypt( $data['secret'] );
	}

	/**
	 * Secretos válidos para verificar comandos: el actual y el anterior durante 24 h.
	 *
	 * @return list<string>
	 */
	public static function valid_secrets(): array {
		$secrets = array( self::secret() );
		$data    = self::data();
		if ( ! empty( $data['previous_secret'] ) && (int) ( $data['previous_secret_expires_at'] ?? 0 ) > time() ) {
			$secrets[] = (string) Crypto::decrypt( (string) $data['previous_secret'] );
		}
		return array_values( array_filter( $secrets ) );
	}

	/**
	 * Endpoint por nombre (`ingest_server`, `ingest_browser`, `api`).
	 *
	 * @param string $name Nombre.
	 */
	public static function endpoint( string $name ): string {
		$data = self::data();
		$url  = $data['endpoints'][ $name ] ?? '';
		return is_string( $url ) ? $url : '';
	}

	/**
	 * URL de la API del hub (para /v1/s/config, /v1/s/catalog/hashes y /v1/s/backfill).
	 *
	 * @param string $path Ruta relativa (`v1/s/config`).
	 */
	public static function api_url( string $path ): string {
		$ingest = self::endpoint( 'ingest_server' );
		// Los endpoints firmados /v1/s/* viven junto a la ingesta del servidor.
		if ( '' !== $ingest && str_starts_with( $path, 'v1/s/' ) ) {
			return rtrim( $ingest, '/' ) . '/' . substr( $path, strlen( 'v1/s/' ) );
		}
		$api = self::endpoint( 'api' );
		$api = '' !== $api ? $api : self::hub_url();
		return rtrim( $api, '/' ) . '/' . ltrim( $path, '/' );
	}

	/**
	 * URL base del hub.
	 */
	public static function hub_url(): string {
		return rtrim( (string) ( defined( 'IGR_HUB_URL' ) ? IGR_HUB_URL : 'https://growth.imagina.cloud' ), '/' );
	}

	/**
	 * Guarda una conexión nueva (respuesta del canje).
	 *
	 * @param array<string, mixed> $response Respuesta de /api/connect/exchange.
	 */
	public static function store( array $response ): void {
		$data = array(
			'site_id'                    => (string) $response['site_id'],
			'public_key'                 => (string) ( $response['public_key'] ?? '' ),
			'secret'                     => Crypto::encrypt( (string) $response['secret'] ),
			'previous_secret'            => null,
			'previous_secret_expires_at' => null,
			'workspace'                  => is_array( $response['workspace'] ?? null ) ? $response['workspace'] : array(),
			'endpoints'                  => is_array( $response['endpoints'] ?? null ) ? $response['endpoints'] : array(),
			'hub_url'                    => self::hub_url(),
			'connected_at'               => time(),
		);
		update_option( self::OPTION, $data, false );
		self::$cache = $data;
		self::update_state( array( 'site_id' => $data['site_id'] ) );
		\ImaginaGrowth\Connector\Tracking\TrackerConfig::rebuild();
	}

	/**
	 * Rota el secreto: el nuevo pasa a actual y el anterior vale 24 h.
	 *
	 * @param string $new_secret Secreto nuevo (`sk_…`).
	 */
	public static function rotate( string $new_secret ): void {
		$data                               = self::data();
		$data['previous_secret']            = $data['secret'] ?? null;
		$data['previous_secret_expires_at'] = time() + self::ROTATION_TTL;
		$data['secret']                     = Crypto::encrypt( $new_secret );
		$data['rotated_at']                 = time();
		update_option( self::OPTION, $data, false );
		self::$cache = $data;
	}

	/**
	 * Borra las claves.
	 */
	public static function forget(): void {
		delete_option( self::OPTION );
		delete_option( RemoteConfig::OPTION );
		self::$cache = null;
		self::update_state(
			array(
				'site_id' => null,
				'trk'     => null,
			)
		);
	}

	/**
	 * Limpia la caché por petición (pruebas).
	 */
	public static function flush_cache(): void {
		self::$cache = null;
	}
}
