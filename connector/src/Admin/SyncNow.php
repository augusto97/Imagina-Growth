<?php
/**
 * «Importar y enviar ahora»: la importación y el envío avanzan desde la página del administrador.
 *
 * Normalmente todo corre en Action Scheduler (connector/CLAUDE.md §5 y §7). Si WP-Cron no corre (sitios con poco
 * tráfico o `DISABLE_WP_CRON` sin un cron real), nada avanza: este botón hace el mismo trabajo en tramos de
 * ~15 s por petición AJAX del administrador, que la página repite hasta terminar. Nunca corre en la petición de
 * un visitante.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Admin;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;

/**
 * Acción AJAX `igr_sync_now`.
 */
final class SyncNow {

	public const ACTION = 'igr_sync_now';

	/** Segundos de trabajo por petición (debajo de los 30 s que suelen permitir los hostings). */
	public const BUDGET = 15;

	/** Error del envío cuando otro envío (el de Action Scheduler) ya está en curso: no es un fallo, se reintenta. */
	private const BUSY = 'otro envío en curso';

	/**
	 * Registra la acción.
	 */
	public static function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/**
	 * Atiende la petición del botón.
	 */
	public static function handle(): void {
		if ( ! current_user_can( AdminPage::CAPABILITY ) ) {
			wp_send_json_error( array( 'message' => __( 'No tienes permiso para hacer esto.', 'imagina-growth' ) ), 403 );
		}
		check_ajax_referer( self::ACTION, 'nonce' );
		if ( ! Connection::is_connected() ) {
			wp_send_json_error( array( 'message' => __( 'El sitio no está conectado a Imagina Growth.', 'imagina-growth' ) ), 409 );
		}
		$first = ! empty( $_POST['first'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- check_ajax_referer() arriba.
		wp_send_json_success( self::run( $first ) );
	}

	/**
	 * Un tramo de trabajo: lotes de la importación y envío de lo pendiente.
	 *
	 * @param bool $first  Primera petición: arranca la importación si nunca corrió (o falló) y adelanta los reintentos.
	 * @param int  $budget Segundos de trabajo.
	 * @return array{status:string, entities:list<array{key:string, sent:int, total:int}>, sent:int, pending:int, dead:int, busy:bool, error:?string, done:bool}
	 */
	public static function run( bool $first, int $budget = self::BUDGET ): array {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( $budget + 30 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- tramo acotado del administrador.
		}
		$deadline = microtime( true ) + $budget;
		if ( $first ) {
			$state  = Runner::state();
			$status = (string) ( $state['status'] ?? '' );
			if ( array() === $state || 'failed' === $status ) {
				Runner::start( null, true );
			} elseif ( 'paused' === $status ) {
				Runner::pause( false );
			}
			Outbox::expedite();
		}

		$state = Runner::state();
		// Al menos un lote por tramo, y se deja un tercio del tiempo para enviar lo que se importó.
		$reserve = max( 1, intdiv( $budget, 3 ) );
		$steps   = 0;
		while ( self::importing( $state ) && ( 0 === $steps || microtime( true ) < $deadline - $reserve ) ) {
			$state = Runner::step( false );
			++$steps;
		}
		$flush = Flusher::run( max( 3, (int) floor( $deadline - microtime( true ) ) ) );
		$stats = Outbox::stats();

		$busy    = self::BUSY === $flush['error'];
		$error   = $busy ? null : $flush['error'];
		$pending = $stats['pending'] + $stats['sending'];
		if ( null === $error && 'failed' === ( $state['status'] ?? '' ) ) {
			$error = (string) ( $state['error'] ?? __( 'La importación falló.', 'imagina-growth' ) );
		}

		$entities = array();
		foreach ( (array) ( $state['entities'] ?? array() ) as $key => $progress ) {
			$entities[] = array(
				'key'   => (string) $key,
				'sent'  => (int) ( $progress['sent'] ?? 0 ),
				'total' => (int) ( $progress['total'] ?? 0 ),
			);
		}

		return array(
			'status'   => (string) ( $state['status'] ?? 'none' ),
			'entities' => $entities,
			'sent'     => $flush['sent'],
			'pending'  => $pending,
			'dead'     => $stats['dead'],
			'busy'     => $busy,
			'error'    => $error,
			'done'     => ! self::importing( $state ) && 0 === $pending,
		);
	}

	/**
	 * ¿La importación sigue en curso?
	 *
	 * @param array<string, mixed> $state Estado de la importación.
	 */
	private static function importing( array $state ): bool {
		return in_array( $state['status'] ?? '', array( 'queued', 'running' ), true );
	}
}
