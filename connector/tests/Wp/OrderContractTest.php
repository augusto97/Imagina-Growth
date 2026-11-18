<?php
/**
 * Contrato de los eventos de pedidos con WooCommerce real.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\OrderCollector;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Events\OrderPayload;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Uuid;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Cada evento que arma el plugin valida contra hub/resources/schemas/events.
 */
final class OrderContractTest extends WpTestCase {

	private function events(): OrderEvents {
		return new OrderEvents( new EventFactory( self::SITE_ID, 'woocommerce' ) );
	}

	public function test_pedido_pagado_por_pse_cumple_el_contrato(): void {
		$order = $this->order(
			array(
				'status'   => 'processing',
				'shipping' => true,
			)
		);
		$order->set_date_paid( time() );
		$order->save();
		$events = $this->events();
		$this->assertContract( array( $events->created( $order ), $events->paid( $order ), $events->status_changed( $order, 'pending', 'processing' ) ) );

		$payload = OrderPayload::from( $order );
		$this->assertSame( 'COP', $payload['currency'] );
		$this->assertSame( 'processing', $payload['status'] );
		$this->assertSame( '312000.00', $payload['totals']['total'] );
		$this->assertSame( '12000.00', $payload['totals']['shipping'] );
		$this->assertSame( '150000.00', $payload['items'][0]['unit_price'] );
		$this->assertSame( 2, $payload['items'][0]['qty'] );
		$this->assertSame( 'flat_rate', $payload['shipping_method']['id'] );
		$this->assertSame( 'ana@example.com', $payload['billing']['email'] );
		$this->assertNull( $payload['customer_ref'], 'invitado' );
	}

	public function test_objetos_vacios_viajan_como_llaves(): void {
		$order = wc_create_order();
		$order->add_product( $this->product(), 1 );
		$order->calculate_totals( false );
		$order->save();
		$created = $this->events()->created( $order, true );
		$json    = Json::encode( $created );
		$this->assertStringContainsString( '"meta":{}', $json );
		$this->assertStringContainsString( '"billing":{}', $json, 'sin datos de facturación' );
		$this->assertStringContainsString( '"coupons":[]', $json );
		$this->assertContract( array( $created ) );
	}

	public function test_contraentrega_cliente_registrado_y_estados_terminales(): void {
		$user   = self::factory()->user->create( array( 'role' => 'customer' ) );
		$order  = $this->order(
			array(
				'gateway'       => 'cod',
				'gateway_title' => 'Pago contra entrega',
				'status'        => 'processing',
				'customer'      => $user,
			)
		);
		$events = $this->events();
		$batch  = array(
			$events->created( $order ),
			$events->payment_pending( $order ),
			$events->terminal( $order, 'completed' ),
			$events->terminal( $order, 'cancelled' ),
			$events->terminal( $order, 'failed' ),
		);
		$this->assertContract( $batch );
		$this->assertSame( 'wc:' . $user, $batch[0]['identity']['customer_ref'] );
		$this->assertSame( '+573001234567', $batch[0]['identity']['phone'] );
		$this->assertTrue( OrderPayload::from( $order )['payment_method']['is_offline'] );
	}

	public function test_reembolsos_parcial_y_total(): void {
		$order = $this->order( array( 'status' => 'completed' ) );
		$order->set_date_paid( time() );
		$order->save();
		$partial = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => '50000',
				'reason'   => 'Color diferente',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $partial );
		$order    = wc_get_order( $order->get_id() );
		$refunded = $this->events()->refunded( $order, $partial );
		$this->assertContract( array( $refunded ) );
		$this->assertSame( '50000.00', $refunded['properties']['amount'] );
		$this->assertTrue( $refunded['properties']['is_partial'] );
		$this->assertSame( Uuid::event( self::SITE_ID, 'refund:' . $partial->get_id() ), $refunded['event_id'] );
		$this->assertSame( '50000.00', $refunded['properties']['order']['totals']['refunded'] );

		$rest  = wc_create_refund(
			array(
				'order_id' => $order->get_id(),
				'amount'   => (string) ( (float) $order->get_total() - 50000 ),
			)
		);
		$order = wc_get_order( $order->get_id() );
		$full  = $this->events()->refunded( $order, $rest, true, false );
		$this->assertContract( array( $full ) );
		$this->assertFalse( $full['properties']['is_partial'] );
		$this->assertArrayNotHasKey( 'order', $full['properties'] );
		$this->assertSame( 'import', $full['source']['type'] );
	}

	public function test_costo_unitario_del_item(): void {
		$product = $this->product( '150000' );
		$product->update_meta_data( '_wc_cog_cost', '90000' );
		$product->save();
		$order   = $this->order(
			array(
				'product' => $product,
				'qty'     => 2,
			)
		);
		$payload = OrderPayload::from( $order );
		$this->assertSame( '90000.00', $payload['items'][0]['cost'], 'cost es unitario: el hub lo multiplica por qty' );
	}

	public function test_event_ids_deterministas_de_api_connector(): void {
		$order  = $this->order( array( 'status' => 'processing' ) );
		$events = $this->events();
		$id     = $order->get_id();
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:created" ), $events->created( $order )['event_id'] );
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:created" ), $events->created( $order, true )['event_id'], 'importación = en vivo' );
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:paid" ), $events->paid( $order, true )['event_id'] );
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:completed" ), $events->terminal( $order, 'completed' )['event_id'] );
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:payment_pending" ), $events->payment_pending( $order )['event_id'] );
		$stamp = $order->get_date_modified()->getTimestamp();
		$this->assertSame( Uuid::event( self::SITE_ID, "order:{$id}:status:pending:processing:{$stamp}" ), $events->status_changed( $order, 'pending', 'processing' )['event_id'] );
	}

	public function test_el_recolector_registra_creado_pagado_y_estados_en_el_outbox(): void {
		$this->connect();
		OrderCollector::register();
		$order = $this->order( array( 'status' => 'pending' ) );
		OrderCollector::on_checkout_processed( $order->get_id(), array(), $order );
		$order->update_status( 'processing' );
		$order->update_status( 'completed' );

		$names = array_column( $this->outbox(), 'event' );
		$this->assertContains( 'order.created', $names );
		$this->assertContains( 'order.paid', $names );
		$this->assertContains( 'order.completed', $names );
		$this->assertSame( 1, count( array_keys( $names, 'order.paid', true ) ), 'order.paid una sola vez' );
		$this->assertContract( $this->outbox_raw() );
		$this->assertNotSame( '', (string) wc_get_order( $order->get_id() )->get_meta( OrderEvents::PAID_META ) );
	}

	public function test_contraentrega_se_paga_solo_al_completarse(): void {
		$this->connect();
		OrderCollector::register();
		$order = $this->order(
			array(
				'gateway' => 'cod',
				'status'  => 'pending',
			)
		);
		$order->update_status( 'processing' );
		$this->assertSame( array(), $this->outbox( 'order.paid' ), 'contraentrega en proceso no es venta' );
		$order->update_status( 'completed' );
		$this->assertCount( 1, $this->outbox( 'order.paid' ) );
	}

	public function test_transferencia_en_espera_emite_pago_pendiente(): void {
		$this->connect();
		OrderCollector::register();
		$order = $this->order(
			array(
				'gateway' => 'bacs',
				'status'  => 'pending',
			)
		);
		$order->update_status( 'on-hold' );
		$pending = $this->outbox( 'order.payment_pending' );
		$this->assertCount( 1, $pending );
		$this->assertContract( $this->outbox_raw( 'order.payment_pending' ) );
		$this->assertSame( 'bacs', $pending[0]['properties']['payment_method'] );
	}
}
