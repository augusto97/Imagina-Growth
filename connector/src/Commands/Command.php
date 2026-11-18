<?php
/**
 * Interfaz de los comandos del hub.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands;

/**
 * Cada comando valida sus argumentos y devuelve datos JSON.
 */
interface Command {

	/**
	 * Ejecuta el comando.
	 *
	 * @param array<string, mixed> $args Argumentos.
	 * @return array<string, mixed> Datos de la respuesta.
	 * @throws CommandError Si los argumentos no son válidos o el comando falla.
	 */
	public function handle( array $args ): array;
}
