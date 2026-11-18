<?php
/**
 * Comandos de la Fase 4 con WooCommerce real.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * `order.create_payment_link`: el enlace de pago que manda la bandeja.
 */
final class CommandsPhase4Test extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->connect();
	}

	/**
	 * Ejecuta un comando firmado como el hub.
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
				'request_id' => '01JF' . wp_generate_password( 22, false ),
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

	public function test_crea_el_pedido_pendiente_con_el_precio_de_la_tienda_y_devuelve_el_enlace(): void {
		$paint = $this->product(
			'150000',
			array(
				'name'  => 'Pintura Azul 1 galón',
				'stock' => 5,
			)
		);
		$brush = $this->product( '20000', array( 'name' => 'Brocha 2"' ) );

		[ $status, $body ] = $this->run_command(
			'order.create_payment_link',
			array(
				'items'   => array(
					array(
						'product_id' => (string) $paint->get_id(),
						'qty'        => 2,
					),
					array(
						'product_id' => (string) $brush->get_id(),
						'qty'        => 1,
					),
				),
				'billing' => array(
					'email'      => 'ana@example.com',
					'phone'      => '+573001234567',
					'first_name' => 'Ana',
				),
			)
		);
		$this->assertSame( 200, $status, (string) wp_json_encode( $body ) );
		$data  = $body['data'];
		$order = wc_get_order( (int) $data['order_id'] );
		$this->assertInstanceOf( \WC_Order::class, $order );
		$this->assertSame( 'pending', $order->get_status() );
		$this->assertSame( 'imagina-growth', $order->get_created_via() );
		$this->assertSame( '320000.00', $data['total'] );
		$this->assertSame( 'ana@example.com', $order->get_billing_email() );
		$this->assertSame( $order->get_checkout_payment_url(), $data['url'] );
		$this->assertStringContainsString( 'order-pay', $data['url'] );
		$this->assertCount( 2, $order->get_items() );
	}

	public function test_rechaza_productos_sin_stock_privados_o_cantidades_invalidas(): void {
		$few     = $this->product( '150000', array( 'stock' => 1 ) );
		$private = $this->product( '90000' );
		$private->set_status( 'private' );
		$private->save();
		$before = count( wc_get_orders( array( 'limit' => -1 ) ) );

		[ $s1, $b1 ] = $this->run_command(
			'order.create_payment_link',
			array(
				'items' => array(
					array(
						'product_id' => (string) $few->get_id(),
						'qty'        => 3,
					),
				),
			)
		);
		$this->assertSame( 422, $s1 );
		$this->assertSame( 'out_of_stock', $b1['error']['code'] ?? $b1['code'] ?? null );

		[ $s2 ] = $this->run_command(
			'order.create_payment_link',
			array(
				'items' => array(
					array(
						'product_id' => (string) $private->get_id(),
						'qty'        => 1,
					),
				),
			)
		);
		$this->assertSame( 422, $s2 );

		[ $s3 ] = $this->run_command( 'order.create_payment_link', array( 'items' => array() ) );
		$this->assertSame( 422, $s3 );
		$this->assertCount( $before, wc_get_orders( array( 'limit' => -1 ) ), 'ningún pedido a medias' );
	}
}
