<?php
/**
 * Cliente HTTP firmado hacia el hub.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Support;

use ImaginaGrowth\Connector\Connection\Connection;

/**
 * Envoltura de `wp_remote_request` con firma HMAC y gzip (docs/api-connector.md §2).
 */
final class Http {

	/**
	 * Petición sin firma (solo el canje del código de conexión).
	 *
	 * @param string       $method  Método.
	 * @param string       $url     URL.
	 * @param array<mixed> $payload Cuerpo JSON.
	 * @param int          $timeout Segundos.
	 */
	public static function json( string $method, string $url, array $payload, int $timeout = 15 ): HttpResponse {
		return self::send(
			$method,
			$url,
			Json::encode( $payload ),
			array(
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
			),
			$timeout
		);
	}

	/**
	 * Petición firmada (docs/api-connector.md §2): con la v2 (método, ruta, consulta y nonce) si el hub la anunció y
	 * con la v1 si no. Si el hub rechaza la firma v2 (`401`) y todavía acepta la v1, se reintenta una vez con la v1:
	 * así un hub que volvió a una versión anterior no deja al sitio sin enviar nada.
	 *
	 * @param string      $method  Método.
	 * @param string      $url     URL.
	 * @param string|null $body    Cuerpo JSON ya codificado (null en GET).
	 * @param string      $site_id ID del sitio.
	 * @param string      $secret  Secreto del sitio.
	 * @param bool        $gzip    Comprimir el cuerpo.
	 * @param int         $timeout Segundos.
	 */
	public static function signed( string $method, string $url, ?string $body, string $site_id, string $secret, bool $gzip = false, int $timeout = 15 ): HttpResponse {
		$headers = array(
			'Accept'     => 'application/json',
			'X-IGR-Site' => $site_id,
		);
		$wire    = '';
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
			$wire                    = $body;
			if ( $gzip ) {
				$wire                        = (string) gzencode( $body, 6 );
				$headers['Content-Encoding'] = 'gzip';
			}
		}
		$version                    = Connection::signature_version();
		$headers['X-IGR-Signature'] = Hmac::sign( $version, $secret, $method, $url, $wire );
		$response                   = self::send( $method, $url, null === $body ? null : $wire, $headers, $timeout );
		if ( 2 === $version && 401 === $response->status && Connection::accepts_v1() ) {
			$headers['X-IGR-Signature'] = Hmac::header( $secret, $wire );
			$response                   = self::send( $method, $url, null === $body ? null : $wire, $headers, $timeout );
		}
		return $response;
	}

	/**
	 * Envía la petición.
	 *
	 * @param string                $method  Método.
	 * @param string                $url     URL.
	 * @param string|null           $body    Bytes del cuerpo.
	 * @param array<string, string> $headers Cabeceras.
	 * @param int                   $timeout Segundos.
	 */
	private static function send( string $method, string $url, ?string $body, array $headers, int $timeout ): HttpResponse {
		$headers['User-Agent'] = 'ImaginaGrowthConnector/' . IGR_VERSION . '; ' . ( function_exists( 'home_url' ) ? home_url() : '' );
		$args                  = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'headers'     => $headers,
			'decompress'  => true,
		);
		if ( null !== $body ) {
			$args['body'] = $body;
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return new HttpResponse( 0, '', null, $response->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		return new HttpResponse( $status, $raw, Json::decode( $raw ) );
	}
}
