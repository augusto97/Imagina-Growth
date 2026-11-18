<?php
/**
 * Validación de los argumentos de un comando.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Los mensajes van en la respuesta JSON del comando, no en HTML.

/**
 * Lee y valida `args`. Cualquier valor inválido lanza CommandError `invalid_args` (422).
 */
final class Args {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function __construct( private readonly array $args ) {}

	/**
	 * ¿Viene el argumento (y no es null)?
	 *
	 * @param string $key Clave.
	 */
	public function has( string $key ): bool {
		return isset( $this->args[ $key ] );
	}

	/**
	 * Texto.
	 *
	 * @param string      $key      Clave.
	 * @param bool        $required Obligatorio.
	 * @param int         $max      Largo máximo.
	 * @param string|null $pattern  Expresión regular que debe cumplir.
	 * @param int         $min      Largo mínimo.
	 */
	public function string( string $key, bool $required = false, int $max = 200, ?string $pattern = null, int $min = 1 ): ?string {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			if ( $required ) {
				throw self::invalid( $key, 'es obligatorio' );
			}
			return null;
		}
		if ( is_int( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) ) {
			throw self::invalid( $key, 'debe ser texto' );
		}
		$value = trim( $value );
		$len   = mb_strlen( $value );
		if ( $len < $min || $len > $max ) {
			throw self::invalid( $key, sprintf( 'debe tener entre %d y %d caracteres', $min, $max ) );
		}
		if ( null !== $pattern && 1 !== preg_match( $pattern, $value ) ) {
			throw self::invalid( $key, 'tiene un formato inválido' );
		}
		return $value;
	}

	/**
	 * Monto con hasta 2 decimales (`"10.00"`), no negativo.
	 *
	 * @param string $key      Clave.
	 * @param bool   $required Obligatorio.
	 */
	public function money( string $key, bool $required = false ): ?string {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			if ( $required ) {
				throw self::invalid( $key, 'es obligatorio' );
			}
			return null;
		}
		if ( is_int( $value ) || is_float( $value ) ) {
			$value = (string) $value;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{1,12}(\.\d{1,2})?$/', trim( $value ) ) ) {
			throw self::invalid( $key, 'debe ser un monto como "10.00"' );
		}
		return trim( $value );
	}

	/**
	 * Booleano.
	 *
	 * @param string $key     Clave.
	 * @param bool   $fallback Valor por defecto.
	 */
	public function bool( string $key, bool $fallback = false ): bool {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			return $fallback;
		}
		if ( ! is_bool( $value ) ) {
			throw self::invalid( $key, 'debe ser true o false' );
		}
		return $value;
	}

	/**
	 * Entero en un rango.
	 *
	 * @param string $key     Clave.
	 * @param int    $fallback Valor por defecto.
	 * @param int    $min     Mínimo.
	 * @param int    $max     Máximo.
	 */
	public function int( string $key, int $fallback, int $min, int $max ): int {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			return $fallback;
		}
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			throw self::invalid( $key, sprintf( 'debe ser un entero entre %d y %d', $min, $max ) );
		}
		return $value;
	}

	/**
	 * Lista de IDs numéricos (`["88", "91"]`).
	 *
	 * @param string $key Clave.
	 * @param int    $max Máximo de elementos.
	 * @return list<int>
	 */
	public function ids( string $key, int $max = 200 ): array {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || count( $value ) > $max ) {
			throw self::invalid( $key, sprintf( 'debe ser una lista de hasta %d IDs', $max ) );
		}
		$out = array();
		foreach ( $value as $id ) {
			if ( ( ! is_string( $id ) && ! is_int( $id ) ) || 1 !== preg_match( '/^\d{1,20}$/', (string) $id ) || (int) $id <= 0 ) {
				throw self::invalid( $key, 'tiene un ID inválido' );
			}
			$out[] = (int) $id;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Lista de correos válidos, en minúsculas.
	 *
	 * @param string $key Clave.
	 * @param int    $max Máximo de elementos.
	 * @return list<string>
	 */
	public function emails( string $key, int $max = 10 ): array {
		$value = $this->args[ $key ] ?? null;
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || count( $value ) > $max ) {
			throw self::invalid( $key, sprintf( 'debe ser una lista de hasta %d correos', $max ) );
		}
		$out = array();
		foreach ( $value as $email ) {
			$email = is_string( $email ) ? strtolower( trim( $email ) ) : '';
			if ( '' === $email || ! is_email( $email ) ) {
				throw self::invalid( $key, 'tiene un correo inválido' );
			}
			$out[] = $email;
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Error de validación.
	 *
	 * @param string $key    Argumento.
	 * @param string $reason Motivo.
	 */
	public static function invalid( string $key, string $reason ): CommandError {
		return new CommandError( 'invalid_args', sprintf( 'El argumento %s %s', $key, $reason ) );
	}
}
