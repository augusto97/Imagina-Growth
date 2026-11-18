<?php
/**
 * Herramientas de privacidad de WordPress (connector/CLAUDE.md §11, cumplimiento.md §9).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Privacy;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Registra un *exporter* y un *eraser*. Al exportar o borrar los datos de una
 * persona desde WordPress se crea la solicitud equivalente en el hub
 * (`POST /v1/s/privacy/requests`, api-connector.md §5.4) desde Action Scheduler,
 * nunca durante la petición. El *eraser* borra además del outbox local los
 * eventos de esa persona que aún no salieron.
 */
final class PrivacyTools {

	public const ACTION     = 'igr_privacy_request';
	public const META       = '_igr_hub_request';
	private const MAX_TRIES = 6;
	private const PENDING   = 'igr_privacy_pending';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( self::class, 'exporters' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( self::class, 'erasers' ) );
		add_action( self::ACTION, array( self::class, 'send' ), 10, 3 );
	}

	/**
	 * @param mixed $exporters Exporters.
	 * @return array<string, mixed>
	 */
	public static function exporters( mixed $exporters ): array {
		$exporters                   = (array) $exporters;
		$exporters['imagina-growth'] = array(
			'exporter_friendly_name' => __( 'Imagina Growth', 'imagina-growth' ),
			'callback'               => array( self::class, 'export' ),
		);
		return $exporters;
	}

	/**
	 * @param mixed $erasers Erasers.
	 * @return array<string, mixed>
	 */
	public static function erasers( mixed $erasers ): array {
		$erasers                   = (array) $erasers;
		$erasers['imagina-growth'] = array(
			'eraser_friendly_name' => __( 'Imagina Growth', 'imagina-growth' ),
			'callback'             => array( self::class, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Exporter: los datos viven en el hub; aquí se pide su copia.
	 *
	 * @param mixed $email Correo.
	 * @param mixed $page  Página.
	 * @return array{data: list<array<string, mixed>>, done: bool}
	 */
	public static function export( mixed $email, mixed $page = 1 ): array {
		$queued = (int) $page <= 1 && self::queue( 'access', (string) $email );
		$data   = array();
		if ( $queued ) {
			$data[] = array(
				'group_id'    => 'imagina-growth',
				'group_label' => __( 'Imagina Growth', 'imagina-growth' ),
				'item_id'     => 'imagina-growth-request',
				'data'        => array(
					array(
						'name'  => __( 'Solicitud', 'imagina-growth' ),
						'value' => __( 'Se pidió a Imagina Growth la copia de los datos de esta persona. El responsable la recibe en su panel.', 'imagina-growth' ),
					),
				),
			);
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * Eraser: pide el borrado en el hub y quita del outbox los eventos pendientes de esa persona.
	 *
	 * @param mixed $email Correo.
	 * @param mixed $page  Página.
	 * @return array{items_removed: int|bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	public static function erase( mixed $email, mixed $page = 1 ): array {
		$email   = (string) $email;
		$removed = 0;
		$queued  = false;
		if ( (int) $page <= 1 && is_email( $email ) ) {
			$queued  = self::queue( 'delete', $email );
			$removed = self::erase_outbox( $email );
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => $queued ? array( __( 'Se pidió a Imagina Growth borrar los datos de esta persona.', 'imagina-growth' ) ) : array(),
			'done'           => true,
		);
	}

	/**
	 * Encola la solicitud al hub. El correo no viaja en los argumentos de la tarea:
	 * se guarda en una opción interna (autoload = no) y se borra al enviarse.
	 *
	 * @param string $kind  `access` o `delete`.
	 * @param string $email Correo.
	 */
	public static function queue( string $kind, string $email ): bool {
		$email = strtolower( trim( $email ) );
		if ( ! Connection::is_connected() || ! is_email( $email ) ) {
			return false;
		}
		$ref     = substr( hash( 'sha256', $kind . '|' . $email ), 0, 24 );
		$pending = self::pending();
		if ( isset( $pending[ $ref ] ) ) {
			return true;
		}
		$pending[ $ref ] = array(
			'kind'       => $kind,
			'email'      => $email,
			'wp_request' => self::wp_request_id( $kind, $email ),
			'at'         => time(),
		);
		update_option( self::PENDING, $pending, false );
		Scheduler::async( self::ACTION, array( $ref, $kind, 1 ) );
		return true;
	}

	/**
	 * Tarea: crea la solicitud en el hub. Reintenta con espera creciente.
	 *
	 * @param mixed $ref     Referencia interna.
	 * @param mixed $kind    Tipo.
	 * @param mixed $attempt Intento.
	 */
	public static function send( mixed $ref, mixed $kind = '', mixed $attempt = 1 ): void {
		$pending = self::pending();
		$item    = $pending[ (string) $ref ] ?? null;
		if ( null === $item || ! Connection::is_connected() ) {
			return;
		}
		$body = array(
			'kind'  => (string) $item['kind'],
			'email' => (string) $item['email'],
		);
		if ( null !== $item['wp_request'] ) {
			$body['wp_request_id'] = (int) $item['wp_request'];
		}
		$res = Http::signed( 'POST', Connection::api_url( 'v1/s/privacy/requests' ), Json::encode( $body ), Connection::site_id(), Connection::secret(), false, 15 );
		if ( $res->ok() ) {
			if ( null !== $item['wp_request'] && is_array( $res->json ) && isset( $res->json['request_id'] ) ) {
				update_post_meta( (int) $item['wp_request'], self::META, sanitize_text_field( (string) $res->json['request_id'] ) );
			}
			unset( $pending[ (string) $ref ] );
			update_option( self::PENDING, $pending, false );
			return;
		}
		Logger::warning( 'No se pudo crear la solicitud de privacidad en el hub: ' . $res->describe(), array( 'kind' => $item['kind'] ) );
		if ( (int) $attempt < self::MAX_TRIES && ( $res->retryable() || 0 === $res->status ) ) {
			Scheduler::single( self::ACTION, array( (string) $ref, (string) $item['kind'], (int) $attempt + 1 ), (int) min( DAY_IN_SECONDS, 60 * ( 5 ** (int) $attempt ) ) );
		}
	}

	/**
	 * Borra del outbox los eventos que mencionan el correo.
	 *
	 * @param string $email Correo.
	 */
	public static function erase_outbox( string $email ): int {
		global $wpdb;
		$table = Outbox::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Tabla propia.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE payload LIKE %s", '%' . $wpdb->esc_like( '"' . strtolower( $email ) . '"' ) . '%' ) );
	}

	/**
	 * Solicitudes pendientes.
	 *
	 * @return array<string, array{kind: string, email: string, wp_request: int|null, at: int}>
	 */
	public static function pending(): array {
		$pending = get_option( self::PENDING, array() );
		return is_array( $pending ) ? $pending : array();
	}

	/**
	 * ID de la solicitud de privacidad de WordPress para ese correo, si existe.
	 *
	 * @param string $kind  `access` o `delete`.
	 * @param string $email Correo.
	 */
	private static function wp_request_id( string $kind, string $email ): ?int {
		$posts = get_posts(
			array(
				'post_type'      => 'user_request',
				'post_name__in'  => array( 'access' === $kind ? 'export_personal_data' : 'remove_personal_data' ),
				'title'          => $email,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			)
		);
		return array() !== $posts ? (int) $posts[0] : null;
	}
}
