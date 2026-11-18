<?php
/**
 * Carrito restaurable (docs/api-connector.md §6.1) y contrato v3 (`order.cart_token`,
 * `cart.updated.restored_from`).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Collectors\OrderCollector;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Onsite\CartRestore;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Enlaces firmados como los arma el hub (CartRestoreLink + SiteToken::sign).
 */
final class CartRestoreTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->connect();
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		WC()->session->set( CartRestore::SESSION_RESTORE, null );
		WC()->session->set( 'igr_cart_hash', null );
		CartCollector::register();
	}

	public function tear_down(): void {
		WC()->cart->empty_cart();
		CartCollector::reset_token();
		parent::tear_down();
	}

	/**
	 * Token con el mismo código que App\Domain\Identity\SiteToken::sign del hub.
	 *
	 * @param array<string, mixed> $payload Datos.
	 * @param string|null          $secret  Secreto.
	 */
	private function hub_token( array $payload, ?string $secret = null ): string {
		$encoded = rtrim( strtr( base64_encode( (string) json_encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode, WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Igual que el hub.
		return $encoded . '.' . hash_hmac( 'sha256', $encoded, $secret ?? self::SECRET );
	}

	/**
	 * Payload de CartRestoreLink::for().
	 *
	 * @param list<array<string, mixed>> $items Ítems.
	 * @param array<string, mixed>       $extra Cambios.
	 * @return array<string, mixed>
	 */
	private function payload( array $items, array $extra = array() ): array {
		return array_merge(
			array(
				'site'  => self::SITE_ID,
				'cart'  => 'crt_01JE4ZQ8K3M5N7P9R1S2T3V4W6',
				'items' => $items,
				'mode'  => 'replace',
				'exp'   => time() + 7 * DAY_IN_SECONDS,
			),
			$extra
		);
	}

	/**
	 * Cantidades del carrito por producto.
	 *
	 * @return array<int, int>
	 */
	private function cart_qty(): array {
		$out = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$id         = (int) ( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
			$out[ $id ] = (int) $item['quantity'];
		}
		ksort( $out );
		return $out;
	}

	public function test_reemplaza_el_carrito_aplica_el_cupon_y_va_al_checkout_con_el_seguimiento(): void {
		$paint  = $this->product( '141000' );
		$roller = $this->product( '25000', array( 'name' => 'Rodillo' ) );
		$other  = $this->product( '9000', array( 'name' => 'Lija' ) );
		WC()->cart->add_to_cart( $other->get_id(), 1 );
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'vuelve-7k2q9xab' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();

		$token = $this->hub_token(
			$this->payload(
				array(
					array(
						'product_id'   => (string) $paint->get_id(),
						'variation_id' => null,
						'qty'          => 2.0,
					),
					array(
						'product_id' => (string) $roller->get_id(),
						'qty'        => 1,
					),
				),
				array( 'coupon' => 'VUELVE-7K2Q9XAB' )
			)
		);
		$url   = CartRestore::process(
			$token,
			array(
				'igr_cart'     => $token,
				'_igr'         => 'K7Q2Z9XA',
				'utm_source'   => 'igr',
				'utm_medium'   => 'whatsapp',
				'utm_campaign' => 'carrito-abandonado',
				'otro'         => 'no-se-copia',
			)
		);

		$this->assertStringStartsWith( wc_get_checkout_url(), $url );
		$this->assertStringContainsString( '_igr=K7Q2Z9XA', $url );
		$this->assertStringContainsString( 'utm_medium=whatsapp', $url );
		$this->assertStringNotContainsString( 'otro=', $url );
		$this->assertStringNotContainsString( 'igr_cart', $url );
		$expected = array(
			$paint->get_id()  => 2,
			$roller->get_id() => 1,
		);
		ksort( $expected );
		$this->assertSame( $expected, $this->cart_qty(), 'reemplaza: la lija ya no está' );
		$this->assertTrue( WC()->cart->has_discount( 'vuelve-7k2q9xab' ) );
		$this->assertSame( 'crt_01JE4ZQ8K3M5N7P9R1S2T3V4W6', WC()->session->get( CartRestore::SESSION_RESTORE ) );

		// cart.updated lleva restored_from (v3) y cumple el contrato.
		$updated = $this->outbox( 'cart.updated' );
		$this->assertNotEmpty( $updated );
		$last = end( $updated );
		$this->assertSame( 'crt_01JE4ZQ8K3M5N7P9R1S2T3V4W6', $last['properties']['restored_from'] );
		$this->assertSame( EventFactory::SCHEMA_VERSION, $last['schema_version'] );
		$this->assertContract( $this->outbox_raw() );
	}

	public function test_suma_al_carrito_y_omite_lo_que_no_existe_o_no_tiene_stock(): void {
		$paint = $this->product( '141000', array( 'stock' => 3 ) );
		$empty = $this->product( '50000', array( 'name' => 'Agotado' ) );
		wc_update_product_stock( $empty, 0 );
		$mine = $this->product( '9000', array( 'name' => 'Lija' ) );
		WC()->cart->add_to_cart( $mine->get_id(), 1 );

		$token = $this->hub_token(
			$this->payload(
				array(
					array(
						'product_id' => (string) $paint->get_id(),
						'qty'        => 5,
					),
					array(
						'product_id' => (string) $empty->get_id(),
						'qty'        => 1,
					),
					array(
						'product_id' => '99999999',
						'qty'        => 1,
					),
				),
				array( 'mode' => 'merge' )
			)
		);
		$url   = CartRestore::process( $token );
		$this->assertStringStartsWith( wc_get_checkout_url(), $url );
		$expected = array(
			$mine->get_id()  => 1,
			$paint->get_id() => 3, // Tope del stock disponible.
		);
		ksort( $expected );
		$this->assertSame( $expected, $this->cart_qty() );
	}

	public function test_enlaces_invalidos_llevan_a_la_tienda_sin_tocar_el_carrito(): void {
		$paint = $this->product( '141000' );
		$mine  = $this->product( '9000', array( 'name' => 'Lija' ) );
		WC()->cart->add_to_cart( $mine->get_id(), 1 );
		$items = array(
			array(
				'product_id' => (string) $paint->get_id(),
				'qty'        => 1,
			),
		);
		$home  = home_url( '/' );
		$cases = array(
			'firma de otro secreto' => $this->hub_token( $this->payload( $items ), 'sk_otro_secreto_otro_secreto_otro_secreto_1' ),
			'vencido'               => $this->hub_token( $this->payload( $items, array( 'exp' => time() - 60 ) ) ),
			'de otro sitio'         => $this->hub_token( $this->payload( $items, array( 'site' => 'st_01JD000000000000000000000' ) ) ),
			'mal formado'           => 'esto-no-es-un-token',
		);
		foreach ( $cases as $label => $token ) {
			$url = CartRestore::process( $token, array( '_igr' => 'K7Q2Z9XA' ) );
			$this->assertSame( add_query_arg( array( '_igr' => 'K7Q2Z9XA' ), $home ), $url, $label );
		}
		$this->assertSame( array( $mine->get_id() => 1 ), $this->cart_qty(), 'el carrito quedó igual' );

		// Ningún producto disponible: también a la tienda.
		$gone = $this->hub_token(
			$this->payload(
				array(
					array(
						'product_id' => '99999999',
						'qty'        => 1,
					),
				)
			)
		);
		WC()->cart->empty_cart();
		$this->assertSame( $home, CartRestore::process( $gone ) );
	}

	public function test_vale_el_secreto_anterior_durante_la_rotacion(): void {
		$paint = $this->product( '141000' );
		$token = $this->hub_token(
			$this->payload(
				array(
					array(
						'product_id' => (string) $paint->get_id(),
						'qty'        => 1,
					),
				)
			)
		);
		Connection::rotate( 'sk_NuevoSecretoNuevoSecretoNuevoSecreto12345' );
		$this->assertStringStartsWith( wc_get_checkout_url(), CartRestore::process( $token ) );
		$this->assertSame( array( $paint->get_id() => 1 ), $this->cart_qty() );
	}

	public function test_el_pedido_lleva_el_cart_token_del_checkout(): void {
		$this->assertSame( 4, EventFactory::SCHEMA_VERSION );
		$paint = $this->product( '141000' );
		WC()->cart->add_to_cart( $paint->get_id(), 1 );
		$token = CartCollector::current_token();
		$this->assertNotNull( $token, 'agregar al carrito crea el token de la sesión' );

		// Como en el checkout: el contexto se guarda antes de que el recolector vea el pedido.
		$order = $this->order( array( 'status' => 'pending' ) );
		OrderCollector::attach_context( $order );
		$order->save();
		OrderCollector::register();
		$this->assertSame( $token, (string) $order->get_meta( OrderEvents::CART_META ) );
		OrderCollector::on_checkout_processed( $order->get_id(), array(), $order );

		// El pago llega después por el webhook de la pasarela (sin sesión): el token sale de la meta.
		CartCollector::reset_token();
		$this->assertNull( CartCollector::current_token() );
		$fresh = wc_get_order( $order->get_id() );
		$fresh->update_status( 'processing' );

		foreach ( array( 'order.created', 'order.paid' ) as $event ) {
			$envelopes = $this->outbox( $event );
			$this->assertCount( 1, $envelopes, $event );
			$this->assertSame( $token, $envelopes[0]['properties']['order']['cart_token'], $event );
		}
		$this->assertContract( $this->outbox_raw() );

		// Un pedido sin carrito (creado en el admin) no lleva cart_token.
		$admin = $this->order( array( 'status' => 'processing' ) );
		$this->assertArrayNotHasKey( 'cart_token', \ImaginaGrowth\Connector\Events\OrderPayload::from( $admin ) );
	}
}
