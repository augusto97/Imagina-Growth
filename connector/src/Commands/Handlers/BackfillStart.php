<?php
/**
 * `backfill.start`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Inicia o reinicia la importación histórica.
 */
final class BackfillStart implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 * @throws CommandError Si la fecha no es válida.
	 */
	public function handle( array $args ): array {
		$from = isset( $args['from'] ) ? (string) $args['from'] : null;
		if ( null !== $from && ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || false === strtotime( $from ) ) ) {
			throw new CommandError( 'invalid_args', 'from debe ser una fecha YYYY-MM-DD' );
		}
		$started = Runner::start( $from, (bool) ( $args['restart'] ?? false ) );
		return array(
			'queued' => $started,
			'state'  => $started ? 'queued' : ( Runner::state()['status'] ?? null ),
		);
	}
}
