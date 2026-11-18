<?php
/**
 * Espera entre reintentos del outbox (connector/CLAUDE.md §5).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Outbox;

/**
 * 1 m, 5 m, 30 m, 2 h y luego 12 h; a los 10 intentos el evento pasa a `dead`.
 */
final class Backoff {

	public const MAX_ATTEMPTS = 10;

	private const DELAYS = array( 60, 300, 1800, 7200, 43200 );

	/**
	 * Segundos de espera después del intento número `$attempts` (1 = primer fallo).
	 *
	 * @param int $attempts Intentos fallidos acumulados.
	 */
	public static function delay( int $attempts ): int {
		$index = max( 0, min( $attempts - 1, count( self::DELAYS ) - 1 ) );
		return self::DELAYS[ $index ];
	}

	/**
	 * ¿Se agotaron los intentos?
	 *
	 * @param int $attempts Intentos fallidos acumulados.
	 */
	public static function exhausted( int $attempts ): bool {
		return $attempts >= self::MAX_ATTEMPTS;
	}
}
