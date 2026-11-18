<?php
/**
 * Comandos firmados del hub (docs/api-connector.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Firma ±300 s, nonce de 10 minutos e idempotencia por request_id.
 */
final class CommandsTest extends WpTestCase {

	/**
	 * Llama al endpoint como lo haría el hub.
	 *
	 * @param array<string, mixed> $payload Cuerpo.
	 * @param string|null          $secret  Secreto para firmar.
	 * @param int|null             $time    Hora de la firma.
	 */
	private function call( array $payload, ?string $secret = null, ?int $time = null ): \WP_REST_Response {
		$body    = (string) wp_json_encode( $payload );
		$request = new \WP_REST_Request( 'POST', '/' . CommandsController::NAMESPACE_V1 . '/commands' );
		$request->set_body( $body );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-igr-signature', Hmac::header( $secret ?? self::SECRET, $body, $time ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * Cuerpo de un comando.
	 *
	 * @param string               $command Comando.
	 * @param array<string, mixed> $args    Argumentos.
	 * @return array<string, mixed>
	 */
	private function cmd( string $command, array $args = array() ): array {
		return array(
			'command'    => $command,
			'args'       => (object) $args,
			'request_id' => '01JD' . wp_generate_password( 22, false ),
			'nonce'      => bin2hex( random_bytes( 16 ) ),
			'issued_at'  => gmdate( 'c' ),
		);
	}

	public function set_up(): void {
		parent::set_up();
		do_action( 'rest_api_init' );
	}

	public function test_diagnostico_con_firma_valida(): void {
		$this->connect();
		$res = $this->call( $this->cmd( 'site.diagnostics' ) );
		$this->assertSame( 200, $res->get_status() );
		$data = $res->get_data();
		$this->assertTrue( $data['ok'] );
		$this->assertArrayHasKey( 'outbox', (array) $data['data'] );
	}

	public function test_rechaza_firma_mala_vencida_y_sin_conexion(): void {
		$this->assertSame( 403, $this->call( $this->cmd( 'site.diagnostics' ) )->get_status() );
		$this->connect();
		$this->assertSame( 401, $this->call( $this->cmd( 'site.diagnostics' ), 'sk_otro' )->get_status() );
		$this->assertSame( 401, $this->call( $this->cmd( 'site.diagnostics' ), null, time() - 301 )->get_status() );
	}

	public function test_nonce_repetido_e_idempotencia(): void {
		$this->connect();
		$cmd   = $this->cmd( 'catalog.resync' );
		$first = $this->call( $cmd );
		$this->assertSame( 200, $first->get_status() );
		// Mismo request_id: misma respuesta, sin ejecutar de nuevo.
		$again = $this->call( $cmd );
		$this->assertSame( $first->get_data(), $again->get_data() );
		// Mismo nonce con otro request_id: repetición.
		$replay               = $cmd;
		$replay['request_id'] = 'otro-' . $cmd['request_id'];
		$this->assertSame( 409, $this->call( $replay )->get_status() );
	}

	public function test_comando_desconocido_y_argumentos_invalidos(): void {
		$this->connect();
		$this->assertSame( 404, $this->call( $this->cmd( 'coupon.inventado' ) )->get_status() );
		$this->assertSame( 422, $this->call( $this->cmd( 'backfill.start', array( 'from' => 'ayer' ) ) )->get_status() );
		$this->assertSame( 422, $this->call( $this->cmd( 'secret.rotate', array( 'secret' => 'corto' ) ) )->get_status() );
	}

	public function test_rotacion_del_secreto_acepta_ambos_24_horas(): void {
		$this->connect();
		$new = 'sk_' . str_repeat( 'N', 43 );
		$res = $this->call( $this->cmd( 'secret.rotate', array( 'secret' => $new ) ) );
		$this->assertSame( 200, $res->get_status() );
		Connection::flush_cache();
		$this->assertSame( $new, Connection::secret() );
		$this->assertContains( self::SECRET, Connection::valid_secrets() );
		$this->assertSame( 200, $this->call( $this->cmd( 'site.diagnostics' ), $new )->get_status() );
		$this->assertSame( 200, $this->call( $this->cmd( 'site.diagnostics' ), self::SECRET )->get_status() );
	}
}
