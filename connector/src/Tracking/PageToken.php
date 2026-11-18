<?php
/**
 * Tokens firmados de la página (docs/api-connector.md §7.1).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

use ImaginaGrowth\Connector\Support\Json;

/**
 * `token = base64url(JSON) "." hex(HMAC-SHA256(secreto, payload))`.
 * `igrUser` vence en 1 hora y `igrOrder` en 24 horas.
 */
final class PageToken {

	public const USER_TTL  = HOUR_IN_SECONDS;
	public const ORDER_TTL = DAY_IN_SECONDS;

	/**
	 * Firma un payload.
	 *
	 * @param array<string, mixed> $payload Datos (`ref`/`order`, `site`, `exp`).
	 * @param string               $secret  Secreto del sitio.
	 */
	public static function sign( array $payload, string $secret ): string {
		$encoded = rtrim( strtr( base64_encode( Json::encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Formato del contrato.
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, $secret );
	}

	/**
	 * Verifica y devuelve el payload, o null (firma, vencimiento y sitio).
	 *
	 * @param string       $token   Token.
	 * @param list<string> $secrets Secretos válidos.
	 * @param string       $site_id Sitio esperado.
	 * @param int|null     $now     Hora actual.
	 * @return array<string, mixed>|null
	 */
	public static function verify( string $token, array $secrets, string $site_id, ?int $now = null ): ?array {
		if ( 1 !== preg_match( '/^([A-Za-z0-9_-]+)\.([0-9a-f]{64})$/', $token, $m ) ) {
			return null;
		}
		$ok = false;
		foreach ( $secrets as $secret ) {
			$ok = $ok || ( '' !== $secret && hash_equals( hash_hmac( 'sha256', $m[1], $secret ), $m[2] ) );
		}
		$data = $ok ? Json::decode( (string) base64_decode( strtr( $m[1], '-_', '+/' ), true ) ) : null; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Formato del contrato.
		if ( null === $data || ( $data['site'] ?? '' ) !== $site_id || (int) ( $data['exp'] ?? 0 ) < ( $now ?? time() ) ) {
			return null;
		}
		return $data;
	}

	/**
	 * Token de `igrUser`.
	 *
	 * @param string $ref     `wc:77`.
	 * @param string $site_id Sitio.
	 * @param string $secret  Secreto.
	 */
	public static function user( string $ref, string $site_id, string $secret ): string {
		return self::sign(
			array(
				'ref'  => $ref,
				'site' => $site_id,
				'exp'  => time() + self::USER_TTL,
			),
			$secret
		);
	}

	/**
	 * Token de `igrOrder`.
	 *
	 * @param string $order_id Pedido.
	 * @param string $site_id  Sitio.
	 * @param string $secret   Secreto.
	 */
	public static function order( string $order_id, string $site_id, string $secret ): string {
		return self::sign(
			array(
				'order' => $order_id,
				'site'  => $site_id,
				'exp'   => time() + self::ORDER_TTL,
			),
			$secret
		);
	}
}
