<?php
/**
 * Textos para el dueño de la tienda en las pestañas Estado y Diagnóstico (auditoría U5: sin jerga).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Admin;

/**
 * Nombres de eventos, estados del envío y errores en palabras de una tienda.
 */
final class Wording {

	/**
	 * Lo que pasó en la tienda, en plural (para «últimas 24 horas»).
	 *
	 * @param string $event Nombre del evento.
	 */
	public static function event( string $event ): string {
		$labels = array(
			'cart.item_added'           => __( 'Productos agregados al carrito', 'imagina-growth' ),
			'cart.item_removed'         => __( 'Productos quitados del carrito', 'imagina-growth' ),
			'cart.updated'              => __( 'Carritos actualizados', 'imagina-growth' ),
			'coupon.applied'            => __( 'Cupones usados en el carrito', 'imagina-growth' ),
			'checkout.started'          => __( 'Visitas a la página de pago', 'imagina-growth' ),
			'checkout.contact_captured' => __( 'Correos o WhatsApp dejados en el pago', 'imagina-growth' ),
			'order.created'             => __( 'Pedidos creados', 'imagina-growth' ),
			'order.paid'                => __( 'Pedidos pagados', 'imagina-growth' ),
			'order.status_changed'      => __( 'Cambios de estado de pedidos', 'imagina-growth' ),
			'order.completed'           => __( 'Pedidos completados', 'imagina-growth' ),
			'order.cancelled'           => __( 'Pedidos cancelados', 'imagina-growth' ),
			'order.failed'              => __( 'Pagos que fallaron', 'imagina-growth' ),
			'order.refunded'            => __( 'Reembolsos', 'imagina-growth' ),
			'order.payment_pending'     => __( 'Pagos por confirmar (transferencia, contraentrega…)', 'imagina-growth' ),
			'customer.registered'       => __( 'Clientes nuevos registrados', 'imagina-growth' ),
			'customer.updated'          => __( 'Datos de clientes actualizados', 'imagina-growth' ),
			'customer.logged_in'        => __( 'Clientes que iniciaron sesión', 'imagina-growth' ),
			'review.submitted'          => __( 'Reseñas de productos', 'imagina-growth' ),
			'form.submitted'            => __( 'Formularios enviados', 'imagina-growth' ),
			'catalog.product_upserted'  => __( 'Productos del catálogo enviados', 'imagina-growth' ),
			'catalog.product_deleted'   => __( 'Productos borrados del catálogo', 'imagina-growth' ),
			'catalog.stock_changed'     => __( 'Cambios de inventario', 'imagina-growth' ),
			'catalog.price_changed'     => __( 'Cambios de precio', 'imagina-growth' ),
			'catalog.category_upserted' => __( 'Categorías enviadas', 'imagina-growth' ),
			'catalog.category_deleted'  => __( 'Categorías borradas', 'imagina-growth' ),
			'site.changed'              => __( 'Cambios en el sitio (plugins, tema o versiones)', 'imagina-growth' ),
			'site.heartbeat'            => __( 'Revisiones automáticas del sitio', 'imagina-growth' ),
		);
		if ( isset( $labels[ $event ] ) ) {
			return $labels[ $event ];
		}
		if ( str_starts_with( $event, 'subscription.' ) ) {
			return __( 'Movimientos de suscripciones', 'imagina-growth' );
		}
		if ( str_starts_with( $event, 'custom.' ) ) {
			return __( 'Eventos personalizados', 'imagina-growth' );
		}
		return $event;
	}

	/**
	 * Estado de un dato en la cola de envío.
	 *
	 * @param string $status pending, sending, sent o dead.
	 */
	public static function status( string $status ): string {
		$labels = array(
			'pending' => __( 'Esperando su turno', 'imagina-growth' ),
			'sending' => __( 'Enviándose en este momento', 'imagina-growth' ),
			'sent'    => __( 'Enviados (se guardan 7 días)', 'imagina-growth' ),
			'dead'    => __( 'No se pudieron enviar', 'imagina-growth' ),
		);
		return $labels[ $status ] ?? $status;
	}

	/**
	 * Un error del envío o de la conexión, explicado. El texto técnico va aparte, para soporte.
	 *
	 * @param string $raw Error técnico (`HTTP 401 signature`, `network: cURL error 6…`, `rejected: schema`…).
	 */
	public static function error( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( str_contains( $raw, 'site_disconnected' ) || str_starts_with( $raw, 'HTTP 403' ) ) {
			return __( 'Imagina Growth tiene este sitio como desconectado. Vuelve a conectarlo en la pestaña Avanzado.', 'imagina-growth' );
		}
		if ( str_starts_with( $raw, 'HTTP 401' ) ) {
			return __( 'Imagina Growth no reconoce la conexión de este sitio (la clave cambió o el reloj del servidor está desfasado). Vuelve a conectar el sitio; si sigue, avisa a soporte.', 'imagina-growth' );
		}
		if ( str_starts_with( $raw, 'HTTP 413' ) || 'too_large' === $raw ) {
			return __( 'Un dato era demasiado grande para enviarse y se descartó. El resto sigue normal.', 'imagina-growth' );
		}
		if ( str_starts_with( $raw, 'HTTP 429' ) ) {
			return __( 'Imagina Growth pidió esperar un poco. El envío se reintenta solo.', 'imagina-growth' );
		}
		if ( str_starts_with( $raw, 'rejected:' ) ) {
			return __( 'Imagina Growth no aceptó este dato (formato no válido). No se reintenta; si se repite, avisa a soporte.', 'imagina-growth' );
		}
		if ( str_contains( $raw, 'cURL error 6' ) || str_contains( $raw, 'resolve host' ) ) {
			return __( 'Tu servidor no encuentra la dirección de Imagina Growth (DNS). El envío se reintenta solo; si dura más de una hora, avisa a tu hosting.', 'imagina-growth' );
		}
		if ( str_starts_with( $raw, 'network:' ) || str_starts_with( $raw, 'HTTP 5' ) || str_starts_with( $raw, 'HTTP 0' ) ) {
			return __( 'No se pudo hablar con Imagina Growth en ese momento. Nada se pierde: el envío se reintenta solo.', 'imagina-growth' );
		}
		if ( 'otro envío en curso' === $raw ) {
			return __( 'Ya había otro envío en curso; este esperó su turno.', 'imagina-growth' );
		}
		return __( 'Hubo un problema al enviar los datos. Se reintenta solo; si se repite, avisa a soporte con el detalle técnico.', 'imagina-growth' );
	}

	/**
	 * Fecha y hora del sitio en palabras («hace 5 minutos»), desde un DATETIME en UTC.
	 *
	 * @param string|null $utc Fecha en UTC (`Y-m-d H:i:s`).
	 */
	public static function ago( ?string $utc ): string {
		if ( null === $utc || '' === $utc ) {
			return '—';
		}
		$ts = strtotime( $utc . ' UTC' );
		if ( false === $ts ) {
			return '—';
		}
		/* translators: %s: tiempo transcurrido, p. ej. «5 minutos». */
		return sprintf( __( 'hace %s', 'imagina-growth' ), human_time_diff( $ts ) );
	}
}
