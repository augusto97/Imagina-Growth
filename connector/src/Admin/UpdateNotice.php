<?php
/**
 * Aviso de versión del plugin en el admin de WordPress (auditoría F15/U5).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Admin;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;

/**
 * El hub manda en `/v1/s/config` la versión mínima compatible, la última y el enlace de descarga (`plugin`); esa
 * configuración ya está guardada (se renueva una vez al día), así que el aviso no pide nada al hub al cargar el admin.
 *
 * - Versión menor que la mínima: aviso de error en todo el admin para quien puede actualizar plugins.
 * - Hay una más nueva: aviso informativo en el Escritorio, en Plugins y en la página de Imagina Growth; se puede
 *   ocultar hasta la siguiente versión.
 */
final class UpdateNotice {

	public const DISMISS_META = 'igr_dismissed_update';
	public const DISMISS_ARG  = 'igr_dismiss_update';

	/**
	 * Registra los hooks (solo en el admin).
	 */
	public static function register(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_init', array( self::class, 'maybe_dismiss' ) );
	}

	/**
	 * Estado de la versión instalada frente a la del hub.
	 *
	 * @return array{status: string, current: string, min: string, latest: string, url: string}
	 */
	public static function state(): array {
		$stored = get_option( RemoteConfig::OPTION, array() );
		$plugin = is_array( $stored ) && is_array( $stored['plugin'] ?? null ) ? $stored['plugin'] : array();
		$min    = self::version( $plugin['min_version'] ?? ( is_array( $stored ) ? ( $stored['min_plugin_version'] ?? '' ) : '' ) );
		$latest = self::version( $plugin['latest_version'] ?? '' );
		$status = 'current';
		if ( '' !== $min && version_compare( IGR_VERSION, $min, '<' ) ) {
			$status = 'outdated';
		} elseif ( '' !== $latest && version_compare( IGR_VERSION, $latest, '<' ) ) {
			$status = 'update_available';
		}
		return array(
			'status'  => $status,
			'current' => IGR_VERSION,
			'min'     => $min,
			'latest'  => $latest,
			'url'     => self::download_url( $plugin['download_url'] ?? null ),
		);
	}

	/**
	 * Pinta el aviso que corresponda.
	 */
	public static function render(): void {
		if ( ! Connection::is_connected() || ! ( current_user_can( 'update_plugins' ) || current_user_can( 'manage_options' ) ) ) {
			return;
		}
		$state = self::state();
		if ( 'current' === $state['status'] ) {
			return;
		}
		$link = sprintf( '<a class="button button-primary" href="%s">%s</a>', esc_url( $state['url'] ), esc_html__( 'Descargar la versión nueva', 'imagina-growth' ) );
		$how  = esc_html__( 'Instálala en Plugins → Añadir nuevo → Subir plugin (reemplaza la actual; no se pierde la conexión).', 'imagina-growth' );
		if ( 'outdated' === $state['status'] ) {
			printf(
				'<div class="notice notice-error igr-update-notice"><p><strong>%s</strong> %s</p><p>%s %s</p></div>',
				esc_html__( 'Imagina Growth: actualiza el plugin.', 'imagina-growth' ),
				esc_html(
					sprintf(
						/* translators: 1: versión instalada, 2: versión mínima. */
						__( 'La versión instalada (%1$s) ya no es compatible: necesitas la %2$s o una más nueva para que tus datos sigan llegando.', 'imagina-growth' ),
						$state['current'],
						$state['min']
					)
				),
				$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Armado con esc_url y esc_html.
				$how // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ya escapado.
			);
			return;
		}
		if ( ! self::on_info_screen() || get_user_meta( get_current_user_id(), self::DISMISS_META, true ) === $state['latest'] ) {
			return;
		}
		$dismiss = wp_nonce_url( add_query_arg( self::DISMISS_ARG, rawurlencode( $state['latest'] ) ), self::DISMISS_ARG );
		printf(
			'<div class="notice notice-info igr-update-notice"><p><strong>%s</strong> %s</p><p>%s %s <a href="%s">%s</a></p></div>',
			esc_html__( 'Imagina Growth: hay una versión nueva del plugin.', 'imagina-growth' ),
			esc_html(
				sprintf(
					/* translators: 1: versión nueva, 2: versión instalada. */
					__( 'La %1$s ya está disponible (tienes la %2$s).', 'imagina-growth' ),
					$state['latest'],
					$state['current']
				)
			),
			$link, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Armado con esc_url y esc_html.
			$how, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Ya escapado.
			esc_url( $dismiss ),
			esc_html__( 'Ocultar hasta la próxima versión', 'imagina-growth' )
		);
	}

	/**
	 * Oculta el aviso de la versión nueva para este usuario.
	 */
	public static function maybe_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ARG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- El nonce se revisa justo abajo.
			return;
		}
		check_admin_referer( self::DISMISS_ARG );
		$version = self::version( sanitize_text_field( wp_unslash( (string) $_GET[ self::DISMISS_ARG ] ) ) );
		if ( '' !== $version ) {
			update_user_meta( get_current_user_id(), self::DISMISS_META, $version );
		}
		wp_safe_redirect( remove_query_arg( array( self::DISMISS_ARG, '_wpnonce' ) ) );
		exit;
	}

	/**
	 * El aviso informativo solo en el Escritorio, en Plugins y en la página del plugin.
	 */
	private static function on_info_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = null !== $screen ? (string) $screen->id : '';
		return in_array( $id, array( 'dashboard', 'plugins', 'toplevel_page_' . AdminPage::SLUG ), true );
	}

	/**
	 * Enlace de descarga del hub: solo http(s); si no viene, el del hub conectado.
	 *
	 * @param mixed $url URL de la configuración.
	 */
	private static function download_url( mixed $url ): string {
		if ( is_string( $url ) && 1 === preg_match( '#^https?://[^\s"<>]+$#i', $url ) ) {
			return $url;
		}
		return Connection::api_url( 'downloads/imagina-growth-connector.zip' );
	}

	/**
	 * Versión semántica o vacío.
	 *
	 * @param mixed $value Valor.
	 */
	private static function version( mixed $value ): string {
		return is_string( $value ) && 1 === preg_match( '/^\d+\.\d+\.\d+$/', $value ) ? $value : '';
	}
}
