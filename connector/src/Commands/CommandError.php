<?php
/**
 * Error controlado de un comando.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands;

/**
 * Se traduce a `{"ok": false, "error": {"code", "message"}}`.
 */
final class CommandError extends \RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param string $error_code Código.
	 * @param string $message    Mensaje.
	 * @param int    $status     Código HTTP.
	 */
	public function __construct( public readonly string $error_code, string $message, public readonly int $status = 422 ) {
		parent::__construct( $message );
	}
}
