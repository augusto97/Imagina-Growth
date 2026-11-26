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

	public function test_captura_temprana_v6_con_el_dato_en_identity(): void {
		$factory  = new EventFactory( self::SITE, 'woocommerce', '0.1.3' );
		$envelope = $factory->make(
			'checkout.contact_captured',
			array(
				'has_email'  => true,
				'has_phone'  => true,
				'cart_token' => 'a3f9c2e1b7d84f0e9c1a2b3c4d5e6f70',
				'checkout'   => 'blocks',
			),
			array(
				'identity' => array(
					'anonymous_id' => 'c3b0f4a2-1d2e-4f5a-8b9c-0d1e2f3a4b5c',
					'email'        => 'ana@ejemplo.com',
					'phone'        => '+573001234567',
				),
				'context'  => array( 'page_type' => 'checkout' ),
			)
		);
		$this->assertSame( 6, $envelope['schema_version'] );
		$this->assertSame( array(), Schema::errors( $envelope ) );

		// El dato nunca va en properties.
		$leaky                        = $envelope;
		$leaky['properties']['email'] = 'ana@ejemplo.com';
		$this->assertNotSame( array(), Schema::errors( $leaky ) );

		// Desde el navegador no puede viajar con el correo (eventos.md §2).
		$browser                   = $envelope;
		$browser['source']['type'] = 'browser';
		$this->assertNotSame( array(), Schema::errors( $browser ) );

		// Un evento de la versión anterior (sin carrito ni tipo de checkout) sigue siendo válido.
		$old                   = $factory->make(
			'checkout.contact_captured',
			array(
				'has_email' => true,
				'has_phone' => false,
			)
		);
		$old['schema_version'] = 4;
		$this->assertSame( array(), Schema::errors( $old ) );
	}

	public function test_el_contrato_v7_del_hub_sigue_aceptando_la_version_del_plugin(): void {
		// La v7 (lote 4) solo cambió un evento del hub (`lead.created.origin` con messenger e instagram): el plugin
		// sigue en la 6 y su sobre sigue siendo válido.
		$schema   = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/hub/resources/schemas/events/_envelope.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local del contrato.
		$versions = $schema['properties']['schema_version']['enum'];
		$this->assertContains( 7, $versions );
		$this->assertContains( EventFactory::SCHEMA_VERSION, $versions );
		$factory = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$this->assertSame( array(), Schema::errors( $factory->make( 'custom.igr_test', array() ) ) );
	}

	public function test_el_contrato_v8_del_hub_sigue_aceptando_la_version_del_plugin(): void {
		// La v8 (negocios sin tienda) solo cambió eventos del hub: `lead.created.origin` acepta `landing`, `meta_lead_ads`
		// y `google_lead_form`, y hay dos eventos nuevos que solo genera el hub (`appointment.confirmed` y `quote.sent`).
		// El plugin no envía nada nuevo: sigue en la 6 y su sobre sigue siendo válido.
		$schema   = json_decode( (string) file_get_contents( dirname( __DIR__, 3 ) . '/hub/resources/schemas/events/_envelope.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- archivo local del contrato.
		$versions = $schema['properties']['schema_version']['enum'];
		$this->assertContains( 8, $versions );
		$this->assertContains( EventFactory::SCHEMA_VERSION, $versions );
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/hub/resources/schemas/events/appointment.confirmed.json' );
		$this->assertFileExists( dirname( __DIR__, 3 ) . '/hub/resources/schemas/events/quote.sent.json' );
		$factory = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$this->assertSame( array(), Schema::errors( $factory->make( 'custom.igr_test', array() ) ) );
		// Un formulario del sitio sigue entrando igual: el plugin no conoce los orígenes nuevos de los leads.
		$form = $factory->make(
			'form.submitted',
			array(
				'form_plugin' => 'cf7',
				'form_id'     => '12',
				'fields'      => array( 'email' => 'ana@ejemplo.com' ),
			)
		);
		$this->assertSame( array(), Schema::errors( $form ) );
	}

	public function test_el_validador_detecta_un_objeto_vacio_mal_codificado(): void {
		$factory              = new EventFactory( self::SITE, 'woocommerce', '0.1.0' );
		$envelope             = $factory->make( 'custom.x', array( 'a' => 1 ) );
		$envelope['identity'] = array();
		$this->assertNotSame( array(), Schema::errors( $envelope ), '[] en vez de {} debe fallar' );
	}
}
