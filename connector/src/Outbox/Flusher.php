<?php
/**
 * Envío del outbox al hub por lotes firmados y comprimidos.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Outbox;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\HttpResponse;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Acción `igr_flush_outbox` (cada minuto) e `igr_flush_now` (tras cada lote del backfill).
 *
 * Toma hasta 500 eventos o 1 MB comprimido, los envía a `/v1/s` y procesa la respuesta
 * (docs/api-connector.md §4.1). Repite mientras quede tiempo en el presupuesto.
 */
final class Flusher {

	public const MAX_EVENTS     = 500;
	public const MAX_GZIP_BYTES = 1048576;
	public const STATUS_OPTION  = 'igr_flush_status';

	/** Bytes sin comprimir que se toman por lote antes de comprimir (luego se ajusta). */
	private const MAX_RAW_BYTES = 8388608;

	/**
	 * Ejecuta el envío.
	 *
	 * @param int $budget_seconds Tiempo máximo.
	 * @return array{batches:int, sent:int, dead:int, retried:int, error:?string}
	 */
	public static function run( int $budget_seconds = 20 ): array {
		$stats = array(
			'batches' => 0,
			'sent'    => 0,
			'dead'    => 0,
			'retried' => 0,
			'error'   => null,
		);
		if ( ! Connection::is_connected() ) {
			return $stats;
		}
		$url = Connection::endpoint( 'ingest_server' );
		if ( '' === $url ) {
			$stats['error'] = 'sin endpoint de ingesta';
			return $stats;
		}
		if ( ! self::lock() ) {
			$stats['error'] = 'otro envío en curso';
			return $stats;
		}
		try {
			Outbox::recover_stale();
			$deadline = microtime( true ) + $budget_seconds;
			$limit    = self::batch_cap();
			do {
				$rows = Outbox::claim( $limit, self::MAX_RAW_BYTES );
				if ( array() === $rows ) {
					break;
				}
				$result = self::send_batch( $url, $rows );
				++$stats['batches'];
				$stats['sent']    += $result['sent'];
				$stats['dead']    += $result['dead'];
				$stats['retried'] += $result['retried'];
				if ( null !== $result['error'] ) {
					$stats['error'] = $result['error'];
					break;
				}
			} while ( microtime( true ) < $deadline );
		} finally {
			self::unlock();
		}
		self::save_status( $stats );
		return $stats;
	}

	/**
	 * Envía un lote.
	 *
	 * @param string                                                                                     $url  Endpoint.
	 * @param list<array{id:int, event_id:string, event:string, payload:string, attempts:int}> $rows Filas.
	 * @return array{sent:int, dead:int, retried:int, error:?string}
	 */
	private static function send_batch( string $url, array $rows ): array {
		$out = array(
			'sent'    => 0,
			'dead'    => 0,
			'retried' => 0,
			'error'   => null,
		);
		// Ajusta el lote para que comprimido no pase de 1 MB.
		$body  = self::body( $rows );
		$count = count( $rows );
		$size  = strlen( (string) gzencode( $body, 6 ) );
		while ( $count > 1 && $size > self::MAX_GZIP_BYTES ) {
			$half = (int) ceil( $count / 2 );
			Outbox::release( array_column( array_slice( $rows, $half ), 'id' ) );
			$rows  = array_slice( $rows, 0, $half );
			$body  = self::body( $rows );
			$count = count( $rows );
			$size  = strlen( (string) gzencode( $body, 6 ) );
		}

		$response = Http::signed( 'POST', $url, $body, Connection::site_id(), Connection::secret(), true, 15 );

		if ( 202 === $response->status || 200 === $response->status ) {
			return self::handle_accepted( $rows, $response );
		}
		if ( 413 === $response->status ) {
			if ( 1 === count( $rows ) ) {
				Outbox::mark_dead( array( $rows[0]['id'] ), 'too_large' );
				$out['dead'] = 1;
				return $out;
			}
			set_transient( 'igr_batch_cap', max( 1, intdiv( count( $rows ), 2 ) ), HOUR_IN_SECONDS );
			Outbox::release( array_column( $rows, 'id' ) );
			$out['error'] = 'lote demasiado grande; se reduce';
			return $out;
		}
		// Red, timeout, 5xx, 401/403 u otros: reintento con espera.
		Outbox::mark_retry( $rows, $response->describe() );
		$out['retried'] = count( $rows );
		$out['error']   = $response->describe();
		if ( 401 === $response->status || 403 === $response->status ) {
			Logger::error( 'El hub rechazó la firma o el sitio: ' . $response->describe() );
		} else {
			Logger::warning( 'Envío al hub fallido, se reintentará: ' . $response->describe(), array( 'events' => count( $rows ) ) );
		}
		return $out;
	}

	/**
	 * Procesa un 202: los rechazados pasan a `dead` con su motivo y el resto a `sent`.
	 *
	 * @param list<array{id:int, event_id:string, event:string, payload:string, attempts:int}> $rows     Filas.
	 * @param HttpResponse                                                                         $response Respuesta.
	 * @return array{sent:int, dead:int, retried:int, error:?string}
	 */
	private static function handle_accepted( array $rows, HttpResponse $response ): array {
		$rejected = array();
		if ( is_array( $response->json ) && is_array( $response->json['rejected'] ?? null ) ) {
			foreach ( $response->json['rejected'] as $item ) {
				if ( is_array( $item ) && isset( $item['event_id'] ) ) {
					$rejected[ (string) $item['event_id'] ] = (string) ( $item['reason'] ?? 'rejected' );
				}
			}
		}
		$sent = array();
		$dead = 0;
		foreach ( $rows as $row ) {
			if ( isset( $rejected[ $row['event_id'] ] ) ) {
				Outbox::mark_dead( array( $row['id'] ), 'rejected: ' . $rejected[ $row['event_id'] ] );
				++$dead;
				Logger::warning(
					'El hub rechazó un evento',
					array(
						'event'  => $row['event'],
						'reason' => $rejected[ $row['event_id'] ],
					)
				);
			} else {
				$sent[] = $row['id'];
			}
		}
		Outbox::mark_sent( $sent );
		return array(
			'sent'    => count( $sent ),
			'dead'    => $dead,
			'retried' => 0,
			'error'   => null,
		);
	}

	/**
	 * Arma el cuerpo sin decodificar los payloads (ya son JSON).
	 *
	 * @param list<array{payload:string}> $rows Filas.
	 */
	public static function body( array $rows ): string {
		return '{"sent_at":"' . Dates::now() . '","events":[' . implode( ',', array_column( $rows, 'payload' ) ) . ']}';
	}

	/**
	 * Tamaño de lote vigente (se reduce tras un 413).
	 */
	private static function batch_cap(): int {
		$cap = (int) get_transient( 'igr_batch_cap' );
		return $cap > 0 ? min( self::MAX_EVENTS, $cap ) : self::MAX_EVENTS;
	}

	/**
	 * Último resultado para el panel.
	 *
	 * @param array{batches:int, sent:int, dead:int, retried:int, error:?string} $stats Resultado.
	 */
	private static function save_status( array $stats ): void {
		$status                    = get_option( self::STATUS_OPTION, array() );
		$status                    = is_array( $status ) ? $status : array();
		$status['last_attempt_at'] = time();
		if ( $stats['batches'] > 0 ) {
			$status['last_batch'] = $stats;
		}
		if ( null === $stats['error'] && $stats['sent'] > 0 ) {
			$status['last_success_at'] = time();
		}
		$status['last_error'] = $stats['error'];
		update_option( self::STATUS_OPTION, $status, false );
	}

	/**
	 * Bloqueo con nombre de MySQL para que no corran dos envíos a la vez.
	 */
	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Libera el bloqueo.
	 */
	private static function unlock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Nombre del bloqueo (uno por instalación).
	 */
	private static function lock_name(): string {
		global $wpdb;
		return 'igr_flush_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 );
	}
}
