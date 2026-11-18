<?php
/**
 * Eventos de pedidos de WooCommerce (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Tracking\RequestContext;

/**
 * `order.created`, `order.paid`, `order.status_changed`, `.completed`, `.cancelled`, `.failed`,
 * `order.payment_pending` y `order.refunded`. Los pedidos se envían siempre, sin importar el rol.
 */
final class OrderCollector {

	/** Estados que nunca generan `order.created` (borradores del checkout de bloques). */
	private const DRAFT_STATUSES = array( 'checkout-draft', 'auto-draft', 'trash' );

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		// Contexto del navegador al crear el pedido (antes de guardarlo: sin una escritura extra).
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'attach_context' ), 10, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( self::class, 'attach_context' ), 10, 1 );

		add_action( 'woocommerce_checkout_order_processed', array( self::class, 'on_checkout_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( self::class, 'on_store_api_processed' ), 20, 1 );
		add_action( 'woocommerce_new_order', array( self::class, 'on_new_order' ), 20, 2 );

		add_action( 'woocommerce_order_status_changed', array( self::class, 'on_status_changed' ), 20, 4 );
		add_action( 'woocommerce_payment_complete', array( self::class, 'on_payment_complete' ), 20, 1 );
		add_action( 'woocommerce_order_refunded', array( self::class, 'on_refunded' ), 20, 2 );
	}

	/**
	 * Guarda `_igr_ctx` con las cookies de la petición del comprador.
	 *
	 * @param mixed $order Pedido.
	 */
	public static function attach_context( mixed $order ): void {
		if ( ! $order instanceof \WC_Order || Recorder::is_suppressed() ) {
			return;
		}
		$ctx = RequestContext::capture();
		if ( array() !== $ctx['identity'] || array() !== $ctx['context'] ) {
			$order->update_meta_data( OrderEvents::CTX_META, $ctx );
		}
		// v3: el carrito del que sale el pedido, para que el hub lo marque convertido o recuperado.
		$token = CartCollector::current_token();
		if ( null !== $token ) {
			$order->update_meta_data( OrderEvents::CART_META, $token );
		}
	}

	/**
	 * Checkout clásico.
	 *
	 * @param mixed $order_id    ID.
	 * @param mixed $posted_data Datos enviados.
	 * @param mixed $order       Pedido.
	 */
	public static function on_checkout_processed( mixed $order_id, mixed $posted_data = null, mixed $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::created( $order );
		}
	}

	/**
	 * Checkout de bloques.
	 *
	 * @param mixed $order Pedido.
	 */
	public static function on_store_api_processed( mixed $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::created( $order );
		}
	}

	/**
	 * Pedidos creados por el admin o la API REST. Durante el checkout se espera al hook
	 * de «procesado» para tener el pedido completo.
	 *
	 * @param mixed $order_id ID.
	 * @param mixed $order    Pedido.
	 */
	public static function on_new_order( mixed $order_id, mixed $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		if ( ! $order instanceof \WC_Order || in_array( $order->get_created_via(), array( 'checkout', 'store-api' ), true ) ) {
			return;
		}
		self::created( $order );
	}

	/**
	 * Cambio de estado.
	 *
	 * @param mixed $order_id ID.
	 * @param mixed $from     Estado anterior.
	 * @param mixed $to       Estado nuevo.
	 * @param mixed $order    Pedido.
	 */
	public static function on_status_changed( mixed $order_id, mixed $from, mixed $to, mixed $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( (int) $order_id );
		$f     = (string) $from;
		$t     = (string) $to;
		if ( ! $order instanceof \WC_Order || in_array( $t, self::DRAFT_STATUSES, true ) ) {
			return;
		}
		$events = self::events();
		if ( null === $events ) {
			return;
		}
		try {
			// Un pedido de bloques pasa de borrador a pendiente: es su creación real.
			if ( in_array( $f, self::DRAFT_STATUSES, true ) ) {
				self::created( $order );
			}
			Recorder::record( $events->status_changed( $order, $f, $t ) );
			if ( in_array( $t, array( 'completed', 'cancelled', 'failed' ), true ) ) {
				Recorder::record( $events->terminal( $order, $t ) );
			}
			if ( in_array( $t, array( 'on-hold', 'pending' ), true ) && RemoteConfig::is_offline( (string) $order->get_payment_method() ) ) {
				Recorder::record( $events->payment_pending( $order ) );
			}
			self::maybe_paid( $order );
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar el cambio de estado: ' . $e->getMessage(), array( 'order' => $order->get_id() ) );
		}
	}

	/**
	 * Pago completado por la pasarela.
	 *
	 * @param mixed $order_id ID.
	 */
	public static function on_payment_complete( mixed $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( $order instanceof \WC_Order ) {
			self::maybe_paid( $order );
		}
	}

	/**
	 * Reembolso.
	 *
	 * @param mixed $order_id  ID del pedido.
	 * @param mixed $refund_id ID del reembolso.
	 */
	public static function on_refunded( mixed $order_id, mixed $refund_id ): void {
		$order  = wc_get_order( (int) $order_id );
		$refund = wc_get_order( (int) $refund_id );
		$events = self::events();
		if ( ! $order instanceof \WC_Order || ! $refund instanceof \WC_Order_Refund || null === $events ) {
			return;
		}
		try {
			Recorder::record( $events->refunded( $order, $refund ) );
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar el reembolso: ' . $e->getMessage(), array( 'order' => $order->get_id() ) );
		}
	}

	/**
	 * Registra `order.created` (el `event_id` determinista evita duplicados entre hooks).
	 *
	 * @param \WC_Order $order Pedido.
	 */
	private static function created( \WC_Order $order ): void {
		if ( in_array( $order->get_status(), self::DRAFT_STATUSES, true ) ) {
			return;
		}
		$events = self::events();
		if ( null === $events ) {
			return;
		}
		try {
			Recorder::record( $events->created( $order ) );
			self::maybe_paid( $order );
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar el pedido: ' . $e->getMessage(), array( 'order' => $order->get_id() ) );
		}
	}

	/**
	 * Registra `order.paid` la primera vez que el pedido queda pagado según la configuración
	 * (contraentrega solo cuando se cobra). Guarda la meta `_igr_paid_sent`.
	 *
	 * @param \WC_Order $order Pedido.
	 */
	private static function maybe_paid( \WC_Order $order ): void {
		if ( ! RemoteConfig::is_paid( (string) $order->get_status(), (string) $order->get_payment_method() ) ) {
			return;
		}
		if ( '' !== (string) $order->get_meta( OrderEvents::PAID_META, true ) ) {
			return;
		}
		$events = self::events();
		if ( null === $events || Recorder::is_suppressed() ) {
			return;
		}
		Recorder::record( $events->paid( $order ) );
		$order->update_meta_data( OrderEvents::PAID_META, (string) time() );
		$order->save_meta_data();
	}

	/**
	 * Constructor de eventos del sitio conectado.
	 */
	private static function events(): ?OrderEvents {
		$factory = EventFactory::current();
		return null === $factory ? null : new OrderEvents( $factory );
	}
}
