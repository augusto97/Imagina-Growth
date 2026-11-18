<?php
/**
 * Contexto de navegación en los eventos de servidor (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

use ImaginaGrowth\Connector\Support\Dates;

/**
 * Lee las cookies `_igr_aid`, `_igr_sid`, `_igr_src`, `_fbp`, `_fbc`, `_ga` y `_ttp`
 * y arma `identity` y `context` del sobre. Valida el formato de cada valor.
 */
final class RequestContext {

	private const UTM_KEYS   = array( 'source', 'medium', 'campaign', 'content', 'term', 'id' );
	private const CLICK_KEYS = array( 'fbclid', 'gclid', 'gbraid', 'wbraid', 'ttclid', 'msclkid' );

	/**
	 * Contexto de la petición actual.
	 *
	 * @param array<string, mixed>|null $cookies Cookies (por defecto `$_COOKIE`).
	 * @return array{identity: array<string, string>, context: array<string, mixed>}
	 */
	public static function capture( ?array $cookies = null ): array {
		$cookies  = $cookies ?? $_COOKIE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$identity = array();
		$context  = array();

		$aid = self::cookie( $cookies, '_igr_aid' );
		if ( null !== $aid && 1 === preg_match( '/^[A-Za-z0-9-]{8,64}$/', $aid ) ) {
			$identity['anonymous_id'] = $aid;
		}
		$sid = self::cookie( $cookies, '_igr_sid' );
		if ( null !== $sid && 1 === preg_match( '/^[A-Za-z0-9_.-]{4,64}$/', $sid ) ) {
			$identity['session_id'] = $sid;
		}

		$consent            = self::consent( $cookies );
		$context['consent'] = $consent;

		$src = self::source( self::cookie( $cookies, '_igr_src' ) );
		if ( null !== $src['first'] ) {
			$context['first_touch'] = $src['first'];
		}
		if ( null !== $src['last'] ) {
			$context['last_touch'] = $src['last'];
			if ( ! empty( $src['last']['utm'] ) ) {
				$context['utm'] = $src['last']['utm'];
			}
			if ( ! empty( $src['last']['click_ids'] ) ) {
				$context['click_ids'] = $src['last']['click_ids'];
			}
		}

		// Sin consentimiento de marketing no se leen las cookies de anuncios (tracker/CLAUDE.md §7).
		if ( $consent['marketing'] ) {
			$ads = array();
			$fbp = self::cookie( $cookies, '_fbp' );
			if ( null !== $fbp && 1 === preg_match( '/^fb\.\d\.\d+\.[\w-]+$/', $fbp ) ) {
				$ads['fbp'] = $fbp;
			}
			$fbc = self::cookie( $cookies, '_fbc' );
			if ( null !== $fbc && 1 === preg_match( '/^fb\.\d\.\d+\.[\w-]+$/', $fbc ) ) {
				$ads['fbc'] = $fbc;
			}
			$ga = self::cookie( $cookies, '_ga' );
			if ( null !== $ga && 1 === preg_match( '/^GA\d\.\d+\.(\d+\.\d+)$/', $ga, $m ) ) {
				$ads['ga_client_id'] = $m[1];
			}
			$ttp = self::cookie( $cookies, '_ttp' );
			if ( null !== $ttp && 1 === preg_match( '/^[\w.-]{6,128}$/', $ttp ) ) {
				$ads['ttp'] = $ttp;
			}
			if ( array() !== $ads ) {
				$context['ad_cookies'] = $ads;
			}
		}

		return array(
			'identity' => $identity,
			'context'  => $context,
		);
	}

	/**
	 * Consentimiento del visitante: WP Consent API, banners conocidos, el banner
	 * propio o el valor por defecto conservador (ConsentState).
	 *
	 * @param array<string, mixed>|null $cookies Cookies.
	 * @return array{analytics:bool, marketing:bool, source:string}
	 */
	public static function consent( ?array $cookies = null ): array {
		return \ImaginaGrowth\Connector\Consent\ConsentState::from_request( $cookies );
	}

	/**
	 * Lee `_igr_src`: JSON (codificado como URL) `{"f": toque, "l": toque}`.
	 * También acepta `first` y `last`.
	 *
	 * @param string|null $raw Valor de la cookie.
	 * @return array{first: array<string, mixed>|null, last: array<string, mixed>|null}
	 */
	public static function source( ?string $raw ): array {
		$out = array(
			'first' => null,
			'last'  => null,
		);
		if ( null === $raw || '' === $raw || strlen( $raw ) > 4096 ) {
			return $out;
		}
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = json_decode( rawurldecode( $raw ), true );
		}
		if ( ! is_array( $data ) ) {
			return $out;
		}
		$out['first'] = self::touch( $data['f'] ?? $data['first'] ?? null );
		$out['last']  = self::touch( $data['l'] ?? $data['last'] ?? null );
		return $out;
	}

	/**
	 * Sanea un toque al formato `touch` de `_defs.json`.
	 *
	 * @param mixed $touch Datos.
	 * @return array<string, mixed>|null
	 */
	public static function touch( mixed $touch ): ?array {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$out = array();
		$utm = array();
		foreach ( self::UTM_KEYS as $k ) {
			$v = $touch['utm'][ $k ] ?? null;
			if ( is_string( $v ) && '' !== $v ) {
				$utm[ $k ] = mb_substr( sanitize_text_field( $v ), 0, 500 );
			}
		}
		if ( array() !== $utm ) {
			$out['utm'] = $utm;
		}
		$clicks = array();
		foreach ( self::CLICK_KEYS as $k ) {
			$v = $touch['click_ids'][ $k ] ?? null;
			if ( is_string( $v ) && 1 === preg_match( '/^[\w.\-~]{1,500}$/', $v ) ) {
				$clicks[ $k ] = $v;
			}
		}
		if ( array() !== $clicks ) {
			$out['click_ids'] = $clicks;
		}
		foreach ( array( 'referrer', 'landing' ) as $k ) {
			$v = $touch[ $k ] ?? null;
			if ( is_string( $v ) && '' !== $v ) {
				$out[ $k ] = mb_substr( esc_url_raw( $v ), 0, 2048 );
			}
		}
		if ( isset( $touch['at'] ) && ( is_string( $touch['at'] ) || is_int( $touch['at'] ) ) ) {
			$at = is_int( $touch['at'] ) ? Dates::iso( (int) floor( $touch['at'] / ( $touch['at'] > 9999999999 ? 1000 : 1 ) ) ) : Dates::iso( $touch['at'] );
			if ( null !== $at ) {
				$out['at'] = $at;
			}
		}
		return array() === $out ? null : $out;
	}

	/**
	 * Lee una cookie como texto.
	 *
	 * @param array<string, mixed> $cookies Cookies.
	 * @param string               $name    Nombre.
	 */
	private static function cookie( array $cookies, string $name ): ?string {
		$value = $cookies[ $name ] ?? null;
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		return wp_unslash( $value );
	}
}
