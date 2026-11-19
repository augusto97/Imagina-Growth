<?php
/**
 * «Importar y enviar ahora» desde el administrador (Admin\SyncNow).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Admin\SyncNow;
use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Sin Action Scheduler corriendo, el botón importa y envía todo; si el hub falla, se detiene y dice por qué.
 */
final class SyncNowTest extends WpTestCase {

	/**
	 * El hub acepta todo lo que llega.
	 */
	private function hub_accepts_all(): void {
		$this->responder = static function ( array $req ): array {
			if ( str_ends_with( $req['url'], '/v1/s' ) ) {
				$events = json_decode( (string) gzdecode( $req['body'] ), true );
				return array(
					'status' => 202,
					'body'   => (string) wp_json_encode(
						array(
							'accepted' => count( (array) ( $events['events'] ?? array() ) ),
							'rejected' => array(),
						)
					),
				);
			}
			return array(
				'status' => 200,
				'body'   => '{}',
			);
		};
	}

	public function test_importa_y_envia_todo_sin_action_scheduler(): void {
		$this->connect();
		$this->hub_accepts_all();
		$this->product();
		$this->order( array( 'status' => 'completed' ) );
		$this->order( array( 'status' => 'processing' ) );
		$this->assertSame( array(), Runner::state(), 'nunca se había importado' );

		$guard = 0;
		$first = true;
		do {
			$res   = SyncNow::run( $first, 5 );
			$first = false;
		} while ( ! $res['done'] && null === $res['error'] && ++$guard < 20 );

		$this->assertNull( $res['error'] );
		$this->assertTrue( $res['done'] );
		$this->assertSame( 'completed', $res['status'] );
		$this->assertSame( 0, $res['pending'] );
		$orders = array_values( array_filter( $res['entities'], static fn( array $e ): bool => 'orders' === $e['key'] ) );
		$this->assertSame( 2, $orders[0]['total'] );
		$this->assertSame( 2, $orders[0]['sent'] );
		$this->assertGreaterThan( 0, Outbox::stats()['sent'] );
		$ingest = array_filter( $this->http, static fn( array $r ): bool => str_ends_with( $r['url'], '/v1/s' ) );
		$this->assertNotEmpty( $ingest, 'envió al endpoint de ingesta' );
	}

	public function test_se_detiene_con_el_error_del_hub_y_reintenta_lo_pendiente(): void {
		$this->connect();
		$this->order( array( 'status' => 'completed' ) );
		$this->responder = static fn( array $req ): array => array(
			'status' => 503,
			'body'   => '{"message":"Service Unavailable"}',
		);

		$res = SyncNow::run( true, 5 );

		$this->assertNotNull( $res['error'], 'muestra el error del hub' );
		$this->assertFalse( $res['done'] );
		$this->assertGreaterThan( 0, $res['pending'], 'nada se pierde: queda pendiente' );

		// El hub vuelve: el siguiente clic adelanta los reintentos y termina.
		$this->hub_accepts_all();
		$guard = 0;
		$first = true;
		do {
			$res   = SyncNow::run( $first, 5 );
			$first = false;
		} while ( ! $res['done'] && null === $res['error'] && ++$guard < 20 );
		$this->assertTrue( $res['done'], (string) $res['error'] );
		$this->assertSame( 0, $res['pending'] );
	}

	public function test_sin_conexion_no_hace_nada(): void {
		$res = SyncNow::run( true, 2 );
		$this->assertSame( array(), $this->http );
		$this->assertSame( 0, $res['sent'] );
	}
}
