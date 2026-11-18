<?php
/**
 * Datos de página, tokens firmados y carga del tracker (api-connector.md §7).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Settings;
use ImaginaGrowth\Connector\Support\Uuid;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;
use ImaginaGrowth\Connector\Tracking\PageData;
use ImaginaGrowth\Connector\Tracking\PageToken;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Lo que ve el navegador.
 */
final class PageDataTest extends WpTestCase {

	private const TOKEN = '/^[A-Za-z0-9_-]+\.[0-9a-f]{64}$/';

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( wc_get_page_id( 'checkout' ) <= 0 || null === get_post( wc_get_page_id( 'checkout' ) ) ) {
			\WC_Install::create_pages();
		}
		$this->connect();
	}

	public function tear_down(): void {
		$_COOKIE = array();
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * `window.X = {...};` de un JS en línea.
	 *
	 * @param string $js  JS.
	 * @param string $var Variable.
	 * @return array<string, mixed>|null
	 */
	private function js_var( string $js, string $var ): ?array {
		if ( 1 !== preg_match( '/window\.' . $var . '=(\{.*?\});(?=window\.|$)/', $js, $m ) ) {
			return null;
		}
		return json_decode( $m[1], true );
	}

	private function inline(): string {
		$trk = TrackerConfig::get();
		$this->assertIsArray( $trk );
		return PageData::inline( $trk );
	}

	public function test_al_conectar_se_guarda_la_copia_autocargada(): void {
		$trk = TrackerConfig::get();
		$this->assertIsArray( $trk );
		$this->assertTrue( $trk['on'] );
		$this->assertSame( self::SITE_ID, $trk['site'] );
		$this->assertSame( 'http://hub.test/v1/b', $trk['ep'] );
		$this->assertSame( 'http://hub.test/p/pk_' . str_repeat( 'a', 32 ) . '/widgets.json', $trk['wu'] );
		$this->assertLessThan( 1024, strlen( (string) wp_json_encode( $trk ) ), 'la copia por página debe ser pequeña' );
		$this->assertStringNotContainsString( self::SECRET, (string) wp_json_encode( $trk ), 'nunca el secreto' );

		Connection::forget();
		$this->assertNull( TrackerConfig::get() );
	}

	public function test_config_y_pagina_iguales_para_todos_los_visitantes(): void {
		$this->go_to( home_url( '/' ) );
		$_COOKIE = array();
		$a       = $this->inline();
		$_COOKIE = array(
			'_igr_aid'         => 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa',
			'cmplz_marketing'  => 'allow',
			'_fbp'             => 'fb.1.1700000000.123',
			'woocommerce_cart' => '1',
		);
		$b       = $this->inline();
		$this->assertSame( $a, $b, 'el HTML cacheable no puede variar por visitante' );

		$config = $this->js_var( $a, 'igrConfig' );
		$this->assertIsArray( $config );
		$this->assertSame( self::SITE_ID, $config['siteId'] );
		$this->assertSame( 'pk_' . str_repeat( 'a', 32 ), $config['siteKey'] );
		$this->assertSame( 'http://hub.test/v1/b', $config['endpoint'] );
		$this->assertSame( 'woocommerce', $config['platform'] );
		$this->assertSame( 'informed', $config['consentMode'], 'sin banner de cookies' );
		$this->assertSame(
			array(
				'analytics' => true,
				'marketing' => false,
				'source'    => 'none',
			),
			$config['consent']
		);
		$this->assertSame( 'COP', $config['currency'] );
		$this->assertStringEndsWith( '/?igr_ping=1', $config['pingUrl'] );
		$this->assertStringContainsString( 'igr-widgets.js', $config['widgetsJs'] );
		$this->assertArrayNotHasKey( 'pixels', $config, 'sin modo full no hay píxeles' );
		$this->assertSame( array( 'type' => 'home' ), $this->js_var( $a, 'igrPage' ) );
		$this->assertStringNotContainsString( 'igrUser', $a );
		$this->assertStringNotContainsString( 'igrOrder', $a );
	}

	public function test_ficha_de_producto_y_html_seguro(): void {
		$product = $this->product( '89900', array( 'name' => 'Vinilo "Mate" & Co' ) );
		$this->go_to( (string) get_permalink( $product->get_id() ) );
		$page = $this->js_var( $this->inline(), 'igrPage' );
		$this->assertSame( 'product', $page['type'] );
		$this->assertSame( (string) $product->get_id(), $page['objectId'] );
		$this->assertSame( '89900.00', $page['product']['price'] );
		$this->assertSame( 'COP', $page['product']['currency'] );
		$this->assertTrue( $page['product']['inStock'] );
		$this->assertSame( 'Vinilo "Mate" & Co', $page['product']['name'] );
	}

	public function test_busqueda_de_productos_y_html_seguro(): void {
		$this->go_to( home_url( '/?s=pintura+azul&post_type=product' ) );
		$page = $this->js_var( $this->inline(), 'igrPage' );
		$this->assertSame( 'search', $page['type'] );
		$this->assertSame( 'pintura azul', $page['search']['query'] );
		$this->assertSame( 'wc', $page['search']['engine'] );

		$this->go_to( home_url( '/?s=' . rawurlencode( '</script><script>alert(1)</script>' ) ) );
		$js = $this->inline();
		$this->assertStringNotContainsString( '</script>', $js, 'JSON_HEX_TAG: no se puede cerrar el <script> en línea' );
		$this->assertSame( '</script><script>alert(1)</script>', $this->js_var( $js, 'igrPage' )['search']['query'] );
	}

	public function test_mi_cuenta_con_sesion_lleva_token_de_usuario(): void {
		$user = self::factory()->user->create( array( 'role' => 'customer' ) );
		wp_set_current_user( $user );
		$this->go_to( (string) get_permalink( wc_get_page_id( 'myaccount' ) ) );
		$this->assertTrue( is_account_page() );
		$igr_user = $this->js_var( $this->inline(), 'igrUser' );
		$this->assertSame( 'wc:' . $user, $igr_user['ref'] );
		$this->assertMatchesRegularExpression( self::TOKEN, $igr_user['token'] );
		$payload = PageToken::verify( $igr_user['token'], array( self::SECRET ), self::SITE_ID );
		$this->assertSame( 'wc:' . $user, $payload['ref'] );
		$this->assertSame( self::SITE_ID, $payload['site'] );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $payload['exp'], 5 );
		$this->assertNull( PageToken::verify( $igr_user['token'], array( 'otro-secreto' ), self::SITE_ID ), 'firma' );
		$this->assertNull( PageToken::verify( $igr_user['token'], array( self::SECRET ), 'st_otro' ), 'sitio' );
		$this->assertNull( PageToken::verify( $igr_user['token'], array( self::SECRET ), self::SITE_ID, time() + 2 * HOUR_IN_SECONDS ), 'vencido' );

		// Mismo usuario en una página cacheable: nunca igrUser.
		$this->go_to( home_url( '/' ) );
		$this->assertStringNotContainsString( 'igrUser', $this->inline() );
	}

	public function test_formato_del_token_igual_al_del_hub(): void {
		$token             = PageToken::sign(
			array(
				'order' => '1234',
				'site'  => self::SITE_ID,
				'exp'   => 1800000000,
			),
			'secreto'
		);
		[ $payload, $sig ] = explode( '.', $token );
		$this->assertSame( '{"order":"1234","site":"' . self::SITE_ID . '","exp":1800000000}', base64_decode( strtr( $payload, '-_', '+/' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->assertStringNotContainsString( '=', $payload, 'base64url sin relleno' );
		$this->assertSame( hash_hmac( 'sha256', $payload, 'secreto' ), $sig );
	}

	public function test_pagina_de_gracias_lleva_pedido_firmado(): void {
		$order = $this->order( array( 'status' => 'processing' ) );
		$this->go_to( $order->get_checkout_order_received_url() );
		$this->assertTrue( is_order_received_page() );
		$js        = $this->inline();
		$igr_order = $this->js_var( $js, 'igrOrder' );
		$this->assertSame( Uuid::event( self::SITE_ID, 'order:' . $order->get_id() . ':paid' ), $igr_order['eventId'], 'el event_id de order.paid' );
		$this->assertSame( (string) $order->get_id(), $igr_order['orderId'] );
		$this->assertSame( '300000.00', $igr_order['value'] );
		$this->assertSame( 'COP', $igr_order['currency'] );
		$this->assertTrue( $igr_order['paid'] );
		$payload = PageToken::verify( $igr_order['token'], array( self::SECRET ), self::SITE_ID );
		$this->assertSame( (string) $order->get_id(), $payload['order'] );
		$this->assertEqualsWithDelta( time() + DAY_IN_SECONDS, $payload['exp'], 5 );
		$this->assertSame( 'thankyou', $this->js_var( $js, 'igrPage' )['type'] );

		// Contraentrega sin pagar: el event_id de order.created.
		$cod = $this->order(
			array(
				'status'  => 'processing',
				'gateway' => 'cod',
			)
		);
		$this->go_to( $cod->get_checkout_order_received_url() );
		$data = $this->js_var( $this->inline(), 'igrOrder' );
		$this->assertFalse( $data['paid'] );
		$this->assertSame( Uuid::event( self::SITE_ID, 'order:' . $cod->get_id() . ':created' ), $data['eventId'] );
	}

	public function test_gracias_con_clave_equivocada_no_expone_el_pedido(): void {
		$order = $this->order( array( 'status' => 'processing' ) );
		$this->go_to( add_query_arg( 'key', 'wc_order_falsa', $order->get_checkout_order_received_url() ) );
		$this->assertStringNotContainsString( 'igrOrder', $this->inline() );
	}

	public function test_encola_el_script_diferido_con_la_configuracion_antes(): void {
		$this->go_to( home_url( '/' ) );
		PageData::enqueue();
		$this->assertTrue( wp_script_is( 'igr-tracker', 'enqueued' ) );
		$this->assertStringEndsWith( 'assets/tracker.min.js', wp_scripts()->registered['igr-tracker']->src );
		$this->assertSame( 'defer', wp_scripts()->get_data( 'igr-tracker', 'strategy' ) );
		$this->assertSame( 1, wp_scripts()->get_data( 'igr-tracker', 'group' ), 'en el pie de página' );
		$before = implode( '', (array) wp_scripts()->get_data( 'igr-tracker', 'before' ) );
		$this->assertStringStartsWith( 'window.igrConfig=', $before );
		$this->assertFalse( wp_script_is( 'igr-banner', 'enqueued' ), 'sin banner propio' );
	}

	public function test_roles_excluidos_y_tracker_apagado(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$this->go_to( home_url( '/' ) );
		PageData::enqueue();
		$this->assertFalse( wp_script_is( 'igr-tracker', 'enqueued' ), 'administradores fuera por defecto' );

		Settings::save( array( 'excluded_roles' => array( 'shop_manager' ) ) );
		PageData::enqueue();
		$this->assertTrue( wp_script_is( 'igr-tracker', 'enqueued' ), 'los roles locales mandan' );

		$GLOBALS['wp_scripts'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		Settings::save( array( 'tracker' => false ) );
		PageData::enqueue();
		$this->assertFalse( wp_script_is( 'igr-tracker', 'enqueued' ) );
	}

	public function test_pixeles_del_hub_y_banner_propio(): void {
		update_option(
			RemoteConfig::OPTION,
			array(
				'measurement' => array(
					'mode'   => 'full',
					'pixels' => array(
						'meta'   => '1234567890',
						'tiktok' => 'C9ABCDEF',
						'google' => 'bad id!',
					),
				),
			),
			false
		);
		RemoteConfig::reset();
		Settings::save(
			array(
				'banner'        => true,
				'wa_numbers'    => '+57 300 123 4567, 12',
				'cookie_domain' => '.Tienda.co',
			)
		);
		$this->go_to( home_url( '/' ) );
		$config = $this->js_var( $this->inline(), 'igrConfig' );
		$this->assertSame(
			array(
				'meta'   => '1234567890',
				'tiktok' => 'C9ABCDEF',
			),
			$config['pixels']
		);
		$this->assertStringContainsString( 'igr-pixels.js', $config['pixelsJs'] );
		$this->assertSame( 'strict', $config['consentMode'] );
		$this->assertFalse( $config['consent']['analytics'], 'con banner nada se mide antes de aceptar' );
		$this->assertSame( 'igr-banner', $config['consent']['source'] );
		$this->assertNotEmpty( $config['banner']['text'] );
		$this->assertSame( '.tienda.co', $config['cookieDomain'] );
		$this->assertSame(
			array(
				'enabled' => true,
				'format'  => '{text} (ref: {token})',
				'numbers' => array( '573001234567' ),
			),
			$config['waRef']
		);
		PageData::enqueue();
		$this->assertTrue( wp_script_is( 'igr-banner', 'enqueued' ) );
	}

	public function test_espacio_de_la_encuesta_en_gracias(): void {
		ob_start();
		PageData::thankyou_slot();
		$this->assertSame( '<div class="igr-slot" data-igr-slot="thankyou"></div>', ob_get_clean() );
		Connection::forget();
		ob_start();
		PageData::thankyou_slot();
		$this->assertSame( '', ob_get_clean() );
	}
}
