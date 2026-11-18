<?php
/**
 * Mi cuenta → «Mis recompensas» (connector/CLAUDE.md §10, M13 y M14).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Endpoint `recompensas` de WooCommerce con el banner de la cuenta y el slot `loyalty`.
 * El contenido lo pide el tracker con `igrUser.token` (Mi cuenta no se cachea).
 */
final class Account {

	public const ENDPOINT = 'recompensas';
	private const REWRITE = 1;

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		if ( ! TrackerConfig::on( 'rw' ) ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', array( self::class, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu' ), 20 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( self::class, 'content' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( self::class, 'title' ) );
	}

	/**
	 * WooCommerce registra el endpoint de reescritura para sus variables de consulta.
	 *
	 * @param mixed $vars Variables.
	 * @return array<string, string>
	 */
	public static function query_vars( mixed $vars ): array {
		$vars                   = is_array( $vars ) ? $vars : array();
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * «Mis recompensas» antes de «Salir».
	 *
	 * @param mixed $items Elementos del menú.
	 * @return array<string, string>
	 */
	public static function menu( mixed $items ): array {
		$items  = is_array( $items ) ? $items : array();
		$logout = $items['customer-logout'] ?? null;
		unset( $items['customer-logout'] );
		$items[ self::ENDPOINT ] = __( 'Mis recompensas', 'imagina-growth' );
		if ( null !== $logout ) {
			$items['customer-logout'] = $logout;
		}
		return $items;
	}

	/**
	 * Título del endpoint.
	 */
	public static function title(): string {
		return __( 'Mis recompensas', 'imagina-growth' );
	}

	/**
	 * Contenido: banner de la cuenta y recompensas.
	 */
	public static function content(): void {
		echo Slots::banner( array( 'placement' => 'account' ) ) . Slots::loyalty(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa.
	}

	/**
	 * Refresca las reglas de reescritura solo cuando el endpoint aparece o desaparece
	 * (conectar, desconectar o cambiar el ajuste). Sin consultas: el estado está en `igr_state`.
	 */
	public static function sync_rewrite(): void {
		$want = Connection::is_connected() && TrackerConfig::on( 'rw' ) ? self::REWRITE : 0;
		$have = (int) ( Connection::state()['rw_flushed'] ?? 0 );
		if ( $want === $have ) {
			return;
		}
		flush_rewrite_rules( false );
		Connection::update_state( array( 'rw_flushed' => $want > 0 ? $want : null ) );
	}
}
