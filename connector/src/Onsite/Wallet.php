<?php
/**
 * Saldo a favor en el checkout (connector/CLAUDE.md §10, api-connector.md §5.5).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * «Usar saldo»: solo clientes con sesión. `igr-wallet.js` pide el saldo a `/me` y muestra
 * el botón si hay; el clic llama a `?wc-ajax=igr_wallet_redeem`, que hace la **única
 * llamada síncrona** al hub (firmada, 3 s) y aplica el cupón de un solo uso al carrito.
 * Si el hub no responde, el checkout sigue igual.
 */
final class Wallet {

	public const ACTION   = 'igr_wallet_redeem';
	private const NONCE   = 'igr-wallet';
	public const TIMEOUT  = 3;
	private const SESSION = 'igr_wallet_coupon';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'wc_ajax_' . self::ACTION, array( self::class, 'ajax' ) );
		if ( ! TrackerConfig::on( 'wallet' ) ) {
			return;
		}
		add_action( 'woocommerce_review_order_before_payment', array( self::class, 'render_classic' ) );
		add_filter( 'render_block_woocommerce/checkout', array( self::class, 'render_block' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 21 );
	}

	/**
	 * ¿Corresponde mostrarlo en esta petición?
	 */
	private static function eligible(): bool {
		return is_user_logged_in() && Connection::is_connected() && Slots::available() && TrackerConfig::on( 'wallet' );
	}

	/**
	 * Slot (oculto hasta saber que hay saldo; el checkout no se cachea).
	 */
	public static function html(): string {
		if ( ! self::eligible() ) {
			return '';
		}
		return '<div class="igr-slot igr-wallet woocommerce-info" data-igr-slot="wallet" data-ajax="' . esc_url( \WC_AJAX::get_endpoint( self::ACTION ) ) . '" data-nonce="' . esc_attr( wp_create_nonce( self::NONCE ) ) . '" hidden style="display:none">'
			. '<span data-igr-wallet-text>' . esc_html__( 'Tienes saldo a favor.', 'imagina-growth' ) . '</span> '
			. '<button type="button" class="button">' . esc_html__( 'Usar saldo', 'imagina-growth' ) . '</button>'
			. '<span data-igr-wallet-msg role="status" aria-live="polite" style="display:block"></span></div>';
	}

	/**
	 * Checkout clásico: antes de los medios de pago (no se repinta con cada actualización).
	 */
	public static function render_classic(): void {
		echo self::html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escapado en html().
	}

	/**
	 * Checkout de bloques: antes del bloque (fuera del árbol de React).
	 *
	 * @param mixed $content HTML del bloque.
	 */
	public static function render_block( mixed $content ): string {
		return self::html() . (string) $content;
	}

	/**
	 * `igr-wallet.js` solo en el checkout y con sesión.
	 */
	public static function enqueue(): void {
		if ( ! self::eligible() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}
		wp_enqueue_script(
			'igr-wallet',
			plugins_url( 'assets/igr-wallet.js', IGR_PLUGIN_FILE ),
			wp_script_is( 'igr-tracker', 'registered' ) ? array( 'igr-tracker' ) : array(),
			IGR_VERSION,
			array(
				'strategy'  => 'defer',
				'in_footer' => true,
			)
		);
	}

	/**
	 * `?wc-ajax=igr_wallet_redeem`: pide el cupón al hub y lo aplica al carrito.
	 */
	public static function ajax(): void {
		if ( ! check_ajax_referer( self::NONCE, 'security', false ) || ! is_user_logged_in() ) {
			wp_send_json(
				array(
					'ok'      => false,
					'message' => __( 'Recarga la página e inténtalo de nuevo.', 'imagina-growth' ),
				),
				403
			);
		}
		$result = self::redeem( get_current_user_id() );
		wp_send_json( $result, $result['ok'] ? 200 : 422 );
	}

	/**
	 * Canje (también lo usan las pruebas).
	 *
	 * @param int $user_id Cliente.
	 * @return array{ok: bool, message: string, code?: string}
	 */
	public static function redeem( int $user_id ): array {
		$fail = array(
			'ok'      => false,
			'message' => __( 'No pudimos usar tu saldo ahora. Inténtalo más tarde.', 'imagina-growth' ),
		);
		if ( $user_id <= 0 || ! Connection::is_connected() || ! function_exists( 'WC' ) || ! WC()->cart instanceof \WC_Cart ) {
			return $fail;
		}
		$cart = WC()->cart;
		$prev = null !== WC()->session ? (string) WC()->session->get( self::SESSION ) : '';
		if ( '' !== $prev && $cart->has_discount( $prev ) ) {
			return array(
				'ok'      => true,
				'message' => __( 'Tu saldo ya está aplicado.', 'imagina-growth' ),
				'code'    => $prev,
			);
		}
		$total = (float) $cart->get_total( 'edit' );
		if ( $total <= 0 ) {
			return array(
				'ok'      => false,
				'message' => __( 'No hay nada por pagar en tu carrito.', 'imagina-growth' ),
			);
		}
		$body     = array(
			'customer_ref' => 'wc:' . $user_id,
			'cart_total'   => Money::format( $total ),
			'currency'     => get_woocommerce_currency(),
			'cart_token'   => null !== WC()->session ? ( CartCollector::current_token() ?? CartCollector::token() ) : null,
		);
		$response = Http::signed( 'POST', Connection::api_url( 'v1/s/wallet/redeem' ), Json::encode( array_filter( $body, static fn( $v ): bool => null !== $v ) ), Connection::site_id(), Connection::secret(), false, self::TIMEOUT );
		if ( 409 === $response->status ) {
			return array(
				'ok'      => false,
				'message' => __( 'No tienes saldo disponible por ahora.', 'imagina-growth' ),
			);
		}
		$code = (string) ( $response->json['coupon']['code'] ?? '' );
		if ( 200 !== $response->status || 1 !== preg_match( '/^[A-Za-z0-9_-]{4,50}$/', $code ) ) {
			Logger::warning( 'No se pudo canjear el saldo en el hub: ' . $response->describe() );
			return $fail;
		}
		$applied = $cart->has_discount( $code ) || $cart->apply_coupon( $code );
		if ( function_exists( 'wc_clear_notices' ) ) {
			wc_clear_notices();
		}
		if ( ! $applied ) {
			Logger::warning( 'WooCommerce no aplicó el cupón de saldo ' . $code );
			return $fail;
		}
		if ( null !== WC()->session ) {
			WC()->session->set( self::SESSION, $code );
		}
		$amount = (string) ( $response->json['coupon']['amount'] ?? '' );
		return array(
			'ok'      => true,
			'message' => '' !== $amount
				/* translators: %s: monto aplicado. */
				? sprintf( __( 'Aplicamos %s de tu saldo.', 'imagina-growth' ), html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES ) )
				: __( 'Aplicamos tu saldo.', 'imagina-growth' ),
			'code'    => $code,
		);
	}
}
