<?php
/**
 * Widget de Elementor «Botón de reservas».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

use ImaginaGrowth\Connector\Onsite\ServiceSlots;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Enlace a la página de reservas del hub en Elementor.
 */
final class BookingWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_booking';
	}

	public function get_title(): string {
		return __( 'Botón de reservas', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-calendar';
	}

	protected function slot(): string {
		return 'booking';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'service' => array( __( 'Servicio (slug, opcional)', 'imagina-growth' ), 'text' ),
			'label'   => array( __( 'Texto del botón', 'imagina-growth' ), 'text' ),
			'style'   => array(
				__( 'Estilo', 'imagina-growth' ),
				'select',
				array(
					'button' => __( 'Botón', 'imagina-growth' ),
					'link'   => __( 'Enlace', 'imagina-growth' ),
				),
			),
		);
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		echo ServiceSlots::booking( is_array( $settings ) ? $settings : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- booking() escapa la URL y el texto.
	}
}
