<?php
/**
 * Conexión con el hub: handshake, canje del código y desconexión (docs/api-connector.md §3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Connection;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * El canje es la única llamada al hub desde una petición de WordPress (la del admin que conecta),
 * igual que el canje de saldo: la inicia la persona.
 */
final class ConnectionManager {

	private const NONCE_TRANSIENT = 'igr_connect_nonce';

	/**
	 * URL del hub para iniciar la conexión (guarda el nonce 15 minutos).
	 */
	public static function connect_url(): string {
		$nonce = bin2hex( random_bytes( 16 ) );
		set_transient( self::NONCE_TRANSIENT, $nonce, 15 * MINUTE_IN_SECONDS );
		return add_query_arg(
			array(
				'site'     => rawurlencode( home_url() ),
				'nonce'    => $nonce,
				'return'   => rawurlencode( self::return_url() ),
				'platform' => class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress',
				'name'     => rawurlencode( wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ) ),
			),
			Connection::hub_url() . '/connect'
		);
	}

	/**
	 * URL a la que vuelve el navegador después de autorizar en el hub.
	 */
	public static function return_url(): string {
		return admin_url( 'admin.php?page=imagina-growth&igr_connect=1' );
	}

	/**
	 * Regreso del hub con `igr_code` e `igr_nonce`.
	 *
	 * @param string $code  Código de un solo uso.
	 * @param string $nonce Nonce devuelto.
	 * @return true|\WP_Error
	 */
	public static function handle_return( string $code, string $nonce ): bool|\WP_Error {
		$expected = get_transient( self::NONCE_TRANSIENT );
		if ( ! is_string( $expected ) || '' === $nonce || ! hash_equals( $expected, $nonce ) ) {
			return new \WP_Error( 'igr_nonce', __( 'La solicitud de conexión venció o no coincide. Vuelve a intentarlo.', 'imagina-growth' ) );
		}
		delete_transient( self::NONCE_TRANSIENT );
		return self::exchange( $code, $nonce );
	}

	/**
	 * Canjea un código (del regreso o pegado a mano) por las claves del sitio.
	 *
	 * @param string      $code  Código `IGR-XXXX-XXXX-XXXX`.
	 * @param string|null $nonce Nonce (null en la conexión manual).
	 * @return true|\WP_Error
	 */
	public static function exchange( string $code, ?string $nonce = null ): bool|\WP_Error {
		$code = strtoupper( trim( $code ) );
		if ( 1 !== preg_match( '/^IGR-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}-[0-9A-HJKMNP-TV-Z]{4}$/', $code ) ) {
			return new \WP_Error( 'igr_code', __( 'El código no tiene el formato IGR-XXXX-XXXX-XXXX.', 'imagina-growth' ) );
		}
		$payload  = array_merge(
			array(
				'code'           => $code,
				'nonce'          => $nonce,
				'site_url'       => home_url(),
				'rest_url'       => rest_url( 'imagina-growth/v1/' ),
				'platform'       => class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress',
				'name'           => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'plugin_version' => IGR_VERSION,
				'wp_version'     => (string) get_bloginfo( 'version' ),
				'wc_version'     => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
				'php_version'    => PHP_VERSION,
				'timezone'       => self::timezone_id(),
				'currency'       => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : null,
				'locale'         => get_locale(),
			),
			array( 'capabilities' => Capabilities::detect() )
		);
		$response = Http::json( 'POST', Connection::hub_url() . '/api/connect/exchange', $payload, 20 );
		if ( ! $response->ok() || ! is_array( $response->json ) ) {
			$error = is_array( $response->json ) ? (string) ( $response->json['error'] ?? '' ) : '';
			Logger::warning( 'El canje del código falló: ' . $response->describe() );
			return new \WP_Error( 'igr_exchange', self::exchange_message( $error, $response->status ) );
		}
		$data = $response->json;
		foreach ( array( 'site_id', 'secret', 'endpoints' ) as $required ) {
			if ( empty( $data[ $required ] ) ) {
				return new \WP_Error( 'igr_exchange', __( 'El hub respondió sin las claves del sitio.', 'imagina-growth' ) );
			}
		}
		if ( 1 !== preg_match( '/^st_[0-9A-HJKMNP-TV-Z]{26}$/', (string) $data['site_id'] ) ) {
			return new \WP_Error( 'igr_exchange', __( 'El hub devolvió un ID de sitio inválido.', 'imagina-growth' ) );
		}
		$previous = Connection::site_id();
		if ( '' !== $previous && $previous !== $data['site_id'] ) {
			// Los pendientes llevan el site_id anterior.
			Outbox::clear();
		}
		Connection::store( $data );
		self::after_connect();
		return true;
	}

	/**
	 * Tareas tras conectar: programación, configuración e importación histórica.
	 */
	public static function after_connect(): void {
		Scheduler::ensure_recurring();
		Scheduler::async( Scheduler::CONFIG );
		Runner::start( null, true );
	}

	/**
	 * Desconecta: borra las claves, cancela las tareas y opcionalmente vacía el outbox.
	 *
	 * @param bool $wipe_local Borrar también el historial del outbox.
	 */
	public static function disconnect( bool $wipe_local = false ): void {
		Scheduler::unschedule_all();
		Outbox::clear( $wipe_local );
		delete_option( Runner::OPTION );
		Connection::forget();
	}

	/**
	 * Zona horaria como identificador IANA (`America/Bogota`). Un sitio configurado con un
	 * desfase fijo (`-05:00`) envía null: el hub usa entonces la del espacio de trabajo.
	 */
	public static function timezone_id(): ?string {
		$tz = wp_timezone_string();
		return in_array( $tz, timezone_identifiers_list(), true ) ? $tz : null;
	}

	/**
	 * Mensaje legible para un error del canje.
	 *
	 * @param string $error  Código del hub.
	 * @param int    $status HTTP.
	 */
	private static function exchange_message( string $error, int $status ): string {
		switch ( $error ) {
			case 'invalid_code':
				return __( 'El código no es válido, ya se usó o venció (dura 10 minutos). Genera uno nuevo en Imagina Growth.', 'imagina-growth' );
			case 'nonce_mismatch':
				return __( 'La conexión no coincide con esta solicitud. Vuelve a pulsar «Conectar».', 'imagina-growth' );
			case 'site_mismatch':
				return __( 'El código se generó para otra dirección de sitio.', 'imagina-growth' );
		}
		if ( 0 === $status ) {
			return __( 'No pudimos comunicarnos con Imagina Growth. Revisa la conexión a internet del servidor.', 'imagina-growth' );
		}
		/* translators: %d: código HTTP. */
		return sprintf( __( 'Imagina Growth respondió con un error (%d). Intenta de nuevo en unos minutos.', 'imagina-growth' ), $status );
	}
}
