<?php
/**
 * Punto único para registrar eventos en el outbox.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Events;

use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Guarda el sobre en el outbox (un INSERT). Nunca hace HTTP.
 */
final class Recorder {

	/**
	 * Cuando es mayor que cero, no se registra nada (p. ej. durante `wp igr demo-store`).
	 *
	 * @var int
	 */
	private static int $suppressed = 0;

	/**
	 * Registra un evento. Los errores nunca rompen la petición del sitio.
	 *
	 * @param array<string, mixed> $envelope Sobre.
	 * @param int                  $delay    Segundos antes de que pueda salir (debounce de la captura temprana).
	 */
	public static function record( array $envelope, int $delay = 0 ): bool {
		if ( self::$suppressed > 0 ) {
			return false;
		}
		/**
		 * Permite modificar o descartar (devolviendo null) un evento antes de guardarlo.
		 *
		 * @param array<string, mixed>|null $envelope Sobre.
		 */
		$envelope = apply_filters( 'igr_event', $envelope );
		if ( ! is_array( $envelope ) ) {
			return false;
		}
		try {
			return Outbox::insert( $envelope, $delay );
		} catch ( \Throwable $e ) {
			Logger::error( 'No se pudo guardar el evento en el outbox: ' . $e->getMessage(), array( 'event' => $envelope['event'] ?? '' ) );
			return false;
		}
	}

	/**
	 * Ejecuta una función sin registrar eventos.
	 *
	 * @template T
	 * @param callable():T $callback Función.
	 * @return T
	 */
	public static function suppress( callable $callback ): mixed {
		++self::$suppressed;
		try {
			return $callback();
		} finally {
			--self::$suppressed;
		}
	}

	/**
	 * ¿Está suprimido el registro?
	 */
	public static function is_suppressed(): bool {
		return self::$suppressed > 0;
	}
}
