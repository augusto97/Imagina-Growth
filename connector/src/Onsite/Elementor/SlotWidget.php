<?php
/**
 * Base de los widgets de Elementor de Imagina Growth.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

use ImaginaGrowth\Connector\Onsite\Slots;

// phpcs:disable Generic.Commenting.DocComment.MissingShort, Squiz.Commenting.FunctionComment.Missing -- Métodos de la API de Elementor.

/**
 * Cada widget define su slot y sus campos; pinta el mismo HTML que el shortcode.
 */
abstract class SlotWidget extends \Elementor\Widget_Base {

	/**
	 * Método de Slots que pinta el widget.
	 */
	abstract protected function slot(): string;

	/**
	 * Campos: clave → [etiqueta, tipo (text|number|select), opciones].
	 *
	 * @return array<string, array{0: string, 1: string, 2?: array<string, string>}>
	 */
	abstract protected function fields(): array;

	public function get_icon(): string {
		return 'eicon-apps';
	}

	/**
	 * @return list<string>
	 */
	public function get_categories(): array {
		return array( 'general' );
	}

	/**
	 * @return list<string>
	 */
	public function get_keywords(): array {
		return array( 'imagina', 'growth' );
	}

	protected function register_controls(): void {
		$this->start_controls_section( 'igr_content', array( 'label' => __( 'Imagina Growth', 'imagina-growth' ) ) );
		foreach ( $this->fields() as $key => $field ) {
			$types = array(
				'text'   => \Elementor\Controls_Manager::TEXT,
				'number' => \Elementor\Controls_Manager::NUMBER,
				'select' => \Elementor\Controls_Manager::SELECT,
			);
			$args  = array(
				'label' => $field[0],
				'type'  => $types[ $field[1] ] ?? \Elementor\Controls_Manager::TEXT,
			);
			if ( isset( $field[2] ) ) {
				$args['options'] = $field[2];
				$args['default'] = (string) array_key_first( $field[2] );
			}
			$this->add_control( $key, $args );
		}
		$this->end_controls_section();
	}

	protected function render(): void {
		$settings = $this->get_settings_for_display();
		$method   = $this->slot();
		echo Slots::$method( is_array( $settings ) ? $settings : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Slots::html escapa cada atributo.
	}
}
