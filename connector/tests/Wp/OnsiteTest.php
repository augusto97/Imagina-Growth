<?php
/**
 * En el sitio (Fase 3): slots, bloques, shortcodes, Mi cuenta, saldo y referidos (connector/CLAUDE.md §10).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\OrderCollector;
use ImaginaGrowth\Connector\Commands\Handlers\CouponCreate;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Onsite\Account;
use ImaginaGrowth\Connector\Onsite\Blocks;
use ImaginaGrowth\Connector\Onsite\Shortcodes;
use ImaginaGrowth\Connector\Onsite\Slots;
use ImaginaGrowth\Connector\Onsite\Store;
use ImaginaGrowth\Connector\Onsite\Wallet;
use ImaginaGrowth\Connector\Settings;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Slots cacheables con espacio reservado y las piezas que llaman al hub solo por acción de la persona.
 */
final class OnsiteTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->connect();
		Shortcodes::register();
		Blocks::register_blocks();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );
		$_COOKIE = array();
		parent::tear_down();
	}

	/**
	 * Configuración del hub con los módulos de la Fase 3 activos.
	 *
	 * @param array<string, bool> $onsite Módulos.
	 */
	private function hub_modules( array $onsite ): void {
		update_option( RemoteConfig::OPTION, array( 'onsite' => $onsite ), false );
		RemoteConfig::reset();
		TrackerConfig::rebuild();
	}

	/**
	 * `data-igr-ctx` de un slot.
	 *
	 * @param string $html HTML.
	 * @return array<string, mixed>
	 */
	private function ctx( string $html ): array {
		$this->assertSame( 1, preg_match( '/data-igr-ctx="([^"]*)"/', $html, $m ), $html );
		return (array) json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true );
	}

	public function test_shortcodes_y_bloques_pintan_slots_con_espacio_reservado(): void {
		$form = do_shortcode( '[igr_form id="wg_01JD3QH0POPUP"]' );
		$this->assertStringContainsString( 'data-igr-slot="form"', $form );
		$this->assertStringContainsString( 'min-height:320px', $form );
		$this->assertSame( array( 'widgetId' => 'wg_01JD3QH0POPUP' ), $this->ctx( $form ) );
		$this->assertSame( '', do_shortcode( '[igr_form id="<script>"]' ), 'un ID inválido no pinta nada' );

		$reco = do_shortcode( '[igr_reco type="best_sellers_category" category_id="12" limit="6" title="Lo más vendido"]' );
		$this->assertSame(
			array(
				'kind'       => 'best_sellers_category',
				'limit'      => 6,
				'categoryId' => '12',
				'title'      => 'Lo más vendido',
			),
			$this->ctx( $reco )
		);
		$this->assertSame( 'trending', $this->ctx( do_shortcode( '[igr_reco type="cualquiera"]' ) )['kind'] );

		$reviews = do_shortcode( '[igr_reviews layout="carousel" product_id="88"]' );
		$this->assertStringContainsString( 'min-height:300px', $reviews );
		$this->assertSame(
			array(
				'layout'    => 'carousel',
				'productId' => '88',
			),
			$this->ctx( $reviews )
		);
		$this->assertStringContainsString( 'data-igr-slot="quiz"', do_shortcode( '[igr_quiz id="wg_Q1"]' ) );
		$this->assertStringContainsString( 'data-igr-slot="loyalty"', do_shortcode( '[igr_loyalty]' ) );

		// El bloque pinta exactamente lo mismo que el shortcode.
		$block = render_block(
			array(
				'blockName'    => 'imagina-growth/recommendations',
				'attrs'        => array(
					'type'  => 'trending',
					'limit' => 4,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertSame( do_shortcode( '[igr_reco type="trending" limit="4"]' ), $block );
		foreach ( array( 'form', 'quiz', 'recommendations', 'reviews', 'loyalty' ) as $name ) {
			$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'imagina-growth/' . $name ) );
		}

		// Sin conexión no se pinta nada (pero el shortcode no queda como texto).
		\ImaginaGrowth\Connector\Connection\Connection::forget();
		TrackerConfig::rebuild();
		$this->assertSame( '', do_shortcode( '[igr_form id="wg_01JD3QH0POPUP"]' ) );
	}

	public function test_en_la_ficha_toma_el_producto_y_el_html_no_depende_del_visitante(): void {
		$product = $this->product( '150000' );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$_COOKIE = array();
		$a       = do_shortcode( '[igr_reco type="bought_together"]' ) . do_shortcode( '[igr_reviews]' );
		$_COOKIE = array(
			'_igr_aid'     => 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa',
			'_igr_src'     => '{"l":{"referral_code":"ANA7K2"}}',
			'wordpress_x'  => '1',
			'woocommerce_' => '1',
		);
		$b       = do_shortcode( '[igr_reco type="bought_together"]' ) . do_shortcode( '[igr_reviews]' );
		$this->assertSame( $a, $b );
		$this->assertSame( (string) $product->get_id(), $this->ctx( do_shortcode( '[igr_reco type="bought_together"]' ) )['productId'] );
		$this->assertSame( (string) $product->get_id(), $this->ctx( do_shortcode( '[igr_reviews]' ) )['productId'] );
	}

	public function test_avisame_y_puntos_en_la_ficha_segun_los_modulos_del_hub(): void {
		$sold_out = $this->product( '89900', array( 'stock' => 0 ) );
		$sold_out->set_stock_status( 'outofstock' );
		$sold_out->save();
		$in_stock = $this->product( '120000' );
		$render   = static function ( \WC_Product $p, string $method ): string {
			$GLOBALS['product'] = $p; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Como en la plantilla de WooCommerce.
			ob_start();
			Store::$method();
			return (string) ob_get_clean();
		};

		// Sin fidelización en el hub: avísame sí, puntos no.
		$this->hub_modules( array() );
		$watch = $render( $sold_out, 'product_watch' );
		$this->assertStringContainsString( 'data-igr-slot="bis"', $watch );
		$this->assertSame(
			array(
				'productId' => (string) $sold_out->get_id(),
				'kind'      => 'back_in_stock',
			),
			$this->ctx( $watch )
		);
		$this->assertStringContainsString( 'min-height:44px', $watch );
		$this->assertSame( '', $render( $in_stock, 'product_watch' ), '«baja de precio» está apagado por defecto' );
		$this->assertSame( '', $render( $in_stock, 'product_points' ) );

		Settings::save( array( 'price_drop' => true ) );
		$this->hub_modules( array( 'loyalty' => true ) );
		$this->assertSame( 'price_drop', $this->ctx( $render( $in_stock, 'product_watch' ) )['kind'] );
		$points = $render( $in_stock, 'product_points' );
		$this->assertSame(
			array(
				'productId' => (string) $in_stock->get_id(),
				'price'     => '120000.00',
			),
			$this->ctx( $points )
		);

		// El hub apaga los avisos de stock: no hay slot.
		$this->hub_modules( array( 'product_watch' => false ) );
		$this->assertSame( '', $render( $sold_out, 'product_watch' ) );
	}

	public function test_mis_recompensas_en_mi_cuenta(): void {
		$this->hub_modules( array( 'loyalty' => true ) );
		$this->assertSame(
			array(
				'orders'      => 'orders',
				'recompensas' => 'recompensas',
			),
			Account::query_vars( array( 'orders' => 'orders' ) )
		);
		$menu = Account::menu(
			array(
				'dashboard'       => 'Escritorio',
				'orders'          => 'Pedidos',
				'customer-logout' => 'Salir',
			)
		);
		$this->assertSame( array( 'dashboard', 'orders', 'recompensas', 'customer-logout' ), array_keys( $menu ) );
		ob_start();
		Account::content();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-igr-slot="banner"', $html );
		$this->assertStringContainsString( 'data-igr-slot="loyalty"', $html );
		$this->assertSame( 'Mis recompensas', Account::title() );
	}

	public function test_usar_saldo_pide_el_cupon_al_hub_y_lo_aplica(): void {
		$this->hub_modules(
			array(
				'loyalty' => true,
				'wallet'  => true,
			)
		);
		WC()->frontend_includes();
		WC()->initialize_session();
		WC()->initialize_cart();
		WC()->cart->empty_cart();
		$user = self::factory()->user->create(
			array(
				'role'       => 'customer',
				'user_email' => 'ana@example.com',
			)
		);
		wp_set_current_user( $user );
		$this->assertStringContainsString( 'data-igr-slot="wallet"', Wallet::html() );
		$this->assertStringContainsString( 'wc-ajax=igr_wallet_redeem', Wallet::html() );

		WC()->cart->add_to_cart( $this->product( '282000' )->get_id(), 1 );
		WC()->cart->calculate_totals();
		// El hub crea el cupón con coupon.create antes de responder (api-connector.md §5.5).
		( new CouponCreate() )->handle(
			array(
				'code'               => 'SALDO-7K2Q9XAB',
				'kind'               => 'fixed_cart',
				'amount'             => '35000.00',
				'expires_at'         => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
				'usage_limit'        => 1,
				'email_restrictions' => array( 'ana@example.com' ),
				'source'             => 'wallet',
			)
		);
		$timeouts = array();
		add_filter(
			'http_request_args',
			static function ( array $args ) use ( &$timeouts ): array {
				$timeouts[] = $args['timeout'];
				return $args;
			}
		);
		$this->responder = static fn( array $req ): array => str_ends_with( $req['url'], '/v1/s/wallet/redeem' )
			? array(
				'status' => 200,
				'body'   => '{"coupon":{"code":"SALDO-7K2Q9XAB","amount":"35000.00","expires_at":"2026-11-19T23:59:59Z"},"balance":"35000.00"}',
			)
			: array(
				'status' => 404,
				'body'   => '{}',
			);

		$result = Wallet::redeem( $user );
		$this->assertTrue( $result['ok'], $result['message'] );
		$this->assertSame( 'Aplicamos ' . html_entity_decode( wp_strip_all_tags( wc_price( 35000 ) ), ENT_QUOTES ) . ' de tu saldo.', $result['message'] );
		$this->assertTrue( WC()->cart->has_discount( 'saldo-7k2q9xab' ) );

		$req = end( $this->http );
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'http://hub.test/v1/s/wallet/redeem', $req['url'] );
		$this->assertSame( self::SITE_ID, $req['headers']['X-IGR-Site'] );
		$this->assertTrue( Hmac::verify( $req['headers']['X-IGR-Signature'], $req['body'], array( self::SECRET ) ) );
		$body = json_decode( $req['body'], true );
		$this->assertSame( 'wc:' . $user, $body['customer_ref'] );
		$this->assertSame( '282000.00', $body['cart_total'] );
		$this->assertSame( 'COP', $body['currency'] );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{16,64}$/', $body['cart_token'] );
		$this->assertSame( array( Wallet::TIMEOUT ), array_values( array_unique( $timeouts ) ) );

		// Ya aplicado: no vuelve a llamar al hub.
		$calls = count( $this->http );
		$this->assertTrue( Wallet::redeem( $user )['ok'] );
		$this->assertCount( $calls, $this->http );

		// Sin saldo (409) o con el hub caído: mensaje claro y el carrito sigue igual.
		WC()->cart->remove_coupons();
		$this->responder = static fn( array $req ): array => array(
			'status' => 409,
			'body'   => '{"ok":false,"error":{"code":"no_balance"}}',
		);
		$this->assertSame( 'No tienes saldo disponible por ahora.', Wallet::redeem( $user )['message'] );
		// Otro canje en curso o un carrito en otra moneda (api-connector.md §5.5).
		$this->responder = static fn( array $req ): array => array(
			'status' => 409,
			'body'   => '{"ok":false,"error":{"code":"busy"}}',
		);
		$this->assertSame( 'Estamos aplicando tu saldo. Inténtalo de nuevo en unos segundos.', Wallet::redeem( $user )['message'] );
		$this->responder = static fn( array $req ): array => array(
			'status' => 409,
			'body'   => '{"ok":false,"error":{"code":"currency_mismatch"}}',
		);
		$this->assertSame( 'Tu saldo no se puede usar con la moneda de este carrito.', Wallet::redeem( $user )['message'] );
		$this->responder = static fn( array $req ): array => array(
			'status' => 0,
			'body'   => '',
		);
		$down            = Wallet::redeem( $user );
		$this->assertFalse( $down['ok'] );
		$this->assertSame( 'No pudimos usar tu saldo ahora. Inténtalo más tarde.', $down['message'] );
		$this->assertSame( array(), WC()->cart->get_applied_coupons() );

		// Sin sesión no hay botón.
		wp_set_current_user( 0 );
		$this->assertSame( '', Wallet::html() );
		WC()->cart->empty_cart();
	}

	public function test_el_pedido_lleva_el_codigo_de_referido_en_last_touch_v4(): void {
		$order               = $this->order( array( 'status' => 'pending' ) );
		$_COOKIE['_igr_aid'] = 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa';
		$_COOKIE['_igr_src'] = rawurlencode( '{"f":{"landing":"https://tienda.co/","at":1795000000000},"l":{"utm":{"source":"whatsapp"},"referral_code":"ANA7K2","landing":"https://tienda.co/?ref=ANA7K2","at":1795000000000}}' );
		// Como en el checkout: el contexto se guarda al crear el pedido y order.created sale al procesarlo.
		OrderCollector::attach_context( $order );
		$order->save();
		OrderCollector::on_checkout_processed( $order->get_id(), array(), $order );
		$created = $this->outbox( 'order.created' );
		$this->assertNotEmpty( $created );
		$this->assertSame( 4, $created[0]['schema_version'] );
		$this->assertSame( 'ANA7K2', $created[0]['context']['last_touch']['referral_code'] );
		$this->assertArrayNotHasKey( 'referral_code', $created[0]['context']['first_touch'] );
		$this->assertContract( $this->outbox_raw( 'order.created' ) );
		$this->assertSame( 'ANA7K2', OrderEvents::stored_context( wc_get_order( $order->get_id() ) )['context']['last_touch']['referral_code'] );

		// Un código con formato inválido no viaja.
		$_COOKIE['_igr_src'] = '{"l":{"referral_code":"<b>x</b>"}}';
		$ctx                 = \ImaginaGrowth\Connector\Tracking\RequestContext::capture();
		$this->assertArrayNotHasKey( 'last_touch', $ctx['context'] );
	}

	public function test_interruptores_en_el_sitio(): void {
		$on = TrackerConfig::onsite( Settings::DEFAULTS, array() );
		$this->assertSame(
			array(
				'bis'    => true,
				'pd'     => false,
				'points' => false,
				'rw'     => false,
				'wallet' => false,
				'top'    => false,
			),
			$on,
			'sin señal del hub, puntos, recompensas y saldo quedan apagados'
		);
		$all = TrackerConfig::onsite(
			Settings::DEFAULTS,
			array(
				'loyalty' => true,
				'wallet'  => true,
			)
		);
		$this->assertTrue( $all['points'] && $all['rw'] && $all['wallet'] );
		$this->assertFalse( TrackerConfig::onsite( array_merge( Settings::DEFAULTS, array( 'points' => false ) ), array( 'loyalty' => true ) )['points'] );
		$this->assertSame( 22, Slots::height( null, 'badge' ) );
		$this->assertSame( 500, Slots::height( '500', 'reco' ) );
	}
}
