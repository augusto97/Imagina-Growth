<?php
/**
 * Renovación de la cookie `_igr_aid` desde el servidor (connector/CLAUDE.md §9, eventos.md §6.1).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

/**
 * `/?igr_ping=1`: Safari limita a 7 días las cookies creadas con JS; una cookie
 * que pone el propio sitio por HTTP dura lo que dice. Se atiende en
 * `plugins_loaded` con prioridad 0 y nunca se guarda en caché.
 */
final class Ping {

	/**
	 * Registra el hook.
	 */
	public static function register(): void {
		add_action( 'plugins_loaded', array( self::class, 'maybe_handle' ), 0 );
	}

	/**
	 * Responde el ping y termina la petición.
	 */
	public static function maybe_handle(): void {
		if ( ! isset( $_GET['igr_ping'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Ping público e idempotente.
			return;
		}
		$res = self::response( $_COOKIE, (string) ( TrackerConfig::get()['cd'] ?? '' ), is_ssl() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- Se valida el formato en response().
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante estándar de los plugins de caché.
		}
		do_action( 'litespeed_control_set_nocache', 'imagina-growth ping' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook de LiteSpeed Cache.
		if ( ! headers_sent() ) {
			foreach ( $res['headers'] as $name => $value ) {
				header( $name . ': ' . $value );
			}
			if ( null !== $res['cookie'] ) {
				setcookie( '_igr_aid', $res['cookie']['value'], $res['cookie']['options'] );
			}
		}
		status_header( 204 );
		exit;
	}

	/**
	 * Respuesta del ping, sin efectos (se prueba aparte).
	 *
	 * @param array<string, mixed> $cookies Cookies de la petición.
	 * @param string               $domain  Dominio de la cookie.
	 * @param bool                 $secure  HTTPS.
	 * @return array{headers: array<string, string>, cookie: array{value: string, options: array<string, mixed>}|null}
	 */
	public static function response( array $cookies, string $domain, bool $secure ): array {
		$aid    = $cookies['_igr_aid'] ?? null;
		$cookie = null;
		if ( is_string( $aid ) && 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/', $aid ) ) {
			$cookie = array(
				'value'   => $aid,
				'options' => array(
					'expires'  => time() + 395 * DAY_IN_SECONDS,
					'path'     => '/',
					'domain'   => $domain,
					'secure'   => $secure,
					'httponly' => false, // El tracker la lee.
					'samesite' => 'Lax',
				),
			);
		}
		return array(
			'headers' => array(
				'Cache-Control'             => 'no-store, no-cache, must-revalidate, max-age=0, private',
				'Pragma'                    => 'no-cache',
				'X-LiteSpeed-Cache-Control' => 'no-cache',
				'CDN-Cache-Control'         => 'no-store',
				'X-Robots-Tag'              => 'noindex',
			),
			'cookie'  => $cookie,
		);
	}
}
