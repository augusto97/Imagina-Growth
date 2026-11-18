<?php
/**
 * Contrato de catálogo, clientes, reseñas, carrito y sitio.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Catalog\CatalogCollector;
use ImaginaGrowth\Connector\Catalog\ProductPayload;
use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Collectors\CustomerCollector;
use ImaginaGrowth\Connector\Collectors\ReviewCollector;
use ImaginaGrowth\Connector\Collectors\SiteCollector;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Eventos fuera de pedidos.
 */
final class CatalogCustomerSiteContractTest extends WpTestCase {

	private function factory_igr(): EventFactory {
		return new EventFactory( self::SITE_ID, 'woocommerce' );
	}

	public function test_productos_simple_variable_y_variacion(): void {
		$this->connect();
		$cat    = wp_insert_term( 'Pinturas', 'product_cat' );
		$simple = $this->product( '45000' );
		$simple->set_category_ids( array( (int) $cat['term_id'] ) );
		$simple->save();

		$variable = new \WC_Product_Variable();
		$variable->set_name( 'Vinilo tipo 1' );
		$variable->set_status( 'publish' );
		$variable->save();
		$variation = new \WC_Product_Variation();
		$variation->set_parent_id( $variable->get_id() );
		$variation->set_regular_price( '89000' );
		$variation->set_sale_price( '79000' );
		$variation->save();
		\WC_Product_Variable::sync( $variable->get_id() );
		$variable = wc_get_product( $variable->get_id() );

		$f = $this->factory_igr();
		foreach ( array( $simple, $variable, wc_get_product( $variation->get_id() ) ) as $p ) {
			$this->assertTrue( CatalogCollector::upsert( $f, $p, true, 'import' ) );
		}
		CatalogCollector::category( $f, get_term( (int) $cat['term_id'], 'product_cat' ), true, 'import' );
		$this->assertContract( $this->outbox_raw() );

		$props = ProductPayload::from( $simple );
		$this->assertSame( array( (string) $cat['term_id'] ), $props['category_ids'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{32}$/', $props['hash'] );
		$this->assertSame( 20, $props['stock_qty'] );
		$this->assertFalse( CatalogCollector::upsert( $f, $simple ), 'sin cambios no se reenvía' );
	}

	public function test_cliente_registrado_y_actualizado(): void {
		$this->connect();
		$user = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'luis@example.com',
				'first_name' => 'Luis',
			)
		);
		update_user_meta( $user, 'billing_phone', '310 555 1234' );
		update_user_meta( $user, 'billing_city', 'Pereira' );
		update_user_meta( $user, 'billing_state', 'CO-RIS' );
		update_user_meta( $user, 'billing_country', 'CO' );
		$registered = CustomerCollector::registered( $this->factory_igr(), $user, true );
		$this->assertContract( array( $registered ) );
		$this->assertSame( '+573105551234', $registered['properties']['phone'] );
		$this->assertSame( 'Pereira', $registered['properties']['billing']['city'] );

		$bare = self::factory()->user->create( array( 'role' => 'customer' ) );
		$this->assertContract( array( CustomerCollector::registered( $this->factory_igr(), $bare, true ) ) );

		CustomerCollector::on_register( $user );
		update_user_meta( $user, 'billing_city', 'Manizales' );
		CustomerCollector::on_update( $user );
		$updated = $this->outbox( 'customer.updated' );
		$this->assertCount( 1, $updated );
		$this->assertSame( array( 'billing.city' ), $updated[0]['properties']['changed'] );
		$this->assertContract( $this->outbox_raw( 'customer.updated' ) );
	}

	public function test_resena_verificada(): void {
		$product = $this->product();
		$comment = self::factory()->comment->create(
			array(
				'comment_post_ID'  => $product->get_id(),
				'comment_type'     => 'review',
				'comment_approved' => 1,
				'comment_content'  => 'Excelente cubrimiento',
			)
		);
		update_comment_meta( $comment, 'rating', 5 );
		update_comment_meta( $comment, 'verified', 1 );
		$envelope = ReviewCollector::envelope( $this->factory_igr(), get_comment( $comment ), true );
		$this->assertNotNull( $envelope );
		$this->assertContract( array( $envelope ) );
		$this->assertSame( 5, $envelope['properties']['rating'] );
	}

	public function test_carrito_y_cupon(): void {
		$this->connect();
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();
		$product = $this->product( '12000' );
		CartCollector::register();
		WC()->cart->add_to_cart( $product->get_id(), 3 );
		CartCollector::on_cart_updated();
		$coupon = new \WC_Coupon();
		$coupon->set_code( 'bienvenida10' );
		$coupon->set_discount_type( 'percent' );
		$coupon->set_amount( 10 );
		$coupon->save();
		WC()->cart->apply_coupon( 'bienvenida10' );
		$events = $this->outbox();
		$names  = array_column( $events, 'event' );
		$this->assertContains( 'cart.item_added', $names );
		$this->assertContains( 'cart.updated', $names );
		$this->assertContains( 'coupon.applied', $names );
		$this->assertContract( $this->outbox_raw() );
		WC()->cart->empty_cart();
	}

	public function test_latido_y_cambio_del_sitio(): void {
		$this->connect();
		SiteCollector::heartbeat();
		$beat = $this->outbox( 'site.heartbeat' );
		$this->assertCount( 1, $beat );
		$this->assertContract( $this->outbox_raw( 'site.heartbeat' ) );
		$this->assertTrue( $beat[0]['properties']['capabilities']['woocommerce'] );

		update_option(
			'igr_versions',
			array(
				'wp'            => '6.0',
				'plugins'       => array( 'woocommerce' => '9.0.0' ),
				'theme'         => 'x',
				'theme_version' => '1',
				'checked_at'    => 0,
			),
			false
		);
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );
		SiteCollector::detect_changes();
		$changes = $this->outbox( 'site.changed' );
		$this->assertNotEmpty( $changes );
		$this->assertContract( $this->outbox_raw( 'site.changed' ) );
	}
}
