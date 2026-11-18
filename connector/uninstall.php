<?php
/**
 * Desinstalación: borra la tabla y las opciones del plugin.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/src/Outbox/Outbox.php';
require_once __DIR__ . '/src/Connection/Connection.php';
require_once __DIR__ . '/src/Scheduler.php';
require_once __DIR__ . '/src/Installer.php';

\ImaginaGrowth\Connector\Installer::uninstall();
