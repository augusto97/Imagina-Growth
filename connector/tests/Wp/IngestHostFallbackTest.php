<?php
/**
 * Si el host de ingesta no existe en el DNS, el plugin pasa al dominio principal del hub.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Caso real: el hub entregó `in.<dominio>` y ese registro DNS no existía (cURL error 6).
 */
final class IngestHostFallbackTest extends WpTestCase {

	/**
	 * Conexión con la ingesta en un host aparte.
	 */
	private function connect_with_ingest_host(): void {
		Connection::store(
			array(
				'site_id'    => self::SITE_ID,
				'public_key' => 'pk_' . str_repeat( 'a', 32 ),
				'secret'     => self::SECRET,
				'workspace'  => array( 'id' => 'ws_01JD3QF0A1B2C3D4E5F6G7H8J9' ),
				'endpoints'  => array(
					'ingest_server'  => 'https://in.hub.test/v1/s',
					'ingest_browser' => 'https://in.hub.test/v1/b',
					'api'            => 'https://hub.test/',
				),
			)
		);
	}

	/**
	 * El host in.hub.test no existe: WordPress devuelve el error de cURL 6.
	 *
	 * @param mixed  $pre  Respuesta previa.
	 * @param array  $args Argumentos.
	 * @param string $url  URL.
	 * @return mixed
	 */
	public function dns_fails_for_ingest_host( mixed $pre, array $args, string $url ): mixed {
		unset( $args );
		if ( str_starts_with( $url, 'https://in.hub.test/' ) ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 6: Could not resolve host: in.hub.test' );
		}
		return $pre;
	}

	public function test_sin_dns_de_ingesta_envia_al_dominio_principal_y_lo_guarda(): void {
		$this->connect_with_ingest_host();
		add_filter( 'pre_http_request', array( $this, 'dns_fails_for_ingest_host' ), 20, 3 );
		$this->responder = static fn(): array => array(
			'status' => 202,
			'body'   => '{"accepted":2,"rejected":[]}',
		);
		$f               = new EventFactory( self::SITE_ID, 'woocommerce' );
		$this->assertTrue( Recorder::record( $f->make( 'custom.prueba', array( 'i' => 1 ) ) ) );
		$this->assertTrue( Recorder::record( $f->make( 'custom.prueba', array( 'i' => 2 ) ) ) );

		$stats = Flusher::run( 5 );
		remove_filter( 'pre_http_request', array( $this, 'dns_fails_for_ingest_host' ), 20 );

		$this->assertNull( $stats['error'] );
		$this->assertSame( 2, $stats['sent'] );
		$this->assertSame( 0, Outbox::stats()['pending'] );
		$this->assertSame( 'https://hub.test/v1/s', Connection::endpoint( 'ingest_server' ) );
		$this->assertSame( 'https://hub.test/v1/b', Connection::endpoint( 'ingest_browser' ), 'el tracker también cambia' );
		$this->assertSame( 'https://hub.test/v1/s/config', Connection::api_url( 'v1/s/config' ) );
		$urls = array_column( $this->http, 'url' );
		$this->assertSame( array( 'https://in.hub.test/v1/s', 'https://hub.test/v1/s' ), $urls );
	}

	public function test_otro_error_de_red_no_cambia_el_host(): void {
		$this->connect_with_ingest_host();
		$this->responder = static fn(): array => array(
			'status' => 503,
			'body'   => '{}',
		);
		$f               = new EventFactory( self::SITE_ID, 'woocommerce' );
		$this->assertTrue( Recorder::record( $f->make( 'custom.prueba', array( 'i' => 1 ) ) ) );

		$stats = Flusher::run( 5 );

		$this->assertNotNull( $stats['error'] );
		$this->assertSame( 'https://in.hub.test/v1/s', Connection::endpoint( 'ingest_server' ) );
	}
}
