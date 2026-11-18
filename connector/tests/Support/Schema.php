<?php
/**
 * Validación de eventos contra el contrato compartido (hub/resources/schemas/events).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Support;

use ImaginaGrowth\Connector\Support\Json;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/**
 * Los mismos archivos que usa el hub: si el plugin y el hub no coinciden, falla aquí.
 */
final class Schema {

	public const BASE_URI = 'https://growth.imagina.cloud/schemas/events/';

	/**
	 * Validador compartido.
	 *
	 * @var Validator|null
	 */
	private static ?Validator $validator = null;

	/**
	 * Carpeta de los esquemas.
	 */
	public static function dir(): string {
		return dirname( __DIR__, 3 ) . '/hub/resources/schemas/events';
	}

	/**
	 * Valida un sobre tal como viaja: el JSON exacto (texto del outbox) o el sobre que arma el plugin,
	 * codificado con Json::encode igual que al guardarlo.
	 *
	 * @param array<string, mixed>|string $envelope Sobre o JSON.
	 * @return list<string> Errores (vacío si es válido).
	 */
	public static function errors( array|string $envelope ): array {
		$json     = is_string( $envelope ) ? $envelope : Json::encode( $envelope );
		$wire     = json_decode( $json );
		$envelope = (array) json_decode( $json, true );
		$out      = array();
		$env      = self::validator()->validate( $wire, self::BASE_URI . '_envelope.json' );
		if ( ! $env->isValid() ) {
			$out[] = 'sobre: ' . self::format( $env->error() );
		}
		$name   = (string) ( $envelope['event'] ?? '' );
		$schema = str_starts_with( $name, 'custom.' ) ? 'custom' : $name;
		if ( ! is_readable( self::dir() . '/' . $schema . '.json' ) ) {
			$out[] = 'evento desconocido: ' . $name;
			return $out;
		}
		$props = self::validator()->validate( $wire->properties ?? new \stdClass(), self::BASE_URI . $schema . '.json' );
		if ( ! $props->isValid() ) {
			$out[] = $name . ': ' . self::format( $props->error() );
		}
		if ( strlen( $json ) > 32 * 1024 ) {
			$out[] = 'el evento pasa de 32 KB';
		}
		return $out;
	}

	/**
	 * Validador con el prefijo de los esquemas registrado.
	 */
	private static function validator(): Validator {
		if ( null === self::$validator ) {
			self::$validator = new Validator();
			self::$validator->setMaxErrors( 5 );
			self::$validator->resolver()->registerPrefix( self::BASE_URI, self::dir() . '/' );
		}
		return self::$validator;
	}

	/**
	 * Mensaje legible.
	 *
	 * @param mixed $error Error de opis.
	 */
	private static function format( mixed $error ): string {
		if ( null === $error ) {
			return 'inválido';
		}
		$parts = array();
		foreach ( ( new ErrorFormatter() )->format( $error, true ) as $path => $messages ) {
			$parts[] = $path . ' ' . implode( '; ', (array) $messages );
		}
		return implode( ' | ', $parts );
	}
}
