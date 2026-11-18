<?php
/**
 * Casillas del checkout, estado del banner, ping de cookie y herramientas de privacidad.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use Automattic\WooCommerce\Blocks\Domain\Services\CheckoutFields;
use Automattic\WooCommerce\Blocks\Package;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Consent\CheckoutConsent;
use ImaginaGrowth\Connector\Consent\ConsentState;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Privacy\PrivacyTools;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;
use ImaginaGrowth\Connector\Tracking\Ping;
use ImaginaGrowth\Connector\Tracking\RequestContext;

/**
 * Consentimiento primero (principio 5) y privacidad por diseño (principio 9).
 */
final class ConsentPrivacyTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( 'igr_privacy_pending' );
		$_POST = array();
		// El plugin arranca desconectado en la suite: se registran como al conectar.
		PrivacyTools::register();
		CheckoutConsent::register();
	}

	public function tear_down(): void {
		$_POST = array();
		parent::tear_down();
	}

	public function test_checkout_clasico_casillas_sin_marcar_y_meta_del_pedido(): void {
		ob_start();
		CheckoutConsent::render_classic();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="igr_consent[whatsapp]"', $html );
		$this->assertStringContainsString( 'name="igr_consent[email]"', $html );
		$this->assertStringNotContainsString( 'checked', $html, 'las casillas van sin marcar' );
		$this->assertStringContainsString( 'WhatsApp', $html );

		$_POST['igr_consent'] = array( 'whatsapp' => '1' );
		$order                = $this->order();
		CheckoutConsent::save_classic( $order );
		$order->save();
		$order = wc_get_order( $order->get_id() );
		$this->assertSame(
			array(
				'whatsapp_marketing' => true,
				'email_marketing'    => false,
				'text_version'       => 'local-1',
				'source'             => 'checkout',
			),
			$order->get_meta( CheckoutConsent::META, true )
		);

		$this->connect();
		$created = ( new OrderEvents( new EventFactory( self::SITE_ID, 'woocommerce' ) ) )->created( $order );
		$this->assertSame(
			array(
				'email_marketing'    => false,
				'whatsapp_marketing' => true,
				'text_version'       => 'local-1',
			),
			$created['properties']['order']['consents']
		);
		$this->assertContract( array( $created ) );
	}

	public function test_textos_y_version_del_hub(): void {
		update_option(
			RemoteConfig::OPTION,
			array(
				'consent' => array(
					'texts' => array(
						array(
							'kind'    => 'marketing_consent',
							'channel' => 'whatsapp',
							'label'   => 'Acepto recibir ofertas de <b>Pinturas La 14</b> por WhatsApp.',
							'version' => 'v3',
						),
						array(
							'kind'    => 'privacy_policy',
							'channel' => 'email',
							'label'   => 'Política',
							'version' => 'v9',
						),
					),
				),
			),
			false
		);
		$texts = CheckoutConsent::texts();
		$this->assertSame( array( 'whatsapp' ), array_keys( $texts ), 'solo los textos de marketing del hub' );
		$this->assertSame( 'Acepto recibir ofertas de Pinturas La 14 por WhatsApp.', $texts['whatsapp']['label'] );
		$_POST['igr_consent'] = array();
		$order                = $this->order();
		CheckoutConsent::save_classic( $order );
		$this->assertSame(
			array(
				'whatsapp_marketing' => false,
				'text_version'       => 'v3',
				'source'             => 'checkout',
			),
			$order->get_meta( CheckoutConsent::META, true )
		);
	}

	public function test_checkout_de_bloques_un_campo_por_canal(): void {
		if ( ! class_exists( Package::class ) || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			$this->markTestSkipped( 'WooCommerce sin campos adicionales del checkout.' );
		}
		$fields = Package::container()->get( CheckoutFields::class );
		$keys   = array_keys( $fields->get_additional_fields() );
		$this->assertContains( 'imagina-growth/marketing-whatsapp', $keys );
		$this->assertContains( 'imagina-growth/marketing-email', $keys );
		$this->assertSame( 'checkbox', $fields->get_additional_fields()['imagina-growth/marketing-email']['type'] );
		$this->assertFalse( (bool) $fields->get_additional_fields()['imagina-growth/marketing-email']['required'] );

		$order = $this->order();
		$fields->persist_field_for_order( 'imagina-growth/marketing-email', true, $order, 'other', false );
		$fields->persist_field_for_order( 'imagina-growth/marketing-whatsapp', false, $order, 'other', false );
		$order->save();
		$meta = wc_get_order( $order->get_id() )->get_meta( CheckoutConsent::META, true );
		$this->assertTrue( $meta['email_marketing'] );
		$this->assertFalse( $meta['whatsapp_marketing'] );
		$this->assertSame( 'checkout', $meta['source'] );
	}

	public function test_estado_del_consentimiento_por_banner(): void {
		$this->assertSame( 'none', ConsentState::banner() );
		$this->assertSame( 'informed', ConsentState::mode() );
		$cases = array(
			array(
				array(
					'cmplz_statistics' => 'allow',
					'cmplz_marketing'  => 'deny',
				),
				true,
				false,
				'complianz',
			),
			array( array( 'cookieyes-consent' => 'consentid:x,consent:yes,necessary:yes,analytics:yes,advertisement:yes' ), true, true, 'cookieyes' ),
			array( array( 'CookieConsent' => rawurlencode( "{stamp:'x',necessary:true,preferences:false,statistics:false,marketing:true}" ) ), false, true, 'cookiebot' ),
			array( array( '_igr_consent' => 'a1m0' ), true, false, 'igr-banner' ),
			array( array(), true, false, 'none' ),
		);
		foreach ( $cases as [ $cookies, $analytics, $marketing, $source ] ) {
			$this->assertSame(
				array(
					'analytics' => $analytics,
					'marketing' => $marketing,
					'source'    => $source,
				),
				ConsentState::from_request( $cookies ),
				$source
			);
		}
	}

	public function test_sin_consentimiento_de_marketing_no_viajan_cookies_de_anuncios(): void {
		$cookies = array(
			'_igr_aid' => 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa',
			'_fbp'     => 'fb.1.1700000000.123456',
			'_ga'      => 'GA1.1.123.456',
		);
		$ctx     = RequestContext::capture( $cookies );
		$this->assertArrayNotHasKey( 'ad_cookies', $ctx['context'] );
		$this->assertFalse( $ctx['context']['consent']['marketing'] );

		$ctx = RequestContext::capture( $cookies + array( 'cmplz_marketing' => 'allow' ) );
		$this->assertSame( 'fb.1.1700000000.123456', $ctx['context']['ad_cookies']['fbp'] );
		$this->assertSame( '123.456', $ctx['context']['ad_cookies']['ga_client_id'] );
	}

	public function test_ping_renueva_la_cookie_sin_cache(): void {
		$res = Ping::response( array( '_igr_aid' => 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa' ), '.tienda.co', true );
		$this->assertStringContainsString( 'no-store', $res['headers']['Cache-Control'] );
		$this->assertStringContainsString( 'private', $res['headers']['Cache-Control'] );
		$this->assertSame( 'no-cache', $res['headers']['X-LiteSpeed-Cache-Control'] );
		$this->assertSame( 'no-store', $res['headers']['CDN-Cache-Control'] );
		$this->assertSame( 'c3b0f4a2-1111-4b2b-9d3e-aaaaaaaaaaaa', $res['cookie']['value'] );
		$opts = $res['cookie']['options'];
		$this->assertEqualsWithDelta( time() + 395 * DAY_IN_SECONDS, $opts['expires'], 5 );
		$this->assertSame( 'Lax', $opts['samesite'] );
		$this->assertTrue( $opts['secure'] );
		$this->assertSame( '/', $opts['path'] );
		$this->assertSame( '.tienda.co', $opts['domain'] );

		$this->assertNull( Ping::response( array( '_igr_aid' => '<script>' ), '', true )['cookie'], 'formato inválido' );
		$this->assertNull( Ping::response( array(), '', true )['cookie'] );
	}

	public function test_exportar_y_borrar_crean_la_solicitud_en_el_hub_por_la_cola(): void {
		$this->assertArrayHasKey( 'imagina-growth', apply_filters( 'wp_privacy_personal_data_exporters', array() ) );
		$this->assertArrayHasKey( 'imagina-growth', apply_filters( 'wp_privacy_personal_data_erasers', array() ) );

		// Desconectado: nada que pedir.
		$this->assertSame( array(), PrivacyTools::export( 'ana@example.com' )['data'] );

		$this->connect();
		$request_id = wp_create_user_request( 'Ana@Example.com', 'remove_personal_data' );
		$this->assertIsInt( $request_id );
		$events = new OrderEvents( new EventFactory( self::SITE_ID, 'woocommerce' ) );
		Outbox::insert( $events->created( $this->order( array( 'email' => 'ana@example.com' ) ) ) );
		Outbox::insert( $events->created( $this->order( array( 'email' => 'otro@example.com' ) ) ) );
		$this->assertCount( 2, $this->outbox_raw() );

		$http_before = count( $this->http );
		$result      = PrivacyTools::erase( 'Ana@Example.com' );
		$this->assertTrue( $result['done'] );
		$this->assertGreaterThan( 0, $result['items_removed'] );
		$this->assertSame( 1, $result['items_removed'] );
		$this->assertCount( 1, $this->outbox_raw(), 'solo se borran los eventos de esa persona' );
		$this->assertStringContainsString( 'otro@example.com', $this->outbox_raw()[0] );
		$this->assertSame( $http_before, count( $this->http ), 'ninguna llamada HTTP en la petición' );

		$pending = PrivacyTools::pending();
		$this->assertCount( 1, $pending );
		$ref  = (string) array_key_first( $pending );
		$item = $pending[ $ref ];
		$this->assertSame( 'delete', $item['kind'] );
		$this->assertSame( 'ana@example.com', $item['email'] );
		$this->assertSame( $request_id, $item['wp_request'] );
		$this->assertNotFalse( as_next_scheduled_action( PrivacyTools::ACTION, array( $ref, 'delete', 1 ), Scheduler::GROUP ), 'tarea en Action Scheduler' );
		$this->assertStringNotContainsString( 'ana', implode( ',', array( $ref, 'delete', '1' ) ), 'el correo no viaja en los argumentos de la tarea' );
		$this->assertArrayNotHasKey( 'igr_privacy_pending', wp_load_alloptions(), 'la opción no es autocargada' );

		// La tarea: firmada hacia /v1/s/privacy/requests.
		$this->responder = static fn( array $req ): array => array(
			'status' => 202,
			'body'   => '{"request_id":"dr_01JD","status":"pending","due_at":"2026-12-03T00:00:00Z"}',
		);
		PrivacyTools::send( $ref, 'delete', 1 );
		$req             = end( $this->http );
		$this->assertSame( 'POST', $req['method'] );
		$this->assertSame( 'http://hub.test/v1/s/privacy/requests', $req['url'] );
		$this->assertSame( self::SITE_ID, $req['headers']['X-IGR-Site'] );
		$this->assertTrue( Hmac::verify( $req['headers']['X-IGR-Signature'], $req['body'], array( self::SECRET ) ) );
		$this->assertSame(
			array(
				'kind'          => 'delete',
				'email'         => 'ana@example.com',
				'wp_request_id' => $request_id,
			),
			json_decode( $req['body'], true )
		);
		$this->assertSame( array(), PrivacyTools::pending(), 'se borra el correo al enviarse' );
		$this->assertSame( 'dr_01JD', get_post_meta( $request_id, PrivacyTools::META, true ) );
	}

	public function test_si_el_hub_falla_se_reintenta(): void {
		$this->connect();
		$this->assertNotEmpty( PrivacyTools::export( 'luis@example.com' )['data'] );
		$ref             = (string) array_key_first( PrivacyTools::pending() );
		$this->responder = static fn( array $req ): array => array(
			'status' => 503,
			'body'   => '{}',
		);
		PrivacyTools::send( $ref, 'access', 1 );
		$this->assertCount( 1, PrivacyTools::pending(), 'sigue pendiente' );
		$this->assertNotFalse( as_next_scheduled_action( PrivacyTools::ACTION, array( $ref, 'access', 2 ), Scheduler::GROUP ) );

		// Repetir la exportación no duplica la solicitud.
		PrivacyTools::export( 'luis@example.com' );
		$this->assertCount( 1, PrivacyTools::pending() );
	}

	public function test_log_de_privacidad_sin_correo(): void {
		$this->connect();
		delete_option( 'igr_log' );
		PrivacyTools::export( 'marta@example.com' );
		$ref             = (string) array_key_first( PrivacyTools::pending() );
		$this->responder = static fn( array $req ): array => array(
			'status' => 500,
			'body'   => '{"error":"marta@example.com"}',
		);
		PrivacyTools::send( $ref, 'access', 1 );
		$log             = (string) wp_json_encode( get_option( 'igr_log', array() ) );
		$this->assertStringContainsString( 'privacidad', $log );
		$this->assertStringNotContainsString( 'marta@example.com', $log, 'nunca PII en los logs' );

		Connection::forget();
		$this->assertFalse( PrivacyTools::queue( 'access', 'otra@example.com' ), 'desconectado no encola' );
	}
}
