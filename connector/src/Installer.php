<?php
/**
 * Activación, desactivación y migraciones de la tabla local.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Outbox\Outbox;

/**
 * La versión del esquema vive en `igr_state` (autocargada) para no consultar en cada petición.
 */
final class Installer {

	/**
	 * Activación.
	 */
	public static function activate(): void {
		self::install();
		if ( false === get_option( Connection::STATE_OPTION ) ) {
			add_option( Connection::STATE_OPTION, array( 'db' => IGR_DB_VERSION ), '', true );
		}
	}

	/**
	 * Desactivación: cancela las tareas (las claves se conservan).
	 */
	public static function deactivate(): void {
		Scheduler::unschedule_all();
	}

	/**
	 * Crea o actualiza la tabla si cambió la versión del esquema y, con una versión nueva del plugin, pide la
	 * configuración del hub enseguida (sin esperar al día siguiente): así el hub conoce la versión y los dos pasan a la
	 * firma v2 en cuanto se actualiza (docs/api-connector.md §2.3). Sin consultas: todo está en `igr_state`.
	 */
	public static function maybe_upgrade(): void {
		$state = Connection::state();
		if ( ( $state['db'] ?? null ) !== IGR_DB_VERSION ) {
			self::install();
		}
		if ( ( $state['pv'] ?? null ) !== IGR_VERSION ) {
			Connection::update_state( array( 'pv' => IGR_VERSION ) );
			if ( Connection::is_connected() ) {
				add_action( 'init', array( self::class, 'after_update' ), 20 );
			}
		}
	}

	/**
	 * Tras actualizar el plugin: renueva la configuración en una tarea (nunca en la petición del visitante).
	 */
	public static function after_update(): void {
		Scheduler::async( Scheduler::CONFIG );
	}

	/**
	 * dbDelta de la tabla del outbox.
	 */
	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( Outbox::schema() );
		Connection::update_state( array( 'db' => IGR_DB_VERSION ) );
	}

	/**
	 * Desinstalación: borra tabla y opciones.
	 */
	public static function uninstall(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . Outbox::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( 'igr_state', 'igr_connection', 'igr_remote_config', 'igr_backfill_state', 'igr_flush_status', 'igr_log', 'igr_versions', 'igr_catalog_sync', 'igr_catalog_hub_hashes', 'igr_debug_until' ) as $option ) {
			delete_option( $option );
		}
	}
}
