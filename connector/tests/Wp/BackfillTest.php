<?php
/**
 * Importación histórica y conciliación (docs/api-connector.md §1.2 y §5.3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Backfill\Reconciliation;
use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * La importación envía todo con source.type=import y los mismos event_id que en vivo.
 */
final class BackfillTest extends WpTestCase {

	public function test_importa_todo_y_concilia_por_estado(): void {
		$this->connect();
		$user = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'cliente@example.com',
			)
		);
		$paid = $this->order(
			array(
				'status'   => 'completed',
				'customer' => $user,
			)
		);
		$paid->set_date_paid( time() - 3600 );
		$paid->save();
		$cod    = $this->order(
			array(
				'status'  => 'processing',
				'gateway' => 'cod',
			)
		);
		$failed = $this->order( array( 'status' => 'failed' ) );
		$draft  = $this->order( array( 'status' => 'checkout-draft' ) );
		$refund = wc_create_refund(
			array(
				'order_id' => $paid->get_id(),
				'amount'   => '10000',
			)
		);
		$this->assertInstanceOf( \WC_Order_Refund::class, $refund );

		$this->assertTrue( Runner::start( null, true, array( 'delay' => 0 ) ) );
		$guard = 0;
		do {
			$state = Runner::step( false );
		} while ( in_array( $state['status'], array( 'queued', 'running' ), true ) && ++$guard < 50 );

		$this->assertSame( 'completed', $state['status'], (string) ( $state['error'] ?? '' ) );
		$this->assertArrayNotHasKey( 'warning', $state, wp_json_encode( $state['diff'] ?? array() ) );

		$events = $this->outbox();
		$this->assertContract( $this->outbox_raw() );
		foreach ( $events as $e ) {
			$this->assertSame( 'import', $e['source']['type'], $e['event'] );
		}
		$created = array_column( $this->outbox( 'order.created' ), 'event_id' );
		$this->assertCount( 3, $created, 'el borrador del checkout no se importa' );
		$f = new OrderEvents( new EventFactory( self::SITE_ID, 'woocommerce' ) );
		$this->assertContains( $f->created( wc_get_order( $paid->get_id() ) )['event_id'], $created, 'mismo event_id que en vivo' );
		$this->assertCount( 1, $this->outbox( 'order.paid' ), 'contraentrega en proceso y fallido no cuentan como pagados' );
		$this->assertCount( 1, $this->outbox( 'order.refunded' ) );
		$this->assertCount( 1, $this->outbox( 'customer.registered' ) );
		$this->assertGreaterThanOrEqual( 4, count( $this->outbox( 'catalog.product_upserted' ) ) );

		$rec = $state['reconciliation'];
		$this->assertSame( 1, $rec['orders_by_status']['completed']['count'] );
		$this->assertSame( 1, $rec['orders_by_status']['processing']['count'] );
		$this->assertSame( 1, $rec['orders_by_status']['failed']['count'] );
		$this->assertArrayNotHasKey( 'checkout-draft', $rec['orders_by_status'] );
		$this->assertSame( Money::format( $paid->get_total() ), $rec['orders_by_status']['completed']['total'] );
		$this->assertSame(
			array(
				'count' => 1,
				'total' => '10000.00',
			),
			$rec['refunds']
		);
		$this->assertSame( 3, $state['entities']['orders']['sent'] );

		// El progreso se informa firmado con PUT /v1/s/backfill e incluye la conciliación.
		$puts = array_values( array_filter( $this->http, static fn( $r ) => 'PUT' === $r['method'] ) );
		$this->assertNotEmpty( $puts );
		$last = end( $puts );
		$this->assertSame( 'http://hub.test/v1/s/backfill', $last['url'] );
		$this->assertTrue( Hmac::verify( $last['headers']['X-IGR-Signature'], $last['body'], array( self::SECRET ) ) );
		$body = json_decode( $last['body'], true );
		$this->assertSame( 'completed', $body['status'] );
		$this->assertSame( $rec['orders_by_status'], $body['reconciliation']['orders_by_status'] );
		unset( $draft, $cod, $failed );
	}

	public function test_conciliacion_respeta_la_fecha_inicial(): void {
		$old = $this->order( array( 'status' => 'completed' ) );
		$old->set_date_created( strtotime( '-30 months' ) );
		$old->save();
		$new = $this->order( array( 'status' => 'completed' ) );
		$rec = Reconciliation::compute( gmdate( 'Y-m-d', strtotime( '-24 months' ) ) );
		$this->assertSame( 1, $rec['orders']['count'] );
		$this->assertSame( Money::format( $new->get_total() ), $rec['orders']['total'] );
	}
}
