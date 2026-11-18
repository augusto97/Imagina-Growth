<?php
/**
 * Registro de errores con datos personales enmascarados.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Guarda los últimos 50 errores (enmascarados) en `igr_log` para Diagnóstico.
 * Con el modo de depuración temporal (24 h) también escribe en el log de PHP.
 */
final class Logger {

	private const OPTION      = 'igr_log';
	private const MAX_ENTRIES = 50;

	/**
	 * Registra un error.
	 *
	 * @param string       $message Mensaje.
	 * @param array<mixed> $context Contexto (se enmascara).
	 */
	public static function error( string $message, array $context = array() ): void {
		self::write( 'error', $message, $context );
	}

	/**
	 * Registra una advertencia.
	 *
	 * @param string       $message Mensaje.
	 * @param array<mixed> $context Contexto (se enmascara).
	 */
	public static function warning( string $message, array $context = array() ): void {
		self::write( 'warning', $message, $context );
	}

	/**
	 * Registra un mensaje de depuración (solo si el modo de depuración está activo).
	 *
	 * @param string       $message Mensaje.
	 * @param array<mixed> $context Contexto (se enmascara).
	 */
	public static function debug( string $message, array $context = array() ): void {
		if ( self::debug_enabled() ) {
			self::to_php_log( 'debug', $message, $context );
		}
	}

	/**
	 * Últimas entradas.
	 *
	 * @return list<array{level:string, message:string, context:array<mixed>, at:string}>
	 */
	public static function recent(): array {
		$log = get_option( self::OPTION, array() );
		return is_array( $log ) ? array_values( $log ) : array();
	}

	/**
	 * Último error, para el heartbeat.
	 */
	public static function last_error(): ?string {
		foreach ( array_reverse( self::recent() ) as $entry ) {
			if ( 'error' === $entry['level'] ) {
				return mb_substr( $entry['message'], 0, 500 );
			}
		}
		return null;
	}

	/**
	 * ¿Está activo el modo de depuración temporal?
	 */
	public static function debug_enabled(): bool {
		$until = (int) get_option( 'igr_debug_until', 0 );
		return $until > time();
	}

	/**
	 * Escribe una entrada.
	 *
	 * @param string       $level   Nivel.
	 * @param string       $message Mensaje.
	 * @param array<mixed> $context Contexto.
	 */
	private static function write( string $level, string $message, array $context ): void {
		$entry = array(
			'level'   => $level,
			'message' => Pii::mask_text( $message ),
			'context' => (array) Pii::mask( $context ),
			'at'      => gmdate( 'c' ),
		);
		$log   = self::recent();
		$log[] = $entry;
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -self::MAX_ENTRIES );
		}
		update_option( self::OPTION, $log, false );
		if ( self::debug_enabled() || ( defined( 'WP_DEBUG' ) && WP_DEBUG && 'error' === $level ) ) {
			self::to_php_log( $level, $message, $context );
		}
	}

	/**
	 * Escribe en el log de PHP, enmascarado.
	 *
	 * @param string       $level   Nivel.
	 * @param string       $message Mensaje.
	 * @param array<mixed> $context Contexto.
	 */
	private static function to_php_log( string $level, string $message, array $context ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[imagina-growth][' . $level . '] ' . Pii::mask_text( $message ) . ( array() === $context ? '' : ' ' . Json::encode( Pii::mask( $context ) ) ) );
	}
}
