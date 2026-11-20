<?php
/**
 * Firma v2 entre el plugin y el hub (docs/api-connector.md §2, auditoría S14).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Unit;

use ImaginaGrowth\Connector\Support\Hmac;
use PHPUnit\Framework\TestCase;

/**
 * Método, ruta, consulta canónica, hora, nonce y hash del cuerpo; v1 solo mientras se acepte.
 */
final class HmacV2Test extends TestCase {

	private const SECRET = 'sk_Zm9vYmFyYmF6cXV4Zm9vYmFyYmF6cXV4Zm9vYmFyYmE';

	public function test_cabecera_t_n_v2_sobre_la_cadena_canonica(): void {
		$header  = Hmac::header_v2( self::SECRET, 'get', 'https://growth.imagina.cloud/v1/s/catalog/hashes?type=product&cursor=&limit=1000', '', 1000, 'abcdefghijklmnop' );
		$message = "IGR-HMAC-SHA256-V2\nGET\n/v1/s/catalog/hashes\ncursor=&limit=1000&type=product\n1000\nabcdefghijklmnop\n" . hash( 'sha256', '' );
		$this->assertSame( 't=1000,n=abcdefghijklmnop,v2=' . hash_hmac( 'sha256', $message, self::SECRET ), $header );
		$this->assertSame( 2, Hmac::verify_request( $header, 'GET', array( '/v1/s/catalog/hashes?limit=1000&type=product&cursor=' ), '', array( self::SECRET ), true, 1000 )['version'] ?? null );
	}

	public function test_una_firma_de_una_ruta_no_sirve_para_otra(): void {
		$header = Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/wp-json/imagina-growth/v1/commands', '{}', 1000, str_repeat( 'a', 32 ) );
		$check  = static fn( string $method, string $target, string $body = '{}' ): ?array => Hmac::verify_request( $header, $method, array( $target ), $body, array( self::SECRET ), true, 1000 );
		$this->assertNotNull( $check( 'POST', '/wp-json/imagina-growth/v1/commands' ) );
		$this->assertNull( $check( 'POST', '/wp-json/imagina-growth/v1/otro' ) );
		$this->assertNull( $check( 'PUT', '/wp-json/imagina-growth/v1/commands' ) );
		$this->assertNull( $check( 'POST', '/wp-json/imagina-growth/v1/commands?x=1' ) );
		$this->assertNull( $check( 'POST', '/wp-json/imagina-growth/v1/commands', '{"a":1}' ) );
		$this->assertNull( $check( 'POST', '/v1/s/wallet/redeem' ) );
	}

	public function test_vale_si_coincide_con_alguno_de_los_destinos(): void {
		$header = Hmac::header_v2( self::SECRET, 'POST', 'https://tienda.co/index.php?rest_route=/imagina-growth/v1/commands', '{}', 1000, str_repeat( 'b', 32 ) );
		$this->assertNotNull( Hmac::verify_request( $header, 'POST', array( '/wp-json/imagina-growth/v1/commands', '/index.php?rest_route=%2Fimagina-growth%2Fv1%2Fcommands' ), '{}', array( self::SECRET ), true, 1000 ) );
		$this->assertNull( Hmac::verify_request( $header, 'POST', array( '/wp-json/imagina-growth/v1/commands' ), '{}', array( self::SECRET ), true, 1000 ) );
	}

	public function test_rechaza_ventana_secreto_y_cabeceras_mal_formadas(): void {
		$header = Hmac::header_v2( self::SECRET, 'POST', '/v1/s', 'x', 1000, str_repeat( 'c', 32 ) );
		$check  = static fn( string $h, array $secrets = array( self::SECRET ), int $now = 1000 ): ?array => Hmac::verify_request( $h, 'POST', array( '/v1/s' ), 'x', $secrets, true, $now );
		$this->assertNotNull( $check( $header, array( self::SECRET ), 1300 ) );
		$this->assertNull( $check( $header, array( self::SECRET ), 1301 ) );
		$this->assertNull( $check( $header, array( self::SECRET ), 699 ) );
		$this->assertNull( $check( $header, array( 'sk_otro' ) ) );
		$this->assertNull( $check( $header, array( '' ) ) );
		$this->assertNotNull( $check( $header, array( 'sk_nuevo', self::SECRET ) ), 'rotación: vale el anterior' );
		$this->assertNull( $check( str_replace( ',n=' . str_repeat( 'c', 32 ), ',n=corto', $header ) ) );
		$this->assertNull( $check( str_replace( ',n=' . str_repeat( 'c', 32 ), '', $header ) ) );
		$this->assertNull( $check( str_replace( ',n=' . str_repeat( 'c', 32 ), ',n=' . str_repeat( 'd', 32 ), $header ) ) );
		$this->assertNull( $check( 't=1000,n=' . str_repeat( 'c', 32 ) . ',v2=zz' ) );
		$this->assertNull( $check( '' ) );
	}

	public function test_con_v2_en_la_cabecera_no_se_cae_a_v1(): void {
		$v1  = Hmac::header( self::SECRET, 'x', 1000 );
		$bad = 't=1000,n=' . str_repeat( 'e', 32 ) . ',v2=' . str_repeat( '0', 64 ) . ',' . substr( $v1, (int) strpos( $v1, 'v1=' ) );
		$this->assertSame( 1, Hmac::verify_request( $v1, 'POST', array( '/v1/s' ), 'x', array( self::SECRET ), true, 1000 )['version'] ?? null );
		$this->assertNull( Hmac::verify_request( $bad, 'POST', array( '/v1/s' ), 'x', array( self::SECRET ), true, 1000 ) );
		$this->assertFalse( Hmac::verify( $bad, 'x', array( self::SECRET ), 1000 ) );
	}

	public function test_v1_solo_si_se_acepta_y_sign_elige_la_version(): void {
		$v1 = Hmac::sign( 1, self::SECRET, 'POST', '/v1/s', 'x' );
		$v2 = Hmac::sign( 2, self::SECRET, 'POST', '/v1/s', 'x' );
		$this->assertMatchesRegularExpression( '/^t=\d+,v1=[0-9a-f]{64}$/', $v1 );
		$this->assertMatchesRegularExpression( '/^t=\d+,n=[0-9a-f]{32},v2=[0-9a-f]{64}$/', $v2 );
		$this->assertNull( Hmac::verify_request( $v1, 'POST', array( '/v1/s' ), 'x', array( self::SECRET ), false ) );
		$this->assertSame( 2, Hmac::verify_request( $v2, 'POST', array( '/v1/s' ), 'x', array( self::SECRET ), false )['version'] ?? null );
	}

	public function test_consulta_por_bytes_y_ruta_vacia(): void {
		$this->assertSame( '%20=x&a=1&a=A&b=&n=10&n=9', Hmac::canonical_query( 'n=9&n=10&b&a=%41&a=1&+=x' ) );
		$this->assertSame( '/', Hmac::canonical_path( '' ) );
		$this->assertSame( '/a%2Fb/c%20d', Hmac::canonical_path( '/a%2fb/c d' ) );
		$this->assertMatchesRegularExpression( Hmac::NONCE_PATTERN, Hmac::nonce() );
		$this->assertNotSame( Hmac::nonce(), Hmac::nonce() );
	}
}
