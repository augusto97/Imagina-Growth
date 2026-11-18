<?php
/**
 * Comandos de la Fase 2 (docs/api-connector.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Commands\Handlers\CouponCreate;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * coupon.create, coupon.disable, order.add_note, product.lookup y order.status con WooCommerce real.
 */
final class CommandsPhase2Test extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->connect();
	}

	/**
	 * Ejecuta un comando firmado como el hub y devuelve [estado HTTP, cuerpo].
	 *
	 * @param string               $command Comando.
	 * @param array<string, mixed> $args    Argumentos.
	 * @return array{0: int, 1: array<string, mixed>}
	 */
	private function run_command( string $command, array $args = array() ): array {
		$body    = (string) wp_json_encode(
			array(
				'command'    => $command,
				'args'       => (object) $args,
				'request_id' => '01JE' . wp_generate_password( 22, false ),
				'nonce'      => bin2hex( random_bytes( 16 ) ),
				'issued_at'  => gmdate( 'c' ),
			)
		);
		$request = new \WP_REST_Request( 'POST', '/' . CommandsController::NAMESPACE_V1 . '/commands' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-igr-signature', Hmac::header( self::SECRET, $body ) );
		$response = rest_get_server()->dispatch( $request );
		return array( $response->get_status(), (array) json_decode( (string) wp_json_encode( $response->get_data() ), true ) );
	}

	/**
	 * Argumentos de cupón como los manda SiteCouponIssuer.
	 *
	 * @param array<string, mixed> $extra Cambios.
	 * @return array<string, mixed>
	 */
	private function coupon_args( array $extra = array() ): array {
		return array_merge(
			array(
				'code'               => 'VUELVE-7K2Q9XAB',
				'kind'               => 'percent',
				'amount'             => '10.00',
				'expires_at'         => '2030-01-15',
				'usage_limit'        => 1,
				'email_restrictions' => array( 'Ana@Example.com' ),
				'individual_use'     => true,
				'minimum_amount'     => '100000.00',
				'free_shipping'      => false,
				'description'        => 'Flujo «Carrito abandonado»',
			),
			$extra
		);
	}

	public function test_coupon_create_crea_el_cupon_y_es_idempotente(): void {
		[ $status, $body ] = $this->run_command( 'coupon.create', $this->coupon_args() );
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$this->assertTrue( $body['ok'] );
		$id     = (int) $body['data']['coupon_id'];
		$coupon = new \WC_Coupon( $id );
		$this->assertSame( 'vuelve-7k2q9xab', strtolower( $coupon->get_code() ) );
		$this->assertSame( 'percent', $coupon->get_discount_type() );
		$this->assertEquals( 10, (float) $coupon->get_amount() );
		$this->assertSame( 1, $coupon->get_usage_limit() );
		$this->assertTrue( $coupon->get_individual_use() );
		$this->assertSame( array( 'ana@example.com' ), $coupon->get_email_restrictions() );
		$this->assertEquals( 100000, (float) $coupon->get_minimum_amount() );
		$this->assertSame( '1', (string) $coupon->get_meta( CouponCreate::META_FLAG ) );
		// Vale hasta el final del día indicado en la hora de la tienda.
		$this->assertSame( '2030-01-15 23:59:59', $coupon->get_date_expires()->setTimezone( Dates::tz() )->format( 'Y-m-d H:i:s' ) );

		// Mismo código y mismos datos: el mismo cupón (un reintento del hub no duplica).
		[ $again, $same ] = $this->run_command( 'coupon.create', $this->coupon_args() );
		$this->assertSame( 200, $again );
		$this->assertSame( (string) $id, $same['data']['coupon_id'] );

		// Mismo código con otros datos: 409.
		[ $conflict, $err ] = $this->run_command( 'coupon.create', $this->coupon_args( array( 'amount' => '15.00' ) ) );
		$this->assertSame( 409, $conflict );
		$this->assertSame( 'coupon_exists', $err['error']['code'] );
	}

	public function test_coupon_create_envio_gratis_monto_fijo_y_validaciones(): void {
		[ $status, $body ] = $this->run_command(
			'coupon.create',
			array(
				'code'          => 'ENVIO-GRATIS1',
				'kind'          => 'free_shipping',
				'product_ids'   => array( '88' ),
				'category_ids'  => array( '12' ),
				'free_shipping' => true,
			)
		);
		$this->assertSame( 200, $status );
		$coupon = new \WC_Coupon( (int) $body['data']['coupon_id'] );
		$this->assertTrue( $coupon->get_free_shipping() );
		$this->assertEquals( 0, (float) $coupon->get_amount() );
		$this->assertSame( array( 88 ), $coupon->get_product_ids() );
		$this->assertSame( array( 12 ), $coupon->get_product_categories() );
		$this->assertNull( $coupon->get_date_expires() );

		[ $fixed ] = $this->run_command(
			'coupon.create',
			$this->coupon_args(
				array(
					'code'   => 'FIJO-20000',
					'kind'   => 'fixed_cart',
					'amount' => '20000',
				)
			)
		);
		$this->assertSame( 200, $fixed );

		foreach ( array(
			array( 'amount' => '120.00' ),
			array( 'code' => 'malo con espacios' ),
			array( 'kind' => 'bogof' ),
			array( 'amount' => '0' ),
			array( 'expires_at' => '2030-02-31' ),
			array( 'email_restrictions' => array( 'no-es-correo' ) ),
			array( 'product_ids' => array( 'x' ) ),
		) as $bad ) {
			[ $code, $err ] = $this->run_command( 'coupon.create', $this->coupon_args( $bad + array( 'code' => 'VALIDO-' . wp_generate_password( 6, false, false ) ) ) );
			$this->assertSame( 422, $code, (string) wp_json_encode( $bad ) );
			$this->assertSame( 'invalid_args', $err['error']['code'] );
		}

		// Un cupón del dueño con ese código nunca se pisa.
		$own = new \WC_Coupon();
		$own->set_code( 'navidad2026' );
		$own->set_amount( 5 );
		$own->save();
		[ $exists ] = $this->run_command( 'coupon.create', $this->coupon_args( array( 'code' => 'NAVIDAD2026' ) ) );
		$this->assertSame( 409, $exists );
	}

	public function test_coupon_disable_solo_los_de_imagina_growth(): void {
		[ , $created ]     = $this->run_command( 'coupon.create', $this->coupon_args( array( 'code' => 'APAGAR-1234' ) ) );
		[ $status, $body ] = $this->run_command( 'coupon.disable', array( 'code' => 'APAGAR-1234' ) );
		$this->assertSame( 200, $status );
		$this->assertTrue( $body['data']['disabled'] );
		$this->assertSame( 'draft', get_post_status( (int) $created['data']['coupon_id'] ) );

		[ $missing, $err ] = $this->run_command( 'coupon.disable', array( 'code' => 'NO-EXISTE-99' ) );
		$this->assertSame( 404, $missing );
		$this->assertSame( 'not_found', $err['error']['code'] );

		$own = new \WC_Coupon();
		$own->set_code( 'propio2026' );
		$own->set_amount( 5 );
		$own->save();
		[ $foreign, $err2 ] = $this->run_command( 'coupon.disable', array( 'code' => 'propio2026' ) );
		$this->assertSame( 409, $foreign );
		$this->assertSame( 'coupon_not_managed', $err2['error']['code'] );
		$this->assertSame( 'publish', get_post_status( $own->get_id() ) );
	}

	public function test_order_add_note_privada_y_al_cliente(): void {
		$order             = $this->order( array( 'status' => 'processing' ) );
		[ $status, $body ] = $this->run_command(
			'order.add_note',
			array(
				'order_id' => (string) $order->get_id(),
				'note'     => 'Pedido recuperado por Imagina Growth',
			)
		);
		$this->assertSame( 200, $status );
		$note = wc_get_order_note( (int) $body['data']['note_id'] );
		$this->assertSame( 'Pedido recuperado por Imagina Growth', $note->content );
		$this->assertFalse( $note->customer_note );

		[ , $public ] = $this->run_command(
			'order.add_note',
			array(
				'order_id'      => (string) $order->get_id(),
				'note'          => 'Tu pedido va en camino',
				'customer_note' => true,
			)
		);
		$this->assertTrue( wc_get_order_note( (int) $public['data']['note_id'] )->customer_note );

		[ $missing ] = $this->run_command(
			'order.add_note',
			array(
				'order_id' => '99999999',
				'note'     => 'x',
			)
		);
		$this->assertSame( 404, $missing );
		[ $bad ] = $this->run_command( 'order.add_note', array( 'order_id' => (string) $order->get_id() ) );
		$this->assertSame( 422, $bad );
	}

	public function test_product_lookup_precio_y_stock_en_vivo(): void {
		$paint = $this->product(
			'150000',
			array(
				'name'  => 'Pintura Azul 1 galón',
				'stock' => 7,
			)
		);
		$paint->set_sale_price( '135000' );
		$paint->save();
		$private = $this->product( '90000', array( 'name' => 'Pintura privada' ) );
		$private->set_status( 'private' );
		$private->save();

		[ $status, $body ] = $this->run_command( 'product.lookup', array( 'ids' => array( (string) $paint->get_id(), (string) $private->get_id(), '99999999' ) ) );
		$this->assertSame( 200, $status );
		$this->assertCount( 1, $body['data']['products'], 'nunca devuelve productos privados ni inexistentes' );
		$p = $body['data']['products'][0];
		$this->assertSame( (string) $paint->get_id(), $p['id'] );
		$this->assertSame( '135000.00', $p['price'] );
		$this->assertSame( '150000.00', $p['regular_price'] );
		$this->assertSame( '135000.00', $p['sale_price'] );
		$this->assertSame( 'instock', $p['stock_status'] );
		$this->assertSame( 7, $p['stock_qty'] );
		$this->assertStringStartsWith( 'http', $p['url'] );

		// El stock es el de ahora mismo.
		wc_update_product_stock( $paint, 0 );
		[ , $after ] = $this->run_command( 'product.lookup', array( 'ids' => array( (string) $paint->get_id() ) ) );
		$this->assertSame( 'outofstock', $after['data']['products'][0]['stock_status'] );

		// Búsqueda y variaciones.
		$variable = new \WC_Product_Variable();
		$variable->set_name( 'Rodillo profesional' );
		$variable->set_status( 'publish' );
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Tamaño' );
		$attr->set_options( array( '9 pulgadas', '12 pulgadas' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$variable->set_attributes( array( $attr ) );
		$variable->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_attributes( array( 'tamano' => '9 pulgadas' ) );
		$variation->set_regular_price( '25000' );
		$variation->set_status( 'publish' );
		$variation->save();
		\WC_Product_Variable::sync( $variable->get_id() );

		[ $found, $search ] = $this->run_command(
			'product.lookup',
			array(
				'search' => 'Rodillo',
				'limit'  => 5,
			)
		);
		$this->assertSame( 200, $found );
		$names = array_column( $search['data']['products'], 'name' );
		$this->assertContains( 'Rodillo profesional', $names );
		$roller = $search['data']['products'][ array_search( 'Rodillo profesional', $names, true ) ];
		$this->assertCount( 1, $roller['variations'] );
		$this->assertSame( '25000.00', $roller['variations'][0]['price'] );

		[ $none ] = $this->run_command( 'product.lookup', array() );
		$this->assertSame( 422, $none );
		[ $too_many ] = $this->run_command(
			'product.lookup',
			array(
				'search' => 'x',
				'limit'  => 500,
			)
		);
		$this->assertSame( 422, $too_many );
	}

	public function test_order_status_solo_con_el_dato_que_coincide(): void {
		$order = $this->order(
			array(
				'status' => 'processing',
				'email'  => 'ana@example.com',
				'phone'  => '300 123 4567',
			)
		);
		$order->update_meta_data(
			'_wc_shipment_tracking_items',
			array(
				array(
					'tracking_provider' => 'Servientrega',
					'tracking_number'   => '123456789',
				),
			)
		);
		$order->save();
		$number = (string) $order->get_order_number();

		[ $status, $body ] = $this->run_command(
			'order.status',
			array(
				'number' => $number,
				'email'  => ' ANA@example.com ',
			)
		);
		$this->assertSame( 200, $status );
		$this->assertSame( 'processing', $body['data']['status'] );
		$this->assertSame( wc_get_order_status_name( 'processing' ), $body['data']['status_label'] );
		$this->assertSame( 'COP', $body['data']['currency'] );
		$this->assertSame( '123456789', $body['data']['tracking'][0]['number'] );
		$this->assertArrayNotHasKey( 'billing', $body['data'] );

		// El teléfono en otro formato también sirve.
		[ $by_phone ] = $this->run_command(
			'order.status',
			array(
				'number' => '#' . $number,
				'phone'  => '+57 300 1234567',
			)
		);
		$this->assertSame( 200, $by_phone );

		// Un dato que no coincide responde igual que un pedido inexistente.
		[ $wrong, $err_wrong ]     = $this->run_command(
			'order.status',
			array(
				'number' => $number,
				'email'  => 'otra@example.com',
			)
		);
		[ $missing, $err_missing ] = $this->run_command(
			'order.status',
			array(
				'number' => '99999999',
				'email'  => 'ana@example.com',
			)
		);
		$this->assertSame( 404, $wrong );
		$this->assertSame( 404, $missing );
		$this->assertSame( $err_missing['error'], $err_wrong['error'] );

		[ $no_data ] = $this->run_command( 'order.status', array( 'number' => $number ) );
		$this->assertSame( 422, $no_data );
	}
}
