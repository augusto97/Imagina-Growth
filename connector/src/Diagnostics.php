<?php
/**
 * Informe de diagnóstico (comando `site.diagnostics`, pestaña Diagnóstico y `wp igr status`).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Connection\Capabilities;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Sin datos personales: los errores ya vienen enmascarados.
 */
final class Diagnostics {

	/**
	 * Informe completo.
	 *
	 * @return array<string, mixed>
	 */
	public static function report(): array {
		$connection = Connection::data();
		$backfill   = Runner::state();
		unset( $backfill['counted'] );
		return array(
			'versions'         => array(
				'plugin' => IGR_VERSION,
				'wp'     => (string) get_bloginfo( 'version' ),
				'wc'     => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
				'php'    => PHP_VERSION,
			),
			'connection'       => array(
				'site_id'      => Connection::site_id(),
				'workspace'    => $connection['workspace'] ?? null,
				'connected_at' => isset( $connection['connected_at'] ) ? gmdate( 'c', (int) $connection['connected_at'] ) : null,
				'rotated_at'   => isset( $connection['rotated_at'] ) ? gmdate( 'c', (int) $connection['rotated_at'] ) : null,
			),
			'capabilities'     => Capabilities::detect(),
			'outbox'           => Outbox::stats(),
			'flush'            => get_option( Flusher::STATUS_OPTION, null ),
			'action_scheduler' => array(
				'available'   => Scheduler::has_action_scheduler(),
				'lag_minutes' => Scheduler::lag_minutes(),
				'wp_cron'     => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			),
			'backfill'         => array() === $backfill ? null : $backfill,
			'recent_errors'    => array_slice( Logger::recent(), -10 ),
		);
	}
}
