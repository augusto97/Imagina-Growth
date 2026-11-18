<?php
/**
 * Widgets de Elementor para los slots (connector/CLAUDE.md §10).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite\Elementor;

/**
 * Solo se registran si Elementor está activo: las clases de los widgets extienden
 * `\Elementor\Widget_Base` y se cargan dentro del hook de Elementor.
 */
final class Registrar {

	public const WIDGETS = array( FormWidget::class, QuizWidget::class, RecoWidget::class, ReviewsWidget::class, LoyaltyWidget::class );

	/**
	 * Registra el hook.
	 */
	public static function register(): void {
		add_action( 'elementor/widgets/register', array( self::class, 'widgets' ) );
	}

	/**
	 * Agrega los widgets al administrador de Elementor.
	 *
	 * @param mixed $manager \Elementor\Widgets_Manager.
	 */
	public static function widgets( mixed $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'register' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		foreach ( self::WIDGETS as $class ) {
			$manager->register( new $class() );
		}
	}
}
