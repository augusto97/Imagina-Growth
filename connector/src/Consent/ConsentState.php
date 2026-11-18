<?php
/**
 * Estado del consentimiento de cookies (connector/CLAUDE.md §11, cumplimiento.md §3.3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Consent;

use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Detecta el banner del sitio y lee la decisión del visitante desde sus cookies,
 * con la misma prioridad que el tracker: WP Consent API, Complianz, CookieYes,
 * Cookiebot, Real Cookie Banner (por la WP Consent API) y el banner ligero propio.
 *
 * Sin señal, el valor es el más conservador: con banner, nada aceptado; sin
 * banner (modo «informado»), analítica sí y marketing no.
 */
final class ConsentState {

	/**
	 * Banner activo en el sitio.
	 */
	public static function banner(): string {
		if ( function_exists( 'wp_has_consent' ) ) {
			return 'wp-consent-api';
		}
		if ( defined( 'cmplz_plugin' ) || class_exists( 'COMPLIANZ' ) ) {
			return 'complianz';
		}
		if ( defined( 'CLI_VERSION' ) || defined( 'CKY_APP_VERSION' ) || class_exists( 'Cookie_Law_Info' ) ) {
			return 'cookieyes';
		}
		if ( defined( 'CYBOT_COOKIEBOT_VERSION' ) || class_exists( 'Cybot\\Cookiebot\\Lib\\Cookiebot_WP' ) || function_exists( 'cookiebot_active' ) ) {
			return 'cookiebot';
		}
		if ( defined( 'RCB_VERSION' ) || class_exists( 'DevOwl\\RealCookieBanner\\Core' ) ) {
			return 'real-cookie-banner';
		}
		$trk = TrackerConfig::get();
		return ! empty( $trk['ban'] ) ? 'igr-banner' : 'none';
	}

	/**
	 * `strict` con cualquier banner; `informed` sin banner.
	 */
	public static function mode(): string {
		return 'none' === self::banner() ? 'informed' : 'strict';
	}

	/**
	 * Valor por defecto para el tracker (igual para todos los visitantes: cacheable).
	 *
	 * @return array{analytics: bool, marketing: bool, source: string}
	 */
	public static function defaults(): array {
		$banner = self::banner();
		return array(
			'analytics' => 'none' === $banner,
			'marketing' => false,
			'source'    => $banner,
		);
	}

	/**
	 * Decisión del visitante de esta petición (eventos de servidor).
	 *
	 * @param array<string, mixed>|null $cookies Cookies (por defecto `$_COOKIE`).
	 * @return array{analytics: bool, marketing: bool, source: string}
	 */
	public static function from_request( ?array $cookies = null ): array {
		$cookies = $cookies ?? $_COOKIE; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
		$get     = static function ( string $name ) use ( $cookies ): ?string {
			$v = $cookies[ $name ] ?? null;
			return is_string( $v ) && '' !== $v ? rawurldecode( wp_unslash( $v ) ) : null;
		};
		if ( function_exists( 'wp_has_consent' ) ) {
			return array(
				'analytics' => (bool) wp_has_consent( 'statistics' ),
				'marketing' => (bool) wp_has_consent( 'marketing' ),
				'source'    => 'wp-consent-api',
			);
		}
		$cs = $get( 'cmplz_statistics' );
		$cm = $get( 'cmplz_marketing' );
		if ( null !== $cs || null !== $cm ) {
			return array(
				'analytics' => 'allow' === $cs,
				'marketing' => 'allow' === $cm,
				'source'    => 'complianz',
			);
		}
		$cy = $get( 'cookieyes-consent' );
		if ( null !== $cy ) {
			return array(
				'analytics' => str_contains( $cy, 'analytics:yes' ),
				'marketing' => str_contains( $cy, 'advertisement:yes' ),
				'source'    => 'cookieyes',
			);
		}
		$cb = $get( 'CookieConsent' );
		if ( null !== $cb ) {
			return array(
				'analytics' => 1 === preg_match( '/statistics:\s*true/', $cb ),
				'marketing' => 1 === preg_match( '/marketing:\s*true/', $cb ),
				'source'    => 'cookiebot',
			);
		}
		$own = $get( '_igr_consent' );
		if ( null !== $own ) {
			return array(
				'analytics' => str_contains( $own, 'a1' ),
				'marketing' => str_contains( $own, 'm1' ),
				'source'    => 'igr-banner',
			);
		}
		return self::defaults();
	}
}
