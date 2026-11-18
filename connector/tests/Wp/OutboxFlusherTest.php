<?php
/**
 * Outbox local y envío firmado al hub.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Nada se pierde: inserción, lotes, rechazos, reintentos y purga.
 */
final class OutboxFlusherTest extends WpTestCase {

	/**
	 * Registra n eventos de prueba.
	 *
	 * @param int $n Cantidad.
	 * @return list<array<string, mixed>>
	 */
	private function record( int $n ): array {
		$f   = new EventFactory( self::SITE_ID, 'woocommerce' );
		$out = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$e = $f->make( 'custom.prueba', array( 'i' => $i ) );
			$this->assertTrue( Recorder::record( $e ) );
			$out[] = $e;
		}
		return $out;
	}

	public function test_insercion_idempotente_por_event_id(): void {
		$f = new EventFactory( self::SITE_ID, 'woocommerce' );
		$e = $f->make( 'catalog.product_deleted', array( 'product_id' => '1' ), array( 'key' => 'product:1:deleted' ) );
		$this->assertTrue( Outbox::insert( $e ) );
		$this->assertFalse( Outbox::insert( $e ), 'el mismo event_id no se duplica' );
		$this->assertSame( 1, Outbox::stats()['pending'] );
	}

	public function test_suprimido_no_registra(): void {
		$f = new EventFactory( self::SITE_ID, 'woocommerce' );
		Recorder::suppress( fn() => $this->assertFalse( Recorder::record( $f->make( 'custom.x', array() ) ) ) );
		$this->assertSame( 0, Outbox::stats()['pending'] );
	}

	public function test_envia_lote_gzip_firmado_sobre_los_bytes_comprimidos(): void {
		$this->connect();
		$events          = $this->record( 3 );
		$rejected_id     = $events[1]['event_id'];
		$this->responder = static fn( array $req ): array => array(
			'status' => 202,
			'body'   => (string) wp_json_encode(
				array(
					'accepted' => 2,
					'rejected' => array(
						array(
							'event_id' => $rejected_id,
							'reason'   => 'envelope',
						),
					),
				)
			),
		);
		$stats           = Flusher::run( 5 );
		$this->assertSame( 2, $stats['sent'] );
		$this->assertSame( 1, $stats['dead'] );

		$req = $this->http[0];
		$this->assertSame( 'http://hub.test/v1/s', $req['url'] );
		$this->assertSame( 'gzip', $req['headers']['Content-Encoding'] );
		$this->assertSame( self::SITE_ID, $req['headers']['X-IGR-Site'] );
		$this->assertTrue( Hmac::verify( $req['headers']['X-IGR-Signature'], $req['body'], array( self::SECRET ) ), 'firma sobre los bytes comprimidos' );
		$batch = json_decode( (string) gzdecode( $req['body'] ), true );
		$this->assertCount( 3, $batch['events'] );
		$this->assertArrayHasKey( 'sent_at', $batch );

		$dead = Outbox::dead();
		$this->assertCount( 1, $dead );
		$this->assertSame( 'rejected: envelope', $dead[0]['last_error'] );
		$this->assertSame( 2, Outbox::stats()['sent'] );
	}

	public function test_5xx_y_red_se_reintentan_con_espera_y_mueren_a_los_10(): void {
		$this->connect();
		$this->record( 2 );
		$this->responder = static fn(): array => array(
			'status' => 503,
			'body'   => '',
		);
		$stats           = Flusher::run( 5 );
		$this->assertSame( 2, $stats['retried'] );
		$s = Outbox::stats();
		$this->assertSame( 2, $s['pending'] );

		global $wpdb;
		$row = $wpdb->get_row( 'SELECT attempts, next_attempt_at FROM ' . Outbox::table() . ' LIMIT 1', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '1', (string) $row['attempts'] );
		$wait = strtotime( $row['next_attempt_at'] . ' UTC' ) - time();
		$this->assertGreaterThan( 50, $wait );
		$this->assertLessThan( 70, $wait );

		// Simula 9 fallos previos: el siguiente lo deja muerto.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Outbox::table() . ' SET attempts = 9, next_attempt_at = %s', gmdate( 'Y-m-d H:i:s', time() - 1 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		Flusher::run( 5 );
		$this->assertSame( 2, Outbox::stats()['dead'] );
		$this->assertSame( 2, Outbox::retry_dead() );
		$this->assertSame( 2, Outbox::stats()['pending'] );
	}

	public function test_sin_conexion_no_hace_http(): void {
		$this->record( 1 );
		Flusher::run( 2 );
		$this->assertSame( array(), $this->http );
	}

	public function test_recupera_filas_atascadas_en_sending_y_purga(): void {
		$this->record( 2 );
		global $wpdb;
		$table = Outbox::table();
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sending', next_attempt_at = %s", gmdate( 'Y-m-d H:i:s', time() - 10 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 2, Outbox::recover_stale() );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'sent', sent_at = %s", gmdate( 'Y-m-d H:i:s', time() - 8 * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$this->assertSame( 2, Outbox::purge() );
	}
}
