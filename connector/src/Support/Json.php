<?php
/**
 * JSON del contrato.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

/**
 * Codificación estable y segura (UTF-8 inválido se reemplaza, nunca falla en silencio).
 */
final class Json {

	public const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION;

	/**
	 * Codifica a JSON.
	 *
	 * @param mixed $value Valor.
	 * @throws \JsonException Si no se puede codificar.
	 */
	public static function encode( mixed $value ): string {
		return json_encode( $value, self::FLAGS | JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- wp_json_encode() no lanza excepciones ni acepta JSON_THROW_ON_ERROR.
	}

	/**
	 * Decodifica a arreglo asociativo; null si no es JSON de objeto o arreglo.
	 *
	 * @param string $json Texto.
	 * @return array<mixed>|null
	 */
	public static function decode( string $json ): ?array {
		if ( '' === $json ) {
			return null;
		}
		$value = json_decode( $json, true );
		return is_array( $value ) ? $value : null;
	}

	/**
	 * Convierte arreglos vacíos que deben ser objetos JSON (`{}`) en stdClass.
	 *
	 * @param array<mixed> $value Arreglo asociativo.
	 * @return array<mixed>|\stdClass
	 */
	public static function obj( array $value ): array|\stdClass {
		return array() === $value ? new \stdClass() : $value;
	}
}
