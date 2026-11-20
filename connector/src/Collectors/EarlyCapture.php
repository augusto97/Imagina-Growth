<?php
/**
 * Captura temprana del checkout: `checkout.contact_captured` (docs/eventos.md §4.2, cumplimiento.md §3.2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Phone;
use ImaginaGrowth\Connector\Support\Uuid;
use ImaginaGrowth\Connector\Tracking\RequestContext;

/**
 * Cuando un invitado escribe un correo o un teléfono válido en el checkout, el carrito queda unido a ese dato en el
 * hub para que el flujo de carrito abandonado sepa a quién escribirle.
 *
 * - Solo si el espacio de trabajo la activó (`settings.early_capture` de `/v1/s/config`, apagada por defecto) y con
 *   el aviso junto al campo («Usaremos tu correo o WhatsApp para ayudarte a terminar tu compra»).
 * - En el servidor, cuando WooCommerce ya recibe esos datos: checkout clásico en `update_order_review` (el plugin
 *   pide esa actualización al salir del campo de correo o teléfono) y de bloques en la actualización del cliente de
 *   la Store API. Ninguna llamada al hub: una fila en el outbox (o la actualización de la que espera).
 * - *Debounce*: la fila sale 20 s después; si en ese tiempo llega otro valor del mismo carrito, se reemplaza (la
 *   persona siguió escribiendo o corrigió el dato). Una sola vez por carrito y dato, y como mucho 5 por carrito.
 * - El dato va firmado en `identity` (nunca en `properties`) y no crea consentimiento de marketing.
 * - Las personas con sesión no se capturan: ya se conocen por su cuenta.
 */
final class EarlyCapture {

	public const EVENT            = 'checkout.contact_captured';
	public const SESSION          = 'igr_early_capture';
	public const DEBOUNCE_SECONDS = 20;
	public const MAX_PER_CART     = 5;

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( self::class, 'on_classic_update' ), 20, 1 );
		add_action( 'woocommerce_store_api_cart_update_customer_from_request', array( self::class, 'on_blocks_update' ), 20, 2 );
		add_filter( 'woocommerce_checkout_fields', array( self::class, 'classic_notice' ), 20, 1 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 30, 0 );
	}

	/**
	 * ¿La activó el espacio de trabajo?
	 */
	public static function enabled(): bool {
		return true === ( RemoteConfig::settings()['early_capture'] ?? false );
	}

	/**
	 * Aviso que exige cumplimiento.md §3.2, junto al campo.
	 */
	public static function notice(): string {
		return __( 'Usaremos tu correo o WhatsApp para ayudarte a terminar tu compra.', 'imagina-growth' );
	}

	/**
	 * Checkout clásico: WooCommerce manda los campos del formulario en `post_data`.
	 *
	 * @param mixed $post_data Formulario codificado como URL.
	 */
	public static function on_classic_update( mixed $post_data = '' ): void {
		if ( ! self::enabled() || ! is_string( $post_data ) || '' === $post_data || strlen( $post_data ) > 65536 ) {
			return;
		}
		$fields = array();
		parse_str( $post_data, $fields );
		self::capture(
			is_string( $fields['billing_email'] ?? null ) ? $fields['billing_email'] : '',
			is_string( $fields['billing_phone'] ?? null ) ? $fields['billing_phone'] : '',
			is_string( $fields['billing_country'] ?? null ) ? $fields['billing_country'] : '',
			'classic'
		);
	}

	/**
	 * Checkout de bloques: la Store API ya guardó en el cliente lo que la persona escribió.
	 *
	 * @param mixed $customer Cliente de WooCommerce.
	 * @param mixed $request  Petición REST.
	 */
	public static function on_blocks_update( mixed $customer = null, mixed $request = null ): void {
		unset( $request );
		if ( ! self::enabled() || ! $customer instanceof \WC_Customer ) {
			return;
		}
		self::capture( (string) $customer->get_billing_email(), (string) $customer->get_billing_phone(), (string) $customer->get_billing_country(), 'blocks' );
	}

	/**
	 * Registra (o actualiza, dentro del *debounce*) la captura del carrito de la sesión.
	 *
	 * @param string $raw_email Correo como llegó.
	 * @param string $raw_phone Teléfono como llegó.
	 * @param string $country   País de facturación (para el teléfono).
	 * @param string $checkout  `classic` o `blocks`.
	 * @return bool true si quedó una fila en el outbox.
	 */
	public static function capture( string $raw_email, string $raw_phone, string $country, string $checkout ): bool {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || null === WC()->session || ! WC()->cart instanceof \WC_Cart || WC()->cart->is_empty() ) {
			return false;
		}
		if ( is_user_logged_in() || Recorder::is_suppressed() ) {
			return false;
		}
		$email = self::email( $raw_email );
		$phone = self::phone( $raw_phone, $country );
		if ( null === $email && null === $phone ) {
			return false;
		}
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return false;
		}
		$token = CartCollector::token();
		$fp    = hash( 'sha256', $token . '|' . (string) $email . '|' . (string) $phone );
		$state = WC()->session->get( self::SESSION );
		if ( ! is_array( $state ) || ( $state['token'] ?? '' ) !== $token ) {
			$state = array(
				'token'   => $token,
				'sent'    => array(),
				'pending' => null,
			);
		}
		$sent = array_values( array_filter( (array) ( $state['sent'] ?? array() ), 'is_string' ) );
		if ( in_array( $fp, $sent, true ) ) {
			return false; // Una vez por carrito y dato.
		}

		try {
			$envelope = self::envelope( $factory, $token, $email, $phone, $checkout );
			$pending  = is_array( $state['pending'] ?? null ) ? $state['pending'] : null;
			$replaced = false;
			if ( null !== $pending && time() - (int) ( $pending['at'] ?? 0 ) < self::DEBOUNCE_SECONDS && is_string( $pending['event_id'] ?? null ) ) {
				// Dentro del debounce: el mismo evento con el dato nuevo (si todavía no salió).
				$envelope['event_id'] = $pending['event_id'];
				$replaced             = Outbox::replace_pending( $pending['event_id'], $envelope, self::DEBOUNCE_SECONDS );
				if ( $replaced ) {
					$sent = array_values( array_diff( $sent, array( (string) ( $pending['fp'] ?? '' ) ) ) );
				} else {
					$envelope['event_id'] = Uuid::v7(); // Ya salió: va como una captura nueva.
				}
			}
			if ( ! $replaced && ( count( $sent ) >= self::MAX_PER_CART || ! Recorder::record( $envelope, self::DEBOUNCE_SECONDS ) ) ) {
				return false;
			}
			$sent[]           = $fp;
			$state['sent']    = array_slice( $sent, -self::MAX_PER_CART * 2 );
			$state['pending'] = array(
				'event_id' => (string) $envelope['event_id'],
				'fp'       => $fp,
				'at'       => time(),
			);
			WC()->session->set( self::SESSION, $state );
			return true;
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar la captura temprana: ' . $e->getMessage(), array( 'event' => self::EVENT ) );
			return false;
		}
	}

	/**
	 * Correo válido y completo (con dominio de nivel superior de 2 letras o más), en minúsculas.
	 *
	 * @param string $raw Correo.
	 */
	public static function email( string $raw ): ?string {
		$email = strtolower( trim( $raw ) );
		if ( '' === $email || strlen( $email ) > 254 || ! is_email( $email ) ) {
			return null;
		}
		return 1 === preg_match( '/@[^@]+\.[a-z]{2,24}$/', $email ) ? $email : null;
	}

	/**
	 * Teléfono en E.164 con al menos 10 dígitos (un número a medio escribir no cuenta).
	 *
	 * @param string $raw     Teléfono.
	 * @param string $country País de facturación.
	 */
	public static function phone( string $raw, string $country ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw || strlen( $raw ) > 32 ) {
			return null;
		}
		$country = 1 === preg_match( '/^[A-Za-z]{2}$/', $country ) ? strtoupper( $country ) : self::base_country();
		$e164    = Phone::e164( $raw, $country );
		return null !== $e164 && strlen( $e164 ) >= 11 ? $e164 : null;
	}

	/**
	 * Arma el sobre: el dato en `identity`, el carrito y el tipo de checkout en `properties`.
	 *
	 * @param EventFactory $factory  Fábrica.
	 * @param string       $token    Carrito.
	 * @param string|null  $email    Correo.
	 * @param string|null  $phone    Teléfono.
	 * @param string       $checkout Tipo de checkout.
	 * @return array<string, mixed>
	 */
	private static function envelope( EventFactory $factory, string $token, ?string $email, ?string $phone, string $checkout ): array {
		$ctx      = RequestContext::capture();
		$identity = $ctx['identity'];
		if ( null !== $email ) {
			$identity['email'] = $email;
		}
		if ( null !== $phone ) {
			$identity['phone'] = $phone;
		}
		$context              = $ctx['context'];
		$context['page_type'] = 'checkout';
		$context['currency']  = get_woocommerce_currency();
		return $factory->make(
			self::EVENT,
			array(
				'has_email'  => null !== $email,
				'has_phone'  => null !== $phone,
				'cart_token' => $token,
				'checkout'   => 'blocks' === $checkout ? 'blocks' : 'classic',
			),
			array(
				'identity' => $identity,
				'context'  => $context,
			)
		);
	}

	/**
	 * Checkout clásico: el aviso va como descripción del campo de correo (o del teléfono si no hay correo).
	 *
	 * @param mixed $fields Campos del checkout.
	 * @return mixed
	 */
	public static function classic_notice( mixed $fields ): mixed {
		if ( ! is_array( $fields ) || ! self::enabled() || is_user_logged_in() ) {
			return $fields;
		}
		foreach ( array( 'billing_email', 'billing_phone' ) as $key ) {
			if ( isset( $fields['billing'][ $key ] ) && is_array( $fields['billing'][ $key ] ) ) {
				$current                                  = trim( (string) ( $fields['billing'][ $key ]['description'] ?? '' ) );
				$fields['billing'][ $key ]['description'] = '' === $current ? self::notice() : $current . ' ' . self::notice();
				break;
			}
		}
		return $fields;
	}

	/**
	 * En la página del checkout (que no se cachea): en el clásico, pide `update_checkout` al salir del correo o del
	 * teléfono (WooCommerce solo lo hace con la dirección); en el de bloques, pone el aviso debajo del correo.
	 */
	public static function enqueue(): void {
		if ( ! self::enabled() || is_user_logged_in() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
			return;
		}
		if ( wp_script_is( 'wc-checkout', 'enqueued' ) ) {
			wp_add_inline_script( 'wc-checkout', self::classic_script() );
			return;
		}
		// Sin el script del clásico, el checkout es de bloques (en la página o en la plantilla del tema).
		wp_register_script( 'igr-early-capture', false, array(), IGR_VERSION, true );
		wp_enqueue_script( 'igr-early-capture' );
		wp_add_inline_script( 'igr-early-capture', self::blocks_script() );
	}

	/**
	 * Clásico: actualización del checkout al cambiar el correo o el teléfono.
	 */
	public static function classic_script(): string {
		return 'jQuery(function($){$("form.checkout").on("change","input[name=billing_email],input[name=billing_phone]",function(){$(document.body).trigger("update_checkout");});});';
	}

	/**
	 * Bloques: el aviso debajo del campo de correo (React lo pinta después; se vuelve a poner si lo quita).
	 */
	public static function blocks_script(): string {
		$text = (string) wp_json_encode( self::notice() );
		return '(function(){var t=' . $text . ',q=0;function put(){q=0;var i=document.querySelector(".wc-block-checkout__contact-fields input[type=email],.wc-block-checkout input#email");if(!i)return;var b=i.closest(".wc-block-components-text-input")||i.parentNode;if(!b||!b.parentNode||(b.nextSibling&&b.nextSibling.className==="igr-ec-notice"))return;var p=document.createElement("p");p.className="igr-ec-notice";p.style.cssText="margin:.4em 0 0;font-size:.85em;opacity:.8";p.textContent=t;b.parentNode.insertBefore(p,b.nextSibling);}if(!window.MutationObserver||!document.body)return;new MutationObserver(function(){if(!q){q=1;(window.requestAnimationFrame||setTimeout)(put);}}).observe(document.body,{childList:true,subtree:true});put();})();';
	}

	/**
	 * País de la tienda para los teléfonos sin indicativo.
	 */
	private static function base_country(): string {
		$base = function_exists( 'wc_get_base_location' ) ? wc_get_base_location() : array();
		$cc   = is_array( $base ) ? (string) ( $base['country'] ?? '' ) : '';
		return 1 === preg_match( '/^[A-Z]{2}$/', $cc ) ? $cc : 'CO';
	}
}
