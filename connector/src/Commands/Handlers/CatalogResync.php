<?php
/**
 * `catalog.resync`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Catalog\CatalogSync;
use ImaginaGrowth\Connector\Commands\Command;

/**
 * Fuerza la conciliación del catálogo.
 */
final class CatalogResync implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		CatalogSync::queue();
		return array( 'queued' => true );
	}
}
