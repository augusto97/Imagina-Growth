<?php
/**
 * Adaptador de un plugin de formularios.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Cada plugin implementa cuándo está activo y cómo engancharse.
 */
interface FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool;

	/**
	 * Engancha el hook del plugin. El callback llama FormCollector::record().
	 */
	public static function register(): void;
}
