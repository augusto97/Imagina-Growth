<?php
/**
 * Cotizador y botón de reservas en el sitio (M18, connector/CLAUDE.md §10).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Onsite\Elementor\BookingWidget;
use ImaginaGrowth\Connector\Onsite\Elementor\QuoteWidget;
use ImaginaGrowth\Connector\Onsite\ServiceSlots;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * HTML fijo (cacheable), sin llamadas al hub al pintar.
 */
final class ServiceSlotsTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->connect();
		ServiceSlots::register();
		ServiceSlots::register_blocks();
	}

	public function test_el_shortcode_del_cotizador_pinta_el_slot_con_espacio_reservado(): void {
		$html = do_shortcode( '[igr_quote id="qc_01JE0000000000000000000000"]' );
		$this->assertStringContainsString( 'data-igr-slot="quote"', $html );
		$this->assertStringContainsString( 'min-height:420px', $html );
		$this->assertSame( 1, preg_match( '/data-igr-ctx="([^"]*)"/', $html, $m ) );
		$this->assertSame( array( 'calculatorId' => 'qc_01JE0000000000000000000000' ), json_decode( html_entity_decode( $m[1], ENT_QUOTES ), true ) );
		$this->assertStringContainsString( 'min-height:600px', do_shortcode( '[igr_quote id="qc_01JE0000000000000000000000" height="600"]' ) );
		$this->assertSame( '', do_shortcode( '[igr_quote id="<script>"]' ), 'un ID inválido no pinta nada' );
		$this->assertSame( '', do_shortcode( '[igr_quote]' ) );
	}

	public function test_el_boton_de_reservas_lleva_a_la_pagina_del_hub(): void {
		$trk = TrackerConfig::get();
		$this->assertIsArray( $trk );
		$base = substr( (string) $trk['wu'], 0, -strlen( '/widgets.json' ) ) . '/reservar';

		$html = do_shortcode( '[igr_booking service="valoracion" label="Agenda tu valoración"]' );
		$this->assertStringContainsString( 'href="' . esc_url( $base . '/valoracion' ) . '"', $html );
		$this->assertStringContainsString( 'data-igr-cta="booking"', $html );
		$this->assertStringContainsString( '>Agenda tu valoración</a>', $html );
		$this->assertStringContainsString( 'igr-booking-button', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );

		$link = do_shortcode( '[igr_booking style="link" new_tab="0" service="no válido/../x"]' );
		$this->assertStringContainsString( 'href="' . esc_url( $base ) . '"', $link, 'un servicio inválido lleva a la lista' );
		$this->assertStringNotContainsString( 'igr-booking-button', $link );
		$this->assertStringNotContainsString( 'target=', $link );
		$this->assertStringContainsString( '>Agendar cita</a>', $link );
	}

	public function test_los_bloques_pintan_lo_mismo_que_el_shortcode(): void {
		$this->assertTrue( \WP_Block_Type_Registry::get_instance()->is_registered( 'imagina-growth/quote' ) );
		$quote = render_block(
			array(
				'blockName'    => 'imagina-growth/quote',
				'attrs'        => array( 'id' => 'qc_01JE0000000000000000000000' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertSame( do_shortcode( '[igr_quote id="qc_01JE0000000000000000000000"]' ), $quote );
		$booking = render_block(
			array(
				'blockName'    => 'imagina-growth/booking',
				'attrs'        => array( 'service' => 'valoracion' ),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);
		$this->assertStringContainsString( '/reservar/valoracion', $booking );
	}

	public function test_sin_conexion_no_pinta_nada(): void {
		\ImaginaGrowth\Connector\Connection\Connection::forget();
		TrackerConfig::rebuild();
		$this->assertSame( '', do_shortcode( '[igr_quote id="qc_01JE0000000000000000000000"]' ) );
		$this->assertSame( '', do_shortcode( '[igr_booking]' ) );
	}

	public function test_los_widgets_de_elementor_se_registran_solo_con_elementor(): void {
		$manager = new class() {
			/**
			 * Widgets registrados.
			 *
			 * @var list<object>
			 */
			public array $widgets = array();

			/**
			 * Registra un widget.
			 *
			 * @param object $widget Widget.
			 */
			public function register( object $widget ): void {
				$this->widgets[] = $widget;
			}
		};
		ServiceSlots::elementor( $manager );
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			$this->assertSame( array(), $manager->widgets );
			return;
		}
		$this->assertInstanceOf( QuoteWidget::class, $manager->widgets[0] );
		$this->assertInstanceOf( BookingWidget::class, $manager->widgets[1] );
	}
}
