<?php
/**
 * `secret.rotate`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;
use ImaginaGrowth\Connector\Connection\Connection;

/**
 * Guarda el secreto nuevo; el anterior vale 24 h más.
 */
final class SecretRotate implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 * @throws CommandError Si el secreto no tiene el formato esperado.
	 */
	public function handle( array $args ): array {
		$secret = isset( $args['secret'] ) ? (string) $args['secret'] : '';
		if ( 1 !== preg_match( '/^sk_[A-Za-z0-9_-]{32,128}$/', $secret ) ) {
			throw new CommandError( 'invalid_args', 'secret no tiene el formato sk_…' );
		}
		Connection::rotate( $secret );
		return array( 'rotated' => true );
	}
}
