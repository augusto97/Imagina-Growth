<?php
/**
 * Sobres de los eventos `order.*` (docs/eventos.md §4.2), compartidos por el recolector y la importación.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Events;

use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Support\Phone;

/**
 * Cada método devuelve un sobre listo para el outbox, con su `event_id` determinista
 * (docs/api-connector.md §1.1).
 */
final class OrderEvents {

	public const CTX_META  = '_igr_ctx';
	public const PAID_META = '_igr_paid_sent';

	/**
	 * Constructor.
	 *
	 * @param EventFactory $factory Fábrica.
	 */
	public function __construct( private readonly EventFactory $factory ) {}

	/**
	 * `order.created`.
	 *
	 * @param \WC_Order                 $order   Pedido.
	 * @param bool                      $import  Importación histórica.
	 * @param array<string, mixed>|null $payload Objeto `order` ya armado.
	 * @return array<string, mixed>
	 */
	public function created( \WC_Order $order, bool $import = false, ?array $payload = null ): array {
		return $this->factory->make(
			'order.created',
			array( 'order' => $payload ?? OrderPayload::from( $order ) ),
			array(
				'type'        => $import ? 'import' : 'server',
				'occurred_at' => $order->get_date_created(),
				'key'         => 'order:' . $order->get_id() . ':created',
				'identity'    => self::identity( $order ),
				'context'     => self::context( $order, ! $import, $import ? null : 'checkout' ),
			)
		);
	}

	/**
	 * `order.paid`.
	 *
	 * @param \WC_Order                 $order   Pedido.
	 * @param bool                      $import  Importación histórica.
	 * @param array<string, mixed>|null $payload Objeto `order` ya armado.
	 * @return array<string, mixed>
	 */
	public function paid( \WC_Order $order, bool $import = false, ?array $payload = null ): array {
		$when = $order->get_date_paid() ?? $order->get_date_completed() ?? ( $import ? $order->get_date_created() : null );
		return $this->factory->make(
			'order.paid',
			array( 'order' => $payload ?? OrderPayload::from( $order ) ),
			array(
				'type'        => $import ? 'import' : 'server',
				'occurred_at' => $when,
				'key'         => 'order:' . $order->get_id() . ':paid',
				'identity'    => self::identity( $order ),
				'context'     => self::context( $order, ! $import ),
			)
		);
	}

	/**
	 * `order.status_changed`.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $from  Estado anterior.
	 * @param string    $to    Estado nuevo.
	 * @return array<string, mixed>
	 */
	public function status_changed( \WC_Order $order, string $from, string $to ): array {
		$modified = $order->get_date_modified();
		$stamp    = null !== $modified ? $modified->getTimestamp() : time();
		return $this->factory->make(
			'order.status_changed',
			array(
				'order_id' => (string) $order->get_id(),
				'from'     => mb_substr( $from, 0, 40 ),
				'to'       => mb_substr( $to, 0, 40 ),
				'order'    => OrderPayload::from( $order ),
			),
			array(
				'key'      => 'order:' . $order->get_id() . ':status:' . $from . ':' . $to . ':' . $stamp,
				'identity' => self::identity( $order ),
				'context'  => self::context( $order, false ),
			)
		);
	}

	/**
	 * `order.completed`, `order.cancelled` u `order.failed`.
	 *
	 * @param \WC_Order $order Pedido.
	 * @param string    $kind  `completed`, `cancelled` o `failed`.
	 * @return array<string, mixed>
	 */
	public function terminal( \WC_Order $order, string $kind ): array {
		$props = array( 'order_id' => (string) $order->get_id() );
		if ( 'completed' !== $kind ) {
			$props['reason'] = null;
		}
		$props['order'] = OrderPayload::from( $order );
		return $this->factory->make(
			'order.' . $kind,
			$props,
			array(
				'key'      => 'order:' . $order->get_id() . ':' . $kind,
				'identity' => self::identity( $order ),
				'context'  => self::context( $order, false ),
			)
		);
	}

	/**
	 * `order.payment_pending` (pago fuera de línea por confirmar).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>
	 */
	public function payment_pending( \WC_Order $order ): array {
		return $this->factory->make(
			'order.payment_pending',
			array(
				'order_id'       => (string) $order->get_id(),
				'payment_method' => mb_substr( (string) $order->get_payment_method(), 0, 100 ),
				'total'          => Money::format( $order->get_total() ),
				'currency'       => (string) $order->get_currency(),
				'pay_url'        => $order->needs_payment() ? mb_substr( (string) $order->get_checkout_payment_url(), 0, 2048 ) : null,
				'order'          => OrderPayload::from( $order ),
			),
			array(
				'key'      => 'order:' . $order->get_id() . ':payment_pending',
				'identity' => self::identity( $order ),
				'context'  => self::context( $order, false ),
			)
		);
	}

	/**
	 * `order.refunded`.
	 *
	 * @param \WC_Order        $order         Pedido padre.
	 * @param \WC_Order_Refund $refund        Reembolso.
	 * @param bool             $import        Importación histórica.
	 * @param bool             $with_snapshot Incluir la foto del pedido.
	 * @return array<string, mixed>
	 */
	public function refunded( \WC_Order $order, \WC_Order_Refund $refund, bool $import = false, bool $with_snapshot = true ): array {
		$amount = abs( (float) $refund->get_amount() );
		$items  = array();
		foreach ( $refund->get_items( 'line_item' ) as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$qty     = abs( (float) $item->get_quantity() );
			$items[] = array(
				'item_id'    => '' !== (string) $item->get_meta( '_refunded_item_id', true ) ? (string) $item->get_meta( '_refunded_item_id', true ) : null,
				'product_id' => (string) $item->get_product_id(),
				'qty'        => floor( $qty ) === $qty ? (int) $qty : $qty,
				'amount'     => Money::format( abs( (float) $item->get_total() ) ),
			);
		}
		$props = array(
			'order_id'    => (string) $order->get_id(),
			'refund_id'   => (string) $refund->get_id(),
			'amount'      => Money::format( $amount ),
			'is_partial'  => ( (float) $order->get_total() - (float) $order->get_total_refunded() ) > 0.004,
			'reason'      => '' !== (string) $refund->get_reason() ? mb_substr( wp_strip_all_tags( (string) $refund->get_reason() ), 0, 300 ) : null,
			'refunded_at' => Dates::iso( $refund->get_date_created() ),
			'items'       => $items,
		);
		if ( $with_snapshot ) {
			$props['order'] = OrderPayload::from( $order );
		}
		return $this->factory->make(
			'order.refunded',
			$props,
			array(
				'type'        => $import ? 'import' : 'server',
				'occurred_at' => $refund->get_date_created(),
				'key'         => 'refund:' . $refund->get_id(),
				'identity'    => self::identity( $order ),
				'context'     => self::context( $order, false ),
			)
		);
	}

	/**
	 * Identidad del comprador: contexto del navegador guardado al crear el pedido + datos fuertes.
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>
	 */
	public static function identity( \WC_Order $order ): array {
		$ctx      = self::stored_context( $order );
		$identity = is_array( $ctx['identity'] ?? null ) ? $ctx['identity'] : array();
		$user_id  = (int) $order->get_customer_id();
		if ( $user_id > 0 ) {
			$identity['customer_ref'] = 'wc:' . $user_id;
		}
		$email = strtolower( trim( (string) $order->get_billing_email() ) );
		if ( '' !== $email && is_email( $email ) ) {
			$identity['email'] = $email;
		}
		$raw_phone = (string) $order->get_billing_phone();
		if ( '' !== trim( $raw_phone ) ) {
			$country           = (string) ( $order->get_billing_country() ? $order->get_billing_country() : WC()->countries->get_base_country() );
			$identity['phone'] = Phone::e164( $raw_phone, $country ) ?? mb_substr( trim( $raw_phone ), 0, 32 );
		}
		return array_intersect_key( $identity, array_flip( array( 'anonymous_id', 'session_id', 'customer_ref', 'email', 'phone', 'link_token' ) ) );
	}

	/**
	 * Contexto del evento.
	 *
	 * @param \WC_Order   $order     Pedido.
	 * @param bool        $transit   Incluir IP y user agent (solo en tránsito, para las conversiones).
	 * @param string|null $page_type Tipo de página.
	 * @return array<string, mixed>
	 */
	public static function context( \WC_Order $order, bool $transit, ?string $page_type = null ): array {
		$ctx     = self::stored_context( $order );
		$context = is_array( $ctx['context'] ?? null ) ? $ctx['context'] : array();
		if ( null !== $page_type ) {
			$context['page_type'] = $page_type;
		}
		$context['currency'] = (string) $order->get_currency();
		$locale              = (string) get_locale();
		if ( 1 === preg_match( '/^[a-z]{2}([-_][A-Za-z]{2})?$/', $locale ) ) {
			$context['locale'] = $locale;
		}
		if ( $transit ) {
			$ip = (string) $order->get_customer_ip_address();
			if ( '' !== $ip && false !== filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				$context['ip'] = $ip;
			}
			$ua = (string) $order->get_customer_user_agent();
			if ( '' !== $ua ) {
				$context['user_agent'] = mb_substr( $ua, 0, 1024 );
			}
		}
		return $context;
	}

	/**
	 * Contexto del navegador guardado al crear el pedido (`_igr_ctx`).
	 *
	 * @param \WC_Order $order Pedido.
	 * @return array<string, mixed>
	 */
	public static function stored_context( \WC_Order $order ): array {
		$ctx = $order->get_meta( self::CTX_META, true );
		return is_array( $ctx ) ? $ctx : array();
	}
}
