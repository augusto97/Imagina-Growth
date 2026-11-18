<?php
/**
 * `site.diagnostics`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Diagnostics;

/**
 * Versiones, capacidades, outbox, Action Scheduler, errores y estado de la importación.
 */
final class SiteDiagnostics implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		return Diagnostics::report();
	}
}
