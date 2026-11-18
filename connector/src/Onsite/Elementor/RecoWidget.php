<?php
/**
 * Widget de Elementor «Recomendaciones».
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

// phpcs:disable Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Slot `reco` en Elementor.
 */
final class RecoWidget extends SlotWidget {

	public function get_name(): string {
		return 'igr_reco';
	}

	public function get_title(): string {
		return __( 'Recomendaciones', 'imagina-growth' );
	}

	public function get_icon(): string {
		return 'eicon-products';
	}

	protected function slot(): string {
		return 'reco';
	}

	/**
	 * {@inheritDoc}
	 */
	protected function fields(): array {
		return array(
			'type'       => array(
				__( 'Tipo', 'imagina-growth' ),
				'select',
				array(
					'trending'              => __( 'En tendencia', 'imagina-growth' ),
					'para_ti'               => __( 'Para ti', 'imagina-growth' ),
					'bought_together'       => __( 'Se compran juntos', 'imagina-growth' ),
					'recently_viewed'       => __( 'Vistos recientemente', 'imagina-growth' ),
					'best_sellers_category' => __( 'Más vendidos de la categoría', 'imagina-growth' ),
					'complete_cart'         => __( 'Completa tu compra', 'imagina-growth' ),
				),
			),
			'product_id' => array( __( 'Producto (ID, opcional)', 'imagina-growth' ), 'text' ),
			'limit'      => array( __( 'Cantidad', 'imagina-growth' ), 'number' ),
			'title'      => array( __( 'Título (opcional)', 'imagina-growth' ), 'text' ),
		);
	}
}
