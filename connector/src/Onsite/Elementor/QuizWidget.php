<?php
/**
 * Widget de Elementor «Quiz de Imagina Growth».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `quiz` en Elementor.
 */
final class QuizWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_quiz';
	}

	public function get_title(): string {
		return __( 'Quiz de Imagina Growth', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-help-o';
	}

	protected function slot(): string {
		return 'quiz';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'id'     => array( __( 'ID del quiz (wg_…)', 'imagina-growth' ), 'text' ),
			'height' => array( __( 'Altura reservada (px)', 'imagina-growth' ), 'number' ),
		);
	}
}
