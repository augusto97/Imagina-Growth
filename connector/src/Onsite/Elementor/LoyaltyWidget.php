<?php
/**
 * Widget de Elementor «Mis recompensas».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `loyalty` en Elementor.
 */
final class LoyaltyWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_loyalty';
	}

	public function get_title(): string {
		return __( 'Mis recompensas', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-favorite';
	}

	protected function slot(): string {
		return 'loyalty';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array();
	}
}
