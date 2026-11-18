<?php
/**
 * Cotizador y agenda en el sitio (M18): slot `quote` y botón de reservas.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

use ImaginaGrowth\Connector\Onsite\Elementor\BookingWidget;
use ImaginaGrowth\Connector\Onsite\Elementor\QuoteWidget;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * `[igr_quote id="qc_…"]` pinta el slot del cotizador (lo llena `igr-w-quote.js`) y
 * `[igr_booking service="slug"]` un enlace a la página de reservas del hub. Los dos
 * son HTML fijo (cacheable): no dependen del visitante ni llaman al hub al pintar.
 * También como bloques de Gutenberg y widgets de Elementor.
 */
final class ServiceSlots {

	private const QUOTE_HEIGHT = 420;

	/**
	 * Registra shortcodes, bloques y widgets de Elementor.
	 */
	public static function register(): void {
		add_shortcode( 'igr_quote', static fn( mixed $atts ): string => self::quote( is_array( $atts ) ? $atts : array() ) );
		add_shortcode( 'igr_booking', static fn( mixed $atts ): string => self::booking( is_array( $atts ) ? $atts : array() ) );
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', array( self::class, 'register_blocks' ) );
		}
		add_action( 'elementor/widgets/register', array( self::class, 'elementor' ) );
	}

	/**
	 * Slot del cotizador.
	 *
	 * @param array<string, mixed> $atts `id` (qc_…), `height`.
	 */
	public static function quote( array $atts ): string {
		$id = is_string( $atts['id'] ?? null ) ? trim( $atts['id'] ) : '';
		if ( 1 !== preg_match( '/^qc_[0-9A-Za-z]{26}$/', $id ) ) {
			return '';
		}
		$height = is_numeric( $atts['height'] ?? null ) ? max( 0, min( 2000, (int) $atts['height'] ) ) : self::QUOTE_HEIGHT;
		return Slots::html( 'quote', array( 'calculatorId' => $id ), $height );
	}

	/**
	 * Enlace (o botón) a la página de reservas del hub: `/p/{clave}/reservar[/{servicio}]`.
	 *
	 * @param array<string, mixed> $atts `service` (slug), `label`, `style` (button|link), `new_tab` (1|0).
	 */
	public static function booking( array $atts ): string {
		$url = self::booking_url( is_string( $atts['service'] ?? null ) ? $atts['service'] : '' );
		if ( null === $url ) {
			return '';
		}
		$label   = is_string( $atts['label'] ?? null ) && '' !== trim( $atts['label'] ) ? trim( $atts['label'] ) : __( 'Agendar cita', 'imagina-growth' );
		$button  = 'link' !== ( $atts['style'] ?? 'button' );
		$new_tab = ! in_array( (string) ( $atts['new_tab'] ?? '1' ), array( '0', 'false', 'no' ), true );
		return sprintf(
			'<a class="igr-booking%1$s" href="%2$s" data-igr-cta="booking"%3$s>%4$s</a>',
			$button ? ' igr-booking-button wp-element-button' : '',
			esc_url( $url ),
			$new_tab ? ' target="_blank" rel="noopener"' : '',
			esc_html( $label )
		);
	}

	/**
	 * URL de la página de reservas, o null si el sitio no está conectado.
	 *
	 * @param string $service Slug del servicio (opcional).
	 */
	public static function booking_url( string $service = '' ): ?string {
		$trk = TrackerConfig::get();
		$wu  = is_array( $trk ) ? (string) ( $trk['wu'] ?? '' ) : '';
		if ( '' === $wu || ! str_ends_with( $wu, '/widgets.json' ) ) {
			return null;
		}
		$base    = substr( $wu, 0, -strlen( '/widgets.json' ) ) . '/reservar';
		$service = strtolower( trim( $service ) );
		return 1 === preg_match( '/^[a-z0-9-]{1,80}$/', $service ) ? $base . '/' . $service : $base;
	}

	/**
	 * Bloques dinámicos «Cotizador» y «Botón de reservas».
	 */
	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'igr-blocks-services',
			plugins_url( 'assets/igr-blocks-services.js', IGR_PLUGIN_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			IGR_VERSION,
			true
		);
		wp_set_script_translations( 'igr-blocks-services', 'imagina-growth', dirname( IGR_PLUGIN_FILE ) . '/languages' );
		$blocks = array(
			'imagina-growth/quote'   => array(
				'attributes' => array(
					'id'     => array(
						'type'    => 'string',
						'default' => '',
					),
					'height' => array( 'type' => 'number' ),
				),
				'render'     => static fn( mixed $attrs ): string => self::quote( is_array( $attrs ) ? $attrs : array() ),
			),
			'imagina-growth/booking' => array(
				'attributes' => array(
					'service' => array(
						'type'    => 'string',
						'default' => '',
					),
					'label'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'style'   => array(
						'type'    => 'string',
						'default' => 'button',
					),
				),
				'render'     => static fn( mixed $attrs ): string => self::booking( is_array( $attrs ) ? $attrs : array() ),
			),
		);
		foreach ( $blocks as $name => $block ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'editor_script'   => 'igr-blocks-services',
					'attributes'      => $block['attributes'],
					'render_callback' => $block['render'],
					'supports'        => array( 'html' => false ),
				)
			);
		}
	}

	/**
	 * Widgets de Elementor (solo con Elementor activo).
	 *
	 * @param mixed $manager \Elementor\Widgets_Manager.
	 */
	public static function elementor( mixed $manager ): void {
		if ( ! is_object( $manager ) || ! method_exists( $manager, 'register' ) || ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}
		$manager->register( new QuoteWidget() );
		$manager->register( new BookingWidget() );
	}
}
