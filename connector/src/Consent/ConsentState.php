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
 * El modo lo elige el espacio de trabajo en el panel (cumplimiento.md §3.3):
 * «según el sitio» (`auto`, por defecto: estricto con banner, informado sin él),
 * «estricto» o «informado». Sin señal del visitante, en estricto no hay nada
 * aceptado y en informado hay analítica pero nunca marketing.
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
		if ( ! empty( $trk['ban'] ) ) {
			return 'igr-banner';
		}
		// Estricto elegido en el panel y el sitio sin banner: el propio, o nadie podría aceptar.
		return null !== $trk && 'strict' === self::workspace_mode() ? 'igr-banner' : 'none';
	}

	/**
	 * Modo elegido en el panel: `auto`, `strict` o `informed` (copia autocargada en `igr_state`).
	 */
	public static function workspace_mode(): string {
		$trk  = TrackerConfig::get();
		$mode = is_array( $trk ) ? (string) ( $trk['cm'] ?? 'auto' ) : 'auto';
		return in_array( $mode, array( 'strict', 'informed' ), true ) ? $mode : 'auto';
	}

	/**
	 * Modo efectivo: el elegido en el panel; con `auto`, estricto si hay banner e informado si no.
	 */
	public static function mode(): string {
		$chosen = self::workspace_mode();
		if ( 'auto' !== $chosen ) {
			return $chosen;
		}
		return 'none' === self::banner() ? 'informed' : 'strict';
	}

	/**
	 * Valor por defecto para el tracker (igual para todos los visitantes: cacheable).
	 *
	 * @return array{analytics: bool, marketing: bool, source: string}
	 */
	public static function defaults(): array {
		return array(
			'analytics' => 'informed' === self::mode(),
			'marketing' => false,
			'source'    => self::banner(),
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
