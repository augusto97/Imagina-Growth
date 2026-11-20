<?php
/**
 * Firma v2 con WordPress (docs/api-connector.md §2, auditoría S14).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Installer;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Comandos del hub en v1 y v2 (con y sin enlaces permanentes), negociación con el hub y respaldo a v1.
 */
final class SignatureV2Test extends WpTestCase {

	/**
	 * REQUEST_URI antes de la prueba.
	 *
	 * @var mixed
	 */
	private mixed $request_uri = null;

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
		$this->request_uri = $_SERVER['REQUEST_URI'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	public function tear_down(): void {
		if ( null === $this->request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $this->request_uri;
		}
		parent::tear_down();
	}

	/**
	 * Cuerpo de un comando.
	 *
	 * @param string $command Comando.
	 * @param string $nonce   Nonce del cuerpo.
	 * @return string
	 */
	private function body( string $command = 'site.diagnostics', string $nonce = '' ): string {
		return (string) wp_json_encode(
			array(
				'command'    => $command,
				'args'       => new \stdClass(),
				'request_id' => '01JD' . wp_generate_password( 22, false ),
				'nonce'      => '' !== $nonce ? $nonce : bin2hex( random_bytes( 16 ) ),
				'issued_at'  => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Llama al endpoint de comandos con una cabecera ya armada.
	 *
	 * @param string      $body        Cuerpo.
	 * @param string      $header      X-IGR-Signature.
	 * @param string|null $request_uri REQUEST_URI con el que llegó (null: sin REQUEST_URI).
	 */
	private function dispatch( string $body, string $header, ?string $request_uri ): \WP_REST_Response {
		if ( null === $request_uri ) {
			unset( $_SERVER['REQUEST_URI'] );
		} else {
			$_SERVER['REQUEST_URI'] = $request_uri;
		}
		$request = new \WP_REST_Request( 'POST', '/' . CommandsController::NAMESPACE_V1 . '/commands' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-igr-signature', $header );
		return rest_get_server()->dispatch( $request );
	}

	public function test_comando_v2_con_y_sin_enlaces_permanentes(): void {
		$this->connect( array( 1, 2 ) );
		$body = $this->body();
		$res  = $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/commands', $body ), '/wp-json/imagina-growth/v1/commands' );
		$this->assertSame( 200, $res->get_status() );

		// Sin enlaces permanentes el hub llama a ?rest_route=… (codificado o no).
		$body = $this->body();
		$res  = $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/index.php?rest_route=/imagina-growth/v1/commands', $body ), '/index.php?rest_route=%2Fimagina-growth%2Fv1%2Fcommands' );
		$this->assertSame( 200, $res->get_status() );

		// Sin REQUEST_URI (o con un proxy que reescribe la ruta) vale el destino de rest_url(), el que se informó al conectar.
		$body = $this->body();
		$res  = $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', rest_url( 'imagina-growth/v1/commands' ), $body ), null );
		$this->assertSame( 200, $res->get_status() );
	}

	public function test_una_firma_v2_de_otra_ruta_o_metodo_no_sirve(): void {
		$this->connect( array( 1, 2 ) );
		$body = $this->body();
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/otro', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://growth.imagina.cloud/v1/s/wallet/redeem', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'PUT', 'https://tienda.co/wp-json/imagina-growth/v1/commands', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/commands?x=1', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header_v2( 'sk_otro', 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/commands', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
	}

	public function test_el_nonce_de_la_firma_v2_no_se_repite(): void {
		$this->connect( array( 1, 2 ) );
		$nonce = bin2hex( random_bytes( 16 ) );
		$url   = 'https://tienda.co/wp-json/imagina-growth/v1/commands';
		$first = $this->body();
		$this->assertSame( 200, $this->dispatch( $first, Hmac::header_v2( self::SECRET, 'POST', $url, $first, null, $nonce ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
		// Otro cuerpo (otro request_id y otro nonce en el cuerpo) con el mismo nonce en la firma: repetición.
		$second = $this->body();
		$this->assertSame( 409, $this->dispatch( $second, Hmac::header_v2( self::SECRET, 'POST', $url, $second, null, $nonce ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
	}

	public function test_acepta_v1_hasta_que_el_hub_anuncia_que_ya_no_la_usa(): void {
		$this->connect();
		$body = $this->body();
		$this->assertSame( 200, $this->dispatch( $body, Hmac::header( self::SECRET, $body ), '/wp-json/imagina-growth/v1/commands' )->get_status(), 'hub anterior a la v2' );

		$this->connect( array( 1, 2 ) );
		$body = $this->body();
		$this->assertSame( 200, $this->dispatch( $body, Hmac::header( self::SECRET, $body ), '/wp-json/imagina-growth/v1/commands' )->get_status(), 'hub con v1 y v2' );

		$this->connect( array( 2 ) );
		$body = $this->body();
		$this->assertSame( 401, $this->dispatch( $body, Hmac::header( self::SECRET, $body ), '/wp-json/imagina-growth/v1/commands' )->get_status(), 'hub solo con v2' );
		$body = $this->body();
		$this->assertSame( 200, $this->dispatch( $body, Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/commands', $body ), '/wp-json/imagina-growth/v1/commands' )->get_status() );
	}

	public function test_firma_hacia_el_hub_en_v2_solo_si_el_hub_la_anuncia(): void {
		$this->connect();
		$this->assertSame( 1, Connection::signature_version() );
		Http::signed( 'PUT', Connection::api_url( 'v1/s/backfill' ), '{"status":"running"}', self::SITE_ID, self::SECRET );
		$req = end( $this->http );
		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[0-9a-f]{64}$/', $req['headers']['X-IGR-Signature'] );

		$this->connect( array( 1, 2 ) );
		$this->http = array();
		Http::signed( 'PUT', Connection::api_url( 'v1/s/backfill' ), '{"status":"running"}', self::SITE_ID, self::SECRET );
		$req      = end( $this->http );
		$verified = Hmac::verify_request( $req['headers']['X-IGR-Signature'], 'PUT', array( '/v1/s/backfill' ), $req['body'], array( self::SECRET ), false );
		$this->assertSame( 2, $verified['version'] ?? null );
		$this->assertNull( Hmac::verify_request( $req['headers']['X-IGR-Signature'], 'PUT', array( '/v1/s/config' ), $req['body'], array( self::SECRET ) ) );
		$this->assertCount( 1, $this->http );
	}

	public function test_si_el_hub_rechaza_la_v2_reintenta_una_vez_con_v1(): void {
		$this->connect( array( 1, 2 ) );
		$this->responder = static fn( array $req ): array => str_contains( (string) $req['headers']['X-IGR-Signature'], ',v2=' )
			? array(
				'status' => 401,
				'body'   => '{"error":"signature"}',
			)
			: array(
				'status' => 202,
				'body'   => '{"accepted":0,"rejected":[]}',
			);

		$res = Http::signed( 'POST', Connection::endpoint( 'ingest_server' ), '{"events":[]}', self::SITE_ID, self::SECRET, true );

		$this->assertSame( 202, $res->status );
		$this->assertCount( 2, $this->http );
		$this->assertTrue( Hmac::verify( $this->http[1]['headers']['X-IGR-Signature'], $this->http[1]['body'], array( self::SECRET ) ), 'el reintento va en v1 sobre los mismos bytes' );

		// Con la v1 apagada en el hub no hay respaldo.
		$this->connect( array( 2 ) );
		$this->http = array();
		$this->assertSame( 401, Http::signed( 'POST', Connection::endpoint( 'ingest_server' ), '{"events":[]}', self::SITE_ID, self::SECRET, true )->status );
		$this->assertCount( 1, $this->http );
	}

	public function test_la_configuracion_informa_la_version_y_guarda_lo_que_anuncia_el_hub(): void {
		$this->connect();
		$signature       = array( 'versions' => array( 1, 2 ) );
		$this->responder = static function ( array $req ) use ( &$signature ): array {
			unset( $req );
			$body = array( 'settings' => new \stdClass() );
			if ( null !== $signature ) {
				$body['signature'] = $signature;
			}
			return array(
				'status' => 200,
				'body'   => (string) wp_json_encode( $body ),
			);
		};
		$this->assertTrue( RemoteConfig::refresh() );
		$req = end( $this->http );
		$this->assertSame( 'http://hub.test/v1/s/config?plugin_version=' . IGR_VERSION, $req['url'] );
		$this->assertSame( 1, Hmac::verify_request( $req['headers']['X-IGR-Signature'], 'GET', array( $req['url'] ), '', array( self::SECRET ) )['version'] ?? null, 'todavía no sabía que el hub entiende v2' );
		$this->assertSame( 2, Connection::signature_version() );
		$this->assertTrue( Connection::accepts_v1() );

		// La siguiente ya va en v2, con la consulta firmada.
		$signature = array( 'versions' => array( 2 ) );
		$this->assertTrue( RemoteConfig::refresh() );
		$req = end( $this->http );
		$this->assertSame( 2, Hmac::verify_request( $req['headers']['X-IGR-Signature'], 'GET', array( $req['url'] ), '', array( self::SECRET ), false )['version'] ?? null );
		$this->assertNull( Hmac::verify_request( $req['headers']['X-IGR-Signature'], 'GET', array( 'http://hub.test/v1/s/config?plugin_version=0.0.1' ), '', array( self::SECRET ) ) );
		$this->assertFalse( Connection::accepts_v1() );

		// Un hub que vuelve a una versión sin la v2 no anuncia nada: se vuelve a v1.
		$signature = null;
		$this->assertTrue( RemoteConfig::refresh() );
		$this->assertSame( 1, Connection::signature_version() );
		$this->assertTrue( Connection::accepts_v1() );
	}

	public function test_el_canje_guarda_lo_que_anuncia_el_hub(): void {
		$this->responder = static fn(): array => array(
			'status' => 200,
			'body'   => (string) wp_json_encode(
				array(
					'site_id'    => self::SITE_ID,
					'public_key' => 'pk_' . str_repeat( 'a', 32 ),
					'secret'     => self::SECRET,
					'workspace'  => array( 'id' => 'ws_01JD3QF0A1B2C3D4E5F6G7H8J9' ),
					'endpoints'  => array(
						'ingest_server'  => 'http://hub.test/v1/s',
						'ingest_browser' => 'http://hub.test/v1/b',
						'api'            => 'http://hub.test/',
					),
					'signature'  => array( 'versions' => array( 1, 2 ) ),
				)
			),
		);
		$this->assertTrue( \ImaginaGrowth\Connector\Connection\ConnectionManager::exchange( 'IGR-7K2Q-M9XD-4H8T' ) );
		$this->assertSame( 2, Connection::signature_version() );
		$sent = json_decode( $this->http[0]['body'], true );
		$this->assertSame( IGR_VERSION, $sent['plugin_version'] );
	}

	public function test_una_version_nueva_del_plugin_pide_la_configuracion_enseguida(): void {
		$this->connect();
		Connection::update_state( array( 'pv' => '0.1.3' ) );
		remove_all_actions( 'init' );
		Installer::maybe_upgrade();
		$this->assertSame( IGR_VERSION, Connection::state()['pv'] ?? null );
		$this->assertSame( 20, has_action( 'init', array( Installer::class, 'after_update' ) ) );

		// La misma versión no vuelve a pedir nada.
		remove_all_actions( 'init' );
		Installer::maybe_upgrade();
		$this->assertFalse( has_action( 'init', array( Installer::class, 'after_update' ) ) );
	}
}
