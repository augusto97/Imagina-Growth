<?php
/**
 * Comandos WP-CLI: `wp igr …`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Cli;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Catalog\CatalogSync;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\ConnectionManager;
use ImaginaGrowth\Connector\Diagnostics;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Json;
use WP_CLI;

/**
 * Administra la conexión con Imagina Growth.
 */
final class Command {

	/**
	 * Estado de la conexión, el outbox y la importación.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : `table` o `json`.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function status( array $args, array $assoc_args ): void {
		$report = Diagnostics::report();
		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}
		WP_CLI::line( 'Conexión:   ' . ( Connection::is_connected() ? 'conectado (' . Connection::site_id() . ')' : 'sin conectar' ) );
		$ws = $report['connection']['workspace'];
		if ( is_array( $ws ) ) {
			WP_CLI::line( 'Espacio:    ' . ( $ws['name'] ?? '' ) . ' (' . ( $ws['id'] ?? '' ) . ')' );
		}
		$o = $report['outbox'];
		WP_CLI::line( sprintf( 'Outbox:     %d pendientes · %d enviando · %d enviados · %d fallidos', $o['pending'], $o['sending'], $o['sent'], $o['dead'] ) );
		WP_CLI::line( 'Retraso AS: ' . $report['action_scheduler']['lag_minutes'] . ' min' );
		$b = $report['backfill'];
		if ( is_array( $b ) ) {
			WP_CLI::line( sprintf( 'Backfill:   %s · fase %s · desde %s', $b['status'] ?? '?', $b['phase'] ?? '?', $b['from'] ?? '?' ) );
			foreach ( (array) ( $b['entities'] ?? array() ) as $entity => $p ) {
				WP_CLI::line( sprintf( '  %-14s %6d / %d', $entity, (int) $p['sent'], (int) $p['total'] ) );
			}
		}
	}

	/**
	 * Envía ahora el outbox.
	 *
	 * ## OPTIONS
	 *
	 * [--all]
	 * : Repetir hasta vaciar los pendientes que ya vencieron.
	 *
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function flush( array $args, array $assoc_args ): void {
		if ( ! Connection::is_connected() ) {
			WP_CLI::error( 'El sitio no está conectado.' );
		}
		$total = 0;
		do {
			$stats  = Flusher::run( 60 );
			$total += $stats['sent'];
			WP_CLI::line( sprintf( 'Lotes %d · enviados %d · fallidos %d · reintentos %d%s', $stats['batches'], $stats['sent'], $stats['dead'], $stats['retried'], $stats['error'] ? ' · error: ' . $stats['error'] : '' ) );
		} while ( isset( $assoc_args['all'] ) && null === $stats['error'] && $stats['batches'] > 0 );
		WP_CLI::success( sprintf( '%d eventos enviados.', $total ) );
	}

	/**
	 * Importa el historial (productos, categorías, clientes, pedidos, reembolsos, reseñas).
	 *
	 * ## OPTIONS
	 *
	 * [--from=<date>]
	 * : Fecha inicial YYYY-MM-DD (por defecto, hoy − 24 meses).
	 *
	 * [--batch=<n>]
	 * : Tamaño del lote.
	 * ---
	 * default: 200
	 * ---
	 *
	 * [--delay=<s>]
	 * : Segundos entre lotes (en segundo plano).
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--now]
	 * : Ejecutar todos los lotes en este proceso, sin Action Scheduler.
	 *
	 * [--flush]
	 * : Con --now, enviar al hub después de cada lote.
	 *
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function backfill( array $args, array $assoc_args ): void {
		if ( ! Connection::is_connected() ) {
			WP_CLI::error( 'El sitio no está conectado.' );
		}
		$from = $assoc_args['from'] ?? null;
		Runner::start(
			$from,
			true,
			array(
				'batch_size' => (int) ( $assoc_args['batch'] ?? 200 ),
				'delay'      => (int) ( $assoc_args['delay'] ?? 10 ),
			)
		);
		$state = Runner::state();
		WP_CLI::line( sprintf( 'Importación desde %s: %d pedidos, %d clientes, %d productos.', $state['from'], $state['entities']['orders']['total'], $state['entities']['customers']['total'], $state['entities']['products']['total'] ) );
		if ( ! isset( $assoc_args['now'] ) ) {
			WP_CLI::success( 'Importación en cola (Action Scheduler).' );
			return;
		}
		$start = microtime( true );
		do {
			$t     = microtime( true );
			$state = Runner::step( false );
			WP_CLI::line( sprintf( '  %-13s lote en %.2fs · %s', $state['phase'], microtime( true ) - $t, self::progress( $state ) ) );
			if ( isset( $assoc_args['flush'] ) ) {
				Flusher::run( 60 );
			}
		} while ( in_array( $state['status'], array( 'queued', 'running' ), true ) );
		Runner::report( $state );
		if ( 'completed' !== $state['status'] ) {
			WP_CLI::error( 'La importación terminó con estado ' . $state['status'] . ': ' . ( $state['error'] ?? '' ) );
		}
		if ( ! empty( $state['warning'] ) ) {
			WP_CLI::warning( (string) $state['warning'] );
		}
		WP_CLI::success( sprintf( 'Importación completa en %.1fs.', microtime( true ) - $start ) );
	}

	/**
	 * Concilia el catálogo con el hub.
	 *
	 * ## OPTIONS
	 *
	 * [--now]
	 * : Ejecutar en este proceso.
	 *
	 * @subcommand resync-catalog
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function resync_catalog( array $args, array $assoc_args ): void {
		CatalogSync::queue();
		if ( isset( $assoc_args['now'] ) ) {
			do {
				CatalogSync::run( 60 );
				$state = get_option( CatalogSync::STATE_OPTION );
			} while ( is_array( $state ) && ! in_array( $state['phase'], array( 'done', 'error' ), true ) );
			WP_CLI::success( 'Conciliación: ' . Json::encode( $state ) );
			return;
		}
		WP_CLI::success( 'Conciliación en cola.' );
	}

	/**
	 * Registra un evento de prueba (`custom.igr_test`).
	 *
	 * ## OPTIONS
	 *
	 * [--flush]
	 * : Enviarlo enseguida.
	 *
	 * @subcommand test-event
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function test_event( array $args, array $assoc_args ): void {
		$factory = EventFactory::current();
		if ( null === $factory ) {
			WP_CLI::error( 'El sitio no está conectado.' );
		}
		$envelope = $factory->make( 'custom.igr_test', array( 'source' => 'wp-cli' ) );
		Recorder::record( $envelope );
		WP_CLI::line( 'event_id: ' . $envelope['event_id'] );
		if ( isset( $assoc_args['flush'] ) ) {
			$this->flush( array(), array() );
		}
	}

	/**
	 * Conecta con un código manual (útil para pruebas de punta a punta).
	 *
	 * ## OPTIONS
	 *
	 * <code>
	 * : Código IGR-XXXX-XXXX-XXXX.
	 *
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function connect( array $args, array $assoc_args ): void {
		$result = ConnectionManager::exchange( (string) $args[0] );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}
		WP_CLI::success( 'Conectado: ' . Connection::site_id() . '. La importación histórica quedó en cola.' );
	}

	/**
	 * Desconecta el sitio.
	 *
	 * ## OPTIONS
	 *
	 * [--wipe]
	 * : Borrar también el historial local del outbox.
	 *
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function disconnect( array $args, array $assoc_args ): void {
		ConnectionManager::disconnect( isset( $assoc_args['wipe'] ) );
		WP_CLI::success( 'Desconectado.' );
	}

	/**
	 * Crea una tienda colombiana de prueba (solo con WP_ENVIRONMENT_TYPE=local).
	 *
	 * ## OPTIONS
	 *
	 * [--customers=<n>]
	 * : Clientes (registrados e invitados).
	 * ---
	 * default: 2000
	 * ---
	 *
	 * [--orders=<n>]
	 * : Pedidos.
	 * ---
	 * default: 10000
	 * ---
	 *
	 * [--months=<n>]
	 * : Meses hacia atrás.
	 * ---
	 * default: 24
	 * ---
	 *
	 * [--seed=<n>]
	 * : Semilla para resultados repetibles.
	 * ---
	 * default: 1581
	 * ---
	 *
	 * @subcommand demo-store
	 * @param list<string>          $args       Argumentos.
	 * @param array<string, string> $assoc_args Opciones.
	 */
	public function demo_store( array $args, array $assoc_args ): void {
		if ( 'local' !== wp_get_environment_type() ) {
			WP_CLI::error( 'demo-store solo funciona con WP_ENVIRONMENT_TYPE=local.' );
		}
		if ( ! class_exists( 'WooCommerce' ) ) {
			WP_CLI::error( 'WooCommerce no está activo.' );
		}
		$generator = new DemoStore(
			(int) ( $assoc_args['seed'] ?? 1581 ),
			static function ( string $message ): void {
				WP_CLI::line( $message );
			}
		);
		$start     = microtime( true );
		$result    = $generator->run( (int) ( $assoc_args['customers'] ?? 2000 ), (int) ( $assoc_args['orders'] ?? 10000 ), (int) ( $assoc_args['months'] ?? 24 ) );
		WP_CLI::success( sprintf( 'Tienda creada en %.1fs: %s', microtime( true ) - $start, Json::encode( $result ) ) );
	}

	/**
	 * Texto de progreso.
	 *
	 * @param array<string, mixed> $state Estado.
	 */
	private static function progress( array $state ): string {
		$parts = array();
		foreach ( (array) ( $state['entities'] ?? array() ) as $entity => $p ) {
			if ( (int) $p['total'] > 0 ) {
				$parts[] = $entity . ' ' . (int) $p['sent'] . '/' . (int) $p['total'];
			}
		}
		return implode( ' · ', $parts ) . ' · outbox ' . Outbox::stats()['pending'];
	}
}
