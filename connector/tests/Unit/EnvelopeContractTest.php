<?php
/**
 * Contrato del sobre armado por EventFactory (sin WordPress).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Unit;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Support\Uuid;
use ImaginaGrowth\Connector\Tests\Support\Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/Support/Schema.php';

/**
 * El sobre cumple hub/resources/schemas/events/_envelope.json.
 */
final class EnvelopeContractTest extends TestCase {

	private const SITE = 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2';

	public function test_sobre_minimo_con_identidad_y_propiedades_vacias(): void {
		$factory  = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$envelope = $factory->make( 'custom.igr_test', array() );
		$this->assertSame( array(), Schema::errors( $envelope ) );
		$this->assertStringContainsString( '"identity":{}', \ImaginaGrowth\Connector\Support\Json::encode( $envelope ) );
		$this->assertStringContainsString( '"properties":{}', \ImaginaGrowth\Connector\Support\Json::encode( $envelope ) );
		$this->assertArrayNotHasKey( 'context', $envelope, 'sin contexto no se envía la clave' );
	}

	public function test_event_id_determinista_con_clave(): void {
		$factory  = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$envelope = $factory->make( 'catalog.product_deleted', array( 'product_id' => '88' ), array( 'key' => 'product:88:deleted' ) );
		$this->assertSame( Uuid::event( self::SITE, 'product:88:deleted' ), $envelope['event_id'] );
		$this->assertSame( array(), Schema::errors( $envelope ) );
	}

	public function test_compacta_contexto_e_identidad(): void {
		$factory  = new EventFactory( self::SITE, 'wordpress', '0.1.0' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Valor del contrato.
		$envelope = $factory->make(
			'catalog.category_deleted',
			array( 'category_id' => '12' ),
			array(
				'type'        => 'import',
				'occurred_at' => 1763420700,
				'identity'    => array(
					'anonymous_id' => '',
					'email'        => null,
				),
				'context'     => array(
					'utm'       => array( 'source' => '' ),
					'click_ids' => array(),
					'currency'  => 'COP',
				),
			)
		);
		$this->assertSame( array( 'currency' => 'COP' ), $envelope['context'] );
		$this->assertSame( 'import', $envelope['source']['type'] );
		$this->assertSame( 'connector/0.1.0', $envelope['source']['sdk'] );
		$this->assertSame( array(), Schema::errors( $envelope ) );
	}

	public function test_el_validador_detecta_un_objeto_vacio_mal_codificado(): void {
		$factory              = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$envelope             = $factory->make( 'custom.x', array( 'a' => 1 ) );
		$envelope['identity'] = array();
		$this->assertNotSame( array(), Schema::errors( $envelope ), '[] en vez de {} debe fallar' );
	}
}
