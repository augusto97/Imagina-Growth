<?php
/**
 * Clases de soporte sin WordPress.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Unit;

use ImaginaGrowth\Connector\Outbox\Backoff;
use ImaginaGrowth\Connector\Support\Crypto;
use ImaginaGrowth\Connector\Support\Hmac;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Money;
use ImaginaGrowth\Connector\Support\Phone;
use ImaginaGrowth\Connector\Support\Pii;
use ImaginaGrowth\Connector\Support\Uuid;
use PHPUnit\Framework\TestCase;

/**
 * Firma, UUID, JSON, montos, cifrado, PII y backoff.
 */
final class SupportTest extends TestCase {

	public function test_hmac_firma_t_punto_cuerpo_como_el_hub(): void {
		$header = Hmac::header( 'sk_secreto', 'cuerpo', 1763420700 );
		$this->assertSame( 't=1763420700,v1=' . hash_hmac( 'sha256', '1763420700.cuerpo', 'sk_secreto' ), $header );
		$this->assertTrue( Hmac::verify( $header, 'cuerpo', array( 'sk_secreto' ), 1763420700 ) );
	}

	public function test_hmac_rechaza_ventana_cuerpo_y_secreto(): void {
		$header = Hmac::header( 'sk_s', 'x', 1000 );
		$this->assertFalse( Hmac::verify( $header, 'x', array( 'sk_s' ), 1301 ) );
		$this->assertTrue( Hmac::verify( $header, 'x', array( 'sk_s' ), 1300 ) );
		$this->assertFalse( Hmac::verify( $header, 'y', array( 'sk_s' ), 1000 ) );
		$this->assertFalse( Hmac::verify( $header, 'x', array( 'sk_otro' ), 1000 ) );
		$this->assertTrue( Hmac::verify( $header, 'x', array( 'sk_nuevo', 'sk_s' ), 1000 ), 'rotación: vale el anterior' );
		$this->assertFalse( Hmac::verify( 't=abc,v1=zz', 'x', array( 'sk_s' ), 1000 ) );
		$this->assertFalse( Hmac::verify( $header, 'x', array( '' ), 1000 ) );
	}

	public function test_hmac_firma_los_bytes_comprimidos(): void {
		$gz     = (string) gzencode( '{"events":[]}' );
		$header = Hmac::header( 'sk_s', $gz, 50 );
		$this->assertTrue( Hmac::verify( $header, $gz, array( 'sk_s' ), 50 ) );
		$this->assertFalse( Hmac::verify( $header, '{"events":[]}', array( 'sk_s' ), 50 ) );
	}

	public function test_uuid_v5_con_el_espacio_de_nombres_del_contrato(): void {
		// uuid5(NAMESPACE_DNS, "growth.imagina.cloud") = espacio de nombres de api-connector.md §1.1.
		$this->assertSame( Uuid::EVENT_NAMESPACE, Uuid::v5( '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'growth.imagina.cloud' ) );
		// Vector conocido de Python: uuid.uuid5(uuid.NAMESPACE_DNS, 'python.org').
		$this->assertSame( '886313e1-3b8a-5372-9b90-0c9aee199e5d', Uuid::v5( '6ba7b810-9dad-11d1-80b4-00c04fd430c8', 'python.org' ) );
		$a = Uuid::event( 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2', 'order:1234:paid' );
		$this->assertSame( $a, Uuid::event( 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2', 'order:1234:paid' ) );
		$this->assertNotSame( $a, Uuid::event( 'st_01JD3QG7Y2V8K6N4M5P9R0S1T3', 'order:1234:paid' ) );
		$this->assertTrue( Uuid::is_valid( $a ) );
		$this->assertSame( '5', $a[14] );
	}

	public function test_uuid_v4_y_v7(): void {
		$v7 = Uuid::v7( 1763420700123 );
		$this->assertTrue( Uuid::is_valid( $v7 ) );
		$this->assertSame( '7', $v7[14] );
		$this->assertStringStartsWith( '019a', $v7 );
		$this->assertLessThan( 0, strcmp( $v7, Uuid::v7( 1763420700124 ) ), 'ordenable por tiempo' );
		$this->assertTrue( Uuid::is_valid( Uuid::v4() ) );
		$this->assertSame( '4', Uuid::v4()[14] );
	}

	public function test_json_objetos_vacios_viajan_como_llaves(): void {
		$this->assertSame( '{}', Json::encode( Json::obj( array() ) ) );
		$this->assertSame( '{"a":1}', Json::encode( Json::obj( array( 'a' => 1 ) ) ) );
		$this->assertSame( '{"x":1.0}', Json::encode( array( 'x' => 1.0 ) ) );
		$this->assertSame( '"á/b"', Json::encode( 'á/b' ) );
		$this->assertNull( Json::decode( '' ) );
		$this->assertNull( Json::decode( '"texto"' ) );
	}

	public function test_montos_en_texto_con_dos_decimales(): void {
		$this->assertSame( '159900.00', Money::format( 159900 ) );
		$this->assertSame( '0.00', Money::format( null ) );
		$this->assertSame( '0.30', Money::add( '0.10', '0.20' ) );
		$this->assertSame( '1532000000.50', Money::add( '1532000000.00', '0.50' ) );
		$this->assertSame( 15990012, Money::to_cents( '159900.115' ) );
		$this->assertSame( '-12.05', Money::from_cents( -1205 ) );
		$this->assertNull( Money::format_or_null( '' ) );
	}

	public function test_cifrado_del_secreto(): void {
		$cipher = Crypto::encrypt( 'sk_secreto', 'sal-1' );
		$this->assertStringStartsWith( 'igr1:', $cipher );
		$this->assertStringNotContainsString( 'sk_secreto', $cipher );
		$this->assertSame( 'sk_secreto', Crypto::decrypt( $cipher, 'sal-1' ) );
		$this->assertNull( Crypto::decrypt( $cipher, 'sal-2' ), 'otra sal no descifra' );
		$this->assertNull( Crypto::decrypt( 'basura', 'sal-1' ) );
		$this->assertNotSame( $cipher, Crypto::encrypt( 'sk_secreto', 'sal-1' ), 'nonce aleatorio' );
	}

	public function test_pii_nunca_deja_datos_en_claro(): void {
		$masked  = Pii::mask(
			array(
				'email'   => 'ana.ruiz@ejemplo.com',
				'billing' => array(
					'phone' => '+573001234567',
					'city'  => 'Manizales',
				),
			)
		);
		$encoded = (string) json_encode( $masked );
		$this->assertStringNotContainsString( 'ana.ruiz', $encoded );
		$this->assertStringNotContainsString( '3001234567', $encoded );
		$this->assertStringContainsString( 'Manizales', $encoded );
		$this->assertStringNotContainsString( 'ana@ejemplo.com', Pii::mask_text( 'Falló con ana@ejemplo.com' ) );
	}

	public function test_telefonos_a_e164(): void {
		$this->assertSame( '+573001234567', Phone::e164( '300 123 4567', 'CO' ) );
		$this->assertSame( '+573001234567', Phone::e164( '+57 300-123-4567', 'CO' ) );
		$this->assertNull( Phone::e164( '123', 'CO' ) );
	}

	public function test_backoff_1m_5m_30m_2h_12h_y_muerto_a_los_10(): void {
		$this->assertSame( array( 60, 300, 1800, 7200, 43200, 43200 ), array_map( array( Backoff::class, 'delay' ), array( 1, 2, 3, 4, 5, 9 ) ) );
		$this->assertFalse( Backoff::exhausted( 9 ) );
		$this->assertTrue( Backoff::exhausted( 10 ) );
	}
}
