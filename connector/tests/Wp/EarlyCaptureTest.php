<?php
/**
 * Captura temprana del checkout (`checkout.contact_captured`, contrato v6; eventos.md §4.2, cumplimiento.md §3.2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Collectors\EarlyCapture;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Checkout clásico (`update_order_review`) y de bloques (Store API), sin HTTP en la petición.
 */
final class EarlyCaptureTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->connect();
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		WC()->session->set( EarlyCapture::SESSION, null );
		CartCollector::reset_token();
		EarlyCapture::register();
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Outbox::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$_COOKIE['_igr_aid'] = 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa';
	}

	public function tear_down(): void {
		unset( $_COOKIE['_igr_aid'] );
		WC()->cart->empty_cart();
		CartCollector::reset_token();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * Enciende (o apaga) la opción como si llegara de `/v1/s/config`.
	 *
	 * @param bool $on Encendida.
	 */
	private function early_capture( bool $on ): void {
		update_option( RemoteConfig::OPTION, array( 'settings' => array( 'early_capture' => $on ) ), false );
		RemoteConfig::reset();
	}

	/**
	 * Lo que WooCommerce manda en `post_data` al actualizar el checkout clásico.
	 *
	 * @param string $email   Correo.
	 * @param string $phone   Teléfono.
	 * @param string $country País.
	 */
	private function classic( string $email, string $phone = '', string $country = 'CO' ): void {
		do_action(
			'woocommerce_checkout_update_order_review',
			http_build_query(
				array(
					'billing_first_name' => 'Ana',
					'billing_email'      => $email,
					'billing_phone'      => $phone,
					'billing_country'    => $country,
				)
			)
		);
	}

	/**
	 * Filas del outbox de la captura.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function rows(): array {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT event_id, status, next_attempt_at, payload FROM ' . Outbox::table() . ' WHERE event = %s ORDER BY id', EarlyCapture::EVENT ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	public function test_apagada_por_defecto_no_captura_nada(): void {
		$this->classic( 'ana@example.com', '3001234567' );
		WC()->customer->set_billing_email( 'ana@example.com' );
		do_action( 'woocommerce_store_api_cart_update_customer_from_request', WC()->customer, new \WP_REST_Request( 'POST', '/wc/store/v1/cart/update-customer' ) );
		$this->assertSame( array(), $this->rows() );
		$fields = apply_filters( 'woocommerce_checkout_fields', array( 'billing' => array( 'billing_email' => array( 'label' => 'Correo' ) ) ) );
		$this->assertArrayNotHasKey( 'description', $fields['billing']['billing_email'], 'sin la opción no hay aviso' );
	}

	public function test_checkout_clasico_una_fila_firmable_con_el_dato_en_identity_y_sin_http(): void {
		$this->early_capture( true );
		global $wpdb;
		$queries = $wpdb->num_queries;
		$start   = microtime( true );
		$this->classic( 'Ana@Example.com ', '300 123 4567' );
		$ms = ( microtime( true ) - $start ) * 1000;
		$this->assertLessThanOrEqual( 2, $wpdb->num_queries - $queries, 'una inserción en el outbox (y como mucho la opción de configuración)' );
		$this->assertLessThan( 50, $ms, 'muy por debajo del presupuesto del checkout' );
		$this->assertSame( array(), $this->http, 'nada de HTTP en la petición del visitante' );

		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertGreaterThan( gmdate( 'Y-m-d H:i:s', time() + 10 ), $rows[0]['next_attempt_at'], 'sale después del debounce' );
		$this->assertContract( array( $rows[0]['payload'] ) );
		$event = json_decode( (string) $rows[0]['payload'], true );
		$this->assertSame( 6, $event['schema_version'] );
		$this->assertSame( 'server', $event['source']['type'] );
		$this->assertSame( 'ana@example.com', $event['identity']['email'] );
		$this->assertSame( '+573001234567', $event['identity']['phone'] );
		$this->assertSame( 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa', $event['identity']['anonymous_id'] );
		$this->assertSame(
			array(
				'has_email'  => true,
				'has_phone'  => true,
				'cart_token' => CartCollector::current_token(),
				'checkout'   => 'classic',
			),
			$event['properties']
		);
		$this->assertStringNotContainsString( 'example.com', (string) wp_json_encode( $event['properties'] ) );
		$this->assertSame( 'checkout', $event['context']['page_type'] );
	}

	public function test_debounce_reemplaza_la_fila_pendiente_y_una_vez_por_carrito_y_dato(): void {
		$this->early_capture( true );
		$this->classic( 'ana@example.co' );
		$this->classic( 'ana@example.com' );
		$rows = $this->rows();
		$this->assertCount( 1, $rows, 'dentro del debounce se reemplaza, no se suma' );
		$this->assertSame( 'ana@example.com', json_decode( (string) $rows[0]['payload'], true )['identity']['email'] );

		$this->classic( 'ana@example.com' );
		$this->assertCount( 1, $this->rows(), 'el mismo dato del mismo carrito no se repite' );

		// Ya salió: otro dato es una captura nueva.
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . ' SET status = %s WHERE event = %s', Outbox::STATUS_SENT, EarlyCapture::EVENT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->classic( 'ana@example.com', '3001234567' );
		$rows = $this->rows();
		$this->assertCount( 2, $rows );
		$this->assertNotSame( $rows[0]['event_id'], $rows[1]['event_id'] );
		$this->assertContract( array_column( $rows, 'payload' ) );
	}

	public function test_un_dato_a_medio_escribir_o_invalido_no_cuenta(): void {
		$this->early_capture( true );
		foreach ( array( array( 'ana@', '' ), array( 'ana@gmail.c', '' ), array( '', '300 12' ), array( 'no es correo', '12345' ) ) as $pair ) {
			$this->classic( $pair[0], $pair[1] );
		}
		$this->assertSame( array(), $this->rows() );
		// Teléfono de México con el país de facturación.
		$this->classic( '', '55 1234 5678', 'MX' );
		$this->assertSame( '+525512345678', json_decode( (string) $this->rows()[0]['payload'], true )['identity']['phone'] );
	}

	public function test_con_sesion_iniciada_o_carrito_vacio_no_captura(): void {
		$this->early_capture( true );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'customer' ) ) );
		$this->classic( 'ana@example.com' );
		wp_set_current_user( 0 );
		WC()->cart->empty_cart();
		$this->classic( 'ana@example.com' );
		$this->assertSame( array(), $this->rows() );
	}

	public function test_checkout_de_bloques_por_la_store_api(): void {
		$this->early_capture( true );
		$request = new \WP_REST_Request( 'POST', '/wc/store/v1/cart/update-customer' );
		$request->set_header( 'Nonce', wp_create_nonce( 'wc_store_api' ) );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'billing_address' => array(
						'email'   => 'bloques@example.com',
						'phone'   => '3009876543',
						'country' => 'CO',
					),
				)
			)
		);
		$response = rest_do_request( $request );
		$this->assertSame( 200, $response->get_status(), 'la ruta real de la Store API' );
		$this->assertSame( array(), $this->http );
		$rows = $this->rows();
		$this->assertCount( 1, $rows );
		$this->assertContract( array( $rows[0]['payload'] ) );
		$event = json_decode( (string) $rows[0]['payload'], true );
		$this->assertSame( 'bloques@example.com', $event['identity']['email'] );
		$this->assertSame( '+573009876543', $event['identity']['phone'] );
		$this->assertSame( 'blocks', $event['properties']['checkout'] );
	}

	public function test_aviso_junto_al_campo_y_scripts_del_checkout(): void {
		$this->early_capture( true );
		$fields = apply_filters(
			'woocommerce_checkout_fields',
			array(
				'billing' => array(
					'billing_email' => array( 'label' => 'Correo' ),
					'billing_phone' => array( 'label' => 'Teléfono' ),
				),
			)
		);
		$this->assertSame( EarlyCapture::notice(), $fields['billing']['billing_email']['description'] );
		$this->assertArrayNotHasKey( 'description', $fields['billing']['billing_phone'], 'un solo aviso' );
		$this->assertStringContainsString( 'update_checkout', EarlyCapture::classic_script() );
		$this->assertStringContainsString( (string) wp_json_encode( EarlyCapture::notice() ), EarlyCapture::blocks_script() );
	}

	public function test_los_eventos_del_carrito_de_un_invitado_no_llevan_el_correo_de_la_sesion(): void {
		WC()->customer->set_billing_email( 'escrito@example.com' );
		CartCollector::register();
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		$added = $this->outbox( 'cart.item_added' );
		$this->assertNotEmpty( $added );
		$this->assertArrayNotHasKey( 'email', (array) end( $added )['identity'] );

		$user = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'cuenta@example.com',
			)
		);
		wp_set_current_user( $user );
		WC()->customer->set_billing_email( 'cuenta@example.com' );
		WC()->cart->add_to_cart( $this->product()->get_id(), 1 );
		$added = $this->outbox( 'cart.item_added' );
		$this->assertSame( 'cuenta@example.com', end( $added )['identity']['email'] );
		$this->assertSame( 'wc:' . $user, end( $added )['identity']['customer_ref'] );
	}
}
