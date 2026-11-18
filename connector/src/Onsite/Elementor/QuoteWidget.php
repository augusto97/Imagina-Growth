<?php
/**
 * Widget de Elementor «Cotizador».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

use ImaginaGrowth\Connector\Onsite\ServiceSlots;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `quote` en Elementor.
 */
final class QuoteWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_quote';
	}

	public function get_title(): string {
		return __( 'Cotizador', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-price-table';
	}

	protected function slot(): string {
		return 'quote';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'id'     => array( __( 'ID de la calculadora (qc_…)', 'imagina-growth' ), 'text' ),
			'height' => array( __( 'Altura reservada (px)', 'imagina-growth' ), 'number' ),
		);
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		echo ServiceSlots::quote( is_array( $settings ) ? $settings : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa cada atributo.
	}
}
