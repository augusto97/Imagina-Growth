<?php
/**
 * Contrato de la firma con el hub (docs/api-connector.md §2): los mismos vectores que
 * hub/tests/Contract/SignatureVectorsTest.php.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Unit;

use ImaginaGrowth\Connector\Support\Hmac;
use PHPUnit\Framework\TestCase;

/**
 * Lee `hub/tests/fixtures/signature/vectors.json` (generados con una implementación independiente): si el plugin o
 * el hub cambian la cadena, la canonización o la cabecera, alguna de las dos suites falla.
 */
final class SignatureVectorsTest extends TestCase {

	/**
	 * Vectores compartidos.
	 *
	 * @return array<string, mixed>
	 */
	private static function vectors(): array {
		$file = dirname( __DIR__, 3 ) . '/hub/tests/fixtures/signature/vectors.json';
		if ( ! is_readable( $file ) ) {
			self::markTestSkipped( 'Sin el hub en el repositorio' );
		}
		return (array) json_decode( (string) file_get_contents( $file ), true, 512, JSON_THROW_ON_ERROR ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/**
	 * Cuerpo de un vector (texto o binario en base64).
	 *
	 * @param array<string, mixed> $vector Vector.
	 */
	private static function body( array $vector ): string {
		return isset( $vector['body_base64'] ) ? (string) base64_decode( (string) $vector['body_base64'], true ) : (string) $vector['body']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	public function test_misma_etiqueta_que_el_hub(): void {
		$this->assertSame( self::vectors()['label'], Hmac::LABEL );
	}

	public function test_canoniza_la_ruta_y_la_consulta_como_el_hub(): void {
		foreach ( self::vectors()['canonical'] as $vector ) {
			$this->assertSame( array( $vector['path'], $vector['query'] ), Hmac::target( $vector['target'] ), $vector['target'] );
		}
	}

	public function test_firma_y_verifica_cada_vector_v2(): void {
		foreach ( self::vectors()['v2'] as $vector ) {
			$body = self::body( $vector );
			$this->assertSame( $vector['body_sha256'], hash( 'sha256', $body ), $vector['name'] );
			$this->assertSame( $vector['string_to_sign'], Hmac::string_to_sign( $vector['method'], $vector['url'], $vector['t'], $vector['nonce'], $body ), $vector['name'] );
			$this->assertSame( $vector['header'], Hmac::header_v2( $vector['secret'], $vector['method'], $vector['url'], $body, $vector['t'], $vector['nonce'] ), $vector['name'] );
			$verified = Hmac::verify_request( $vector['header'], $vector['method'], array( $vector['url'] ), $body, array( $vector['secret'] ), false, $vector['t'] );
			$this->assertSame(
				array(
					'version' => 2,
					'nonce'   => $vector['nonce'],
				),
				$verified,
				$vector['name']
			);
		}
	}

	public function test_una_firma_v2_no_sirve_para_otra_ruta_metodo_o_cuerpo(): void {
		$vectors = self::vectors()['v2'];
		foreach ( $vectors as $signed ) {
			foreach ( $vectors as $other ) {
				$same     = $other['method'] === $signed['method'] && Hmac::target( $other['url'] ) === Hmac::target( $signed['url'] ) && self::body( $other ) === self::body( $signed );
				$verified = Hmac::verify_request( $signed['header'], $other['method'], array( $other['url'] ), self::body( $other ), array( $signed['secret'] ), true, $signed['t'] );
				$this->assertSame( $same, null !== $verified, $signed['name'] . ' → ' . $other['name'] );
			}
		}
	}

	public function test_vectores_v1_solo_mientras_se_acepte_la_v1(): void {
		foreach ( self::vectors()['v1'] as $vector ) {
			$body = self::body( $vector );
			$this->assertSame( $vector['header'], Hmac::header( $vector['secret'], $body, $vector['t'] ) );
			$this->assertTrue( Hmac::verify( $vector['header'], $body, array( $vector['secret'] ), $vector['t'] ) );
			$this->assertSame( 1, Hmac::verify_request( $vector['header'], 'POST', array( '/v1/s' ), $body, array( $vector['secret'] ), true, $vector['t'] )['version'] ?? null );
			$this->assertNull( Hmac::verify_request( $vector['header'], 'POST', array( '/v1/s' ), $body, array( $vector['secret'] ), false, $vector['t'] ) );
		}
	}
}
