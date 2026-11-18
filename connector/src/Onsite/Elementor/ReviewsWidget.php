<?php
/**
 * Widget de Elementor «Reseñas».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `reviews` en Elementor.
 */
final class ReviewsWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_reviews';
	}

	public function get_title(): string {
		return __( 'Reseñas', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-rating';
	}

	protected function slot(): string {
		return 'reviews';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'layout'     => array(
				__( 'Diseño', 'imagina-growth' ),
				'select',
				array(
					'list'     => __( 'Lista', 'imagina-growth' ),
					'carousel' => __( 'Carrusel', 'imagina-growth' ),
					'gallery'  => __( 'Galería de fotos', 'imagina-growth' ),
					'badge'    => __( 'Insignia de estrellas', 'imagina-growth' ),
				),
			),
			'product_id' => array( __( 'Producto (ID, opcional)', 'imagina-growth' ), 'text' ),
		);
	}
}
