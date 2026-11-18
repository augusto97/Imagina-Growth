<?php
/**
 * Endpoint REST firmado de comandos del hub (docs/api-connector.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * `POST /wp-json/imagina-growth/v1/commands`: firma (±300 s), nonce de 10 minutos e
 * idempotencia por `request_id` durante 24 h.
 */
final class CommandsController {

	public const NAMESPACE_V1 = 'imagina-growth/v1';

	/**
	 * Comandos disponibles.
	 *
	 * @var array<string, class-string<Command>>
	 */
	private const COMMANDS = array(
		'site.diagnostics'  => Handlers\SiteDiagnostics::class,
		'catalog.resync'    => Handlers\CatalogResync::class,
		'backfill.start'    => Handlers\BackfillStart::class,
		'secret.rotate'     => Handlers\SecretRotate::class,
		// Fase 2.
		'coupon.create'     => Handlers\CouponCreate::class,
		'coupon.disable'    => Handlers\CouponDisable::class,
		'order.add_note'    => Handlers\OrderAddNote::class,
		'product.lookup'    => Handlers\ProductLookup::class,
		'order.status'      => Handlers\OrderStatus::class,
		// Fase 3.
		'review.publish'    => Handlers\ReviewPublish::class,
		'review.reply'      => Handlers\ReviewReply::class,
		'review.unpublish'  => Handlers\ReviewUnpublish::class,
		'customer.set_tier' => Handlers\CustomerSetTier::class,
	);

	/**
	 * Registra la ruta.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE_V1,
			'/commands',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => '__return_true', // La firma HMAC se verifica en handle(): no hay usuario de WordPress.
			)
		);
	}

	/**
	 * Atiende un comando.
	 *
	 * @param \WP_REST_Request $request Petición.
	 */
	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = (string) $request->get_body();
		if ( ! Connection::is_connected() ) {
			return self::error( 'not_connected', 'El sitio no está conectado', 403 );
		}
		$signature = (string) $request->get_header( 'x-igr-signature' );
		if ( '' === $signature || ! Hmac::verify( $signature, $body, Connection::valid_secrets() ) ) {
			return self::error( 'signature', 'Firma inválida o fuera de la ventana de tiempo', 401 );
		}
		$data = Json::decode( $body );
		if ( null === $data || ! isset( $data['command'], $data['request_id'], $data['nonce'] ) || ! is_string( $data['command'] ) || ! is_string( $data['request_id'] ) || ! is_string( $data['nonce'] ) ) {
			return self::error( 'bad_request', 'Faltan command, request_id o nonce', 400 );
		}
		if ( strlen( $data['request_id'] ) > 64 || strlen( $data['nonce'] ) < 8 || strlen( $data['nonce'] ) > 128 ) {
			return self::error( 'bad_request', 'request_id o nonce con formato inválido', 400 );
		}

		// Idempotencia: la misma request_id devuelve la misma respuesta sin ejecutar otra vez.
		$cached = get_transient( self::request_key( $data['request_id'] ) );
		if ( is_array( $cached ) && isset( $cached['status'], $cached['body'] ) ) {
			return new \WP_REST_Response( $cached['body'], (int) $cached['status'] );
		}

		// Repetición: un nonce ya visto en los últimos 10 minutos se rechaza.
		$nonce_key = 'igr_cmd_n_' . substr( hash( 'sha256', $data['nonce'] ), 0, 32 );
		if ( false !== get_transient( $nonce_key ) ) {
			return self::error( 'replay', 'Nonce repetido', 409 );
		}
		set_transient( $nonce_key, 1, 10 * MINUTE_IN_SECONDS );

		$class = self::COMMANDS[ $data['command'] ] ?? null;
		if ( null === $class ) {
			$response = self::error( 'unknown_command', 'Comando desconocido: ' . sanitize_text_field( $data['command'] ), 404 );
		} else {
			try {
				$args     = is_array( $data['args'] ?? null ) ? $data['args'] : array();
				$result   = ( new $class() )->handle( $args );
				$response = new \WP_REST_Response(
					array(
						'ok'   => true,
						'data' => Json::obj( $result ),
					),
					200
				);
			} catch ( CommandError $e ) {
				$response = self::error( $e->error_code, $e->getMessage(), $e->status );
			} catch ( \Throwable $e ) {
				Logger::error( 'Error al ejecutar el comando ' . $data['command'] . ': ' . $e->getMessage() );
				$response = self::error( 'internal', 'Error interno al ejecutar el comando', 500 );
			}
		}
		set_transient(
			self::request_key( $data['request_id'] ),
			array(
				'status' => $response->get_status(),
				'body'   => $response->get_data(),
			),
			DAY_IN_SECONDS
		);
		return $response;
	}

	/**
	 * Respuesta de error.
	 *
	 * @param string $code    Código.
	 * @param string $message Mensaje.
	 * @param int    $status  HTTP.
	 */
	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error' => array(
					'code'    => $code,
					'message' => $message,
				),
			),
			$status
		);
	}

	/**
	 * Clave del transient de idempotencia.
	 *
	 * @param string $request_id ID de la petición.
	 */
	private static function request_key( string $request_id ): string {
		return 'igr_cmd_r_' . substr( hash( 'sha256', $request_id ), 0, 32 );
	}
}
