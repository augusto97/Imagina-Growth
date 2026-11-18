<?php
/**
 * Envoltura de Action Scheduler (con respaldo en WP-Cron si no está disponible).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector;

/**
 * Todas las tareas van en el grupo `imagina-growth`.
 */
final class Scheduler {

	public const GROUP = 'imagina-growth';

	public const FLUSH          = 'igr_flush_outbox';
	public const FLUSH_NOW      = 'igr_flush_now';
	public const HEARTBEAT      = 'igr_heartbeat';
	public const PURGE          = 'igr_outbox_purge';
	public const CONFIG         = 'igr_refresh_config';
	public const RECONCILE      = 'igr_catalog_reconcile';
	public const RECONCILE_STEP = 'igr_catalog_reconcile_step';
	public const BACKFILL       = 'igr_backfill_step';

	/**
	 * Tareas recurrentes y su intervalo en segundos.
	 */
	private const RECURRING = array(
		self::FLUSH     => MINUTE_IN_SECONDS,
		self::HEARTBEAT => HOUR_IN_SECONDS,
		self::PURGE     => DAY_IN_SECONDS,
		self::CONFIG    => DAY_IN_SECONDS,
		self::RECONCILE => DAY_IN_SECONDS,
	);

	/**
	 * ¿Hay Action Scheduler?
	 */
	public static function has_action_scheduler(): bool {
		return function_exists( 'as_schedule_single_action' );
	}

	/**
	 * Programa las tareas recurrentes que falten.
	 */
	public static function ensure_recurring(): void {
		foreach ( self::RECURRING as $hook => $interval ) {
			if ( self::has_action_scheduler() ) {
				if ( false === as_has_scheduled_action( $hook, array(), self::GROUP ) ) {
					$start = self::RECONCILE === $hook ? self::next_night() : time() + 5;
					as_schedule_recurring_action( $start, $interval, $hook, array(), self::GROUP, true );
				}
			} elseif ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time() + 5, self::cron_recurrence( $interval ), $hook );
			}
		}
	}

	/**
	 * Programa una tarea única (sin duplicar).
	 *
	 * @param string       $hook  Acción.
	 * @param array<mixed> $args  Argumentos.
	 * @param int          $delay Segundos desde ahora.
	 */
	public static function single( string $hook, array $args = array(), int $delay = 0 ): void {
		if ( self::has_action_scheduler() ) {
			if ( false === as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				as_schedule_single_action( time() + $delay, $hook, $args, self::GROUP, true );
			}
			return;
		}
		if ( ! wp_next_scheduled( $hook, $args ) ) {
			wp_schedule_single_event( time() + $delay, $hook, $args );
		}
	}

	/**
	 * Programa la continuación de una tarea desde la propia tarea en curso (sin chequeo de
	 * unicidad: Action Scheduler cuenta la acción en ejecución como existente).
	 *
	 * @param string $hook  Acción.
	 * @param int    $delay Segundos desde ahora.
	 */
	public static function next( string $hook, int $delay ): void {
		if ( self::has_action_scheduler() ) {
			as_schedule_single_action( time() + $delay, $hook, array(), self::GROUP, false );
			return;
		}
		wp_schedule_single_event( time() + $delay, $hook );
	}

	/**
	 * Encola una tarea para ejecutarse lo antes posible.
	 *
	 * @param string       $hook Acción.
	 * @param array<mixed> $args Argumentos.
	 */
	public static function async( string $hook, array $args = array() ): void {
		if ( self::has_action_scheduler() ) {
			if ( false === as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
				as_enqueue_async_action( $hook, $args, self::GROUP, true );
			}
			return;
		}
		self::single( $hook, $args, 0 );
	}

	/**
	 * Cancela todas las tareas del plugin.
	 */
	public static function unschedule_all(): void {
		if ( self::has_action_scheduler() ) {
			as_unschedule_all_actions( '', array(), self::GROUP );
		}
		foreach ( array_merge( array_keys( self::RECURRING ), array( self::FLUSH_NOW, self::BACKFILL, self::RECONCILE_STEP ) ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Retraso de la cola: minutos desde la tarea vencida más antigua del grupo.
	 */
	public static function lag_minutes(): int {
		if ( ! self::has_action_scheduler() ) {
			$next = wp_next_scheduled( self::FLUSH );
			return false === $next ? 0 : max( 0, (int) floor( ( time() - $next ) / 60 ) );
		}
		$ids = as_get_scheduled_actions(
			array(
				'group'    => self::GROUP,
				'status'   => \ActionScheduler_Store::STATUS_PENDING,
				'date'     => gmdate( 'Y-m-d H:i:s' ),
				'orderby'  => 'date',
				'order'    => 'ASC',
				'per_page' => 1,
			),
			'ids'
		);
		if ( empty( $ids ) ) {
			return 0;
		}
		$action = \ActionScheduler::store()->fetch_action( (int) reset( $ids ) );
		$date   = $action->get_schedule() instanceof \ActionScheduler_Schedule ? $action->get_schedule()->get_date() : null;
		return null === $date ? 0 : max( 0, (int) floor( ( time() - $date->getTimestamp() ) / 60 ) );
	}

	/**
	 * Registra el intervalo de un minuto para WP-Cron.
	 *
	 * @param array<string, array{interval:int, display:string}> $schedules Intervalos.
	 * @return array<string, array{interval:int, display:string}>
	 */
	public static function cron_schedules( array $schedules ): array {
		$schedules['igr_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => __( 'Cada minuto (Imagina Growth)', 'imagina-growth' ),
		);
		return $schedules;
	}

	/**
	 * Nombre de la recurrencia de WP-Cron para un intervalo.
	 *
	 * @param int $interval Segundos.
	 */
	private static function cron_recurrence( int $interval ): string {
		if ( $interval <= MINUTE_IN_SECONDS ) {
			return 'igr_minute';
		}
		return $interval <= HOUR_IN_SECONDS ? 'hourly' : 'daily';
	}

	/**
	 * Próximas 3:00 a. m. en la zona del sitio.
	 */
	private static function next_night(): int {
		$tz   = wp_timezone();
		$next = new \DateTimeImmutable( 'tomorrow 03:00', $tz );
		return $next->getTimestamp() + wp_rand( 0, 1800 );
	}
}
