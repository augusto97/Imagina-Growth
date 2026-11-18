<?php
/**
 * Widget de Elementor «Formulario de Imagina Growth».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `form` en Elementor.
 */
final class FormWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_form';
	}

	public function get_title(): string {
		return __( 'Formulario de Imagina Growth', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-form-horizontal';
	}

	protected function slot(): string {
		return 'form';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'id'     => array( __( 'ID del widget (wg_…)', 'imagina-growth' ), 'text' ),
			'height' => array( __( 'Altura reservada (px)', 'imagina-growth' ), 'number' ),
		);
	}
}
