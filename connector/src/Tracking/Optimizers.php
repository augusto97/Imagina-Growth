<?php
/**
 * Exclusiones en los optimizadores de JS (connector/CLAUDE.md §9).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tracking;

/**
 * El tracker no se combina ni se retrasa hasta la interacción: se perderían las
 * vistas de página. Pesa ≤ 6 KB y ya va con `defer`.
 */
final class Optimizers {

	private const KEYWORDS = array( 'imagina-growth-connector/assets/', 'igrConfig', 'igr-tracker' );

	/**
	 * Registra los filtros de LiteSpeed Cache, WP Rocket, Autoptimize, Perfmatters y FlyingPress.
	 */
	public static function register(): void {
		$add = static fn( mixed $list ): array => array_values( array_unique( array_merge( (array) $list, self::KEYWORDS ) ) );
		foreach ( array( 'litespeed_optimize_js_excludes', 'litespeed_optm_js_defer_exc', 'litespeed_optm_js_delay_inc_exc', 'rocket_exclude_js', 'rocket_delay_js_exclusions', 'rocket_exclude_defer_js', 'perfmatters_delay_js_exclusions', 'flying_press_exclude_from_delay:js', 'flying_press_exclude_from_minify:js' ) as $filter ) {
			add_filter( $filter, $add ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtros de otros plugins.
		}
		add_filter(
			'autoptimize_filter_js_exclude', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Filtro de Autoptimize.
			static fn( mixed $list ): string => trim( (string) $list . ', ' . implode( ', ', self::KEYWORDS ), ', ' )
		);
		add_filter( 'script_loader_tag', array( self::class, 'tag' ), 10, 2 );
		add_filter( 'wp_inline_script_attributes', array( self::class, 'inline_attributes' ) );
	}

	/**
	 * Marca la etiqueta del tracker para que los optimizadores la dejen tal cual.
	 *
	 * @param mixed $tag    Etiqueta.
	 * @param mixed $handle Handle.
	 */
	public static function tag( mixed $tag, mixed $handle ): string {
		$tag = (string) $tag;
		if ( in_array( $handle, array( 'igr-tracker', 'igr-banner' ), true ) && ! str_contains( $tag, 'data-no-optimize' ) ) {
			$tag = str_replace( '<script ', '<script data-no-optimize="1" data-cfasync="false" nowprocket ', $tag );
		}
		return $tag;
	}

	/**
	 * Lo mismo para la configuración en línea (admite el nonce de CSP que agregue el sitio).
	 *
	 * @param mixed $attributes Atributos.
	 * @return array<string, mixed>
	 */
	public static function inline_attributes( mixed $attributes ): array {
		$attributes = (array) $attributes;
		$id         = (string) ( $attributes['id'] ?? '' );
		if ( str_starts_with( $id, 'igr-tracker-js' ) || str_starts_with( $id, 'igr-banner-js' ) ) {
			$attributes['data-no-optimize'] = '1';
			$attributes['data-cfasync']     = 'false';
			$attributes['nowprocket']       = true;
		}
		return $attributes;
	}
}
