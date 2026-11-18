<?php
/**
 * Shortcodes de los slots (connector/CLAUDE.md §10).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

/**
 * `[igr_form id=""]`, `[igr_quiz id=""]`, `[igr_reco type=""]`, `[igr_reviews]`,
 * `[igr_loyalty]` y `[igr_banner placement=""]`.
 */
final class Shortcodes {

	public const TAGS = array(
		'igr_form'    => 'form',
		'igr_quiz'    => 'quiz',
		'igr_reco'    => 'reco',
		'igr_reviews' => 'reviews',
		'igr_loyalty' => 'loyalty',
		'igr_banner'  => 'banner',
	);

	/**
	 * Registra los shortcodes.
	 */
	public static function register(): void {
		foreach ( self::TAGS as $tag => $method ) {
			add_shortcode(
				$tag,
				static function ( mixed $atts ) use ( $method ): string {
					return Slots::$method( is_array( $atts ) ? $atts : array() );
				}
			);
		}
	}
}
