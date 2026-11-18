<?php
/**
 * Bloques de Gutenberg de los slots (connector/CLAUDE.md §10).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Onsite;

/**
 * Bloques dinámicos: el editor guarda solo los atributos y el servidor pinta el slot
 * (el mismo HTML que el shortcode). El script del editor es JS plano (`assets/igr-blocks.js`).
 */
final class Blocks {

	/**
	 * Bloque → [método de Slots, atributos].
	 *
	 * @var array<string, array{0: string, 1: array<string, array{type: string, default?: mixed}>}>
	 */
	private const BLOCKS = array(
		'imagina-growth/form'            => array(
			'form',
			array(
				'id'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'height' => array( 'type' => 'number' ),
			),
		),
		'imagina-growth/quiz'            => array(
			'quiz',
			array(
				'id'     => array(
					'type'    => 'string',
					'default' => '',
				),
				'height' => array( 'type' => 'number' ),
			),
		),
		'imagina-growth/recommendations' => array(
			'reco',
			array(
				'type'        => array(
					'type'    => 'string',
					'default' => 'trending',
				),
				'product_id'  => array(
					'type'    => 'string',
					'default' => '',
				),
				'category_id' => array(
					'type'    => 'string',
					'default' => '',
				),
				'limit'       => array(
					'type'    => 'number',
					'default' => 4,
				),
				'title'       => array(
					'type'    => 'string',
					'default' => '',
				),
				'height'      => array( 'type' => 'number' ),
			),
		),
		'imagina-growth/reviews'         => array(
			'reviews',
			array(
				'product_id' => array(
					'type'    => 'string',
					'default' => '',
				),
				'layout'     => array(
					'type'    => 'string',
					'default' => 'list',
				),
				'title'      => array(
					'type'    => 'string',
					'default' => '',
				),
				'height'     => array( 'type' => 'number' ),
			),
		),
		'imagina-growth/loyalty'         => array(
			'loyalty',
			array( 'height' => array( 'type' => 'number' ) ),
		),
	);

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		if ( did_action( 'init' ) ) {
			self::register_blocks();
		} else {
			add_action( 'init', array( self::class, 'register_blocks' ) );
		}
	}

	/**
	 * Registra el script del editor y los bloques.
	 */
	public static function register_blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script(
			'igr-blocks',
			plugins_url( 'assets/igr-blocks.js', IGR_PLUGIN_FILE ),
			array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-i18n' ),
			IGR_VERSION,
			true
		);
		wp_set_script_translations( 'igr-blocks', 'imagina-growth', dirname( IGR_PLUGIN_FILE ) . '/languages' );
		foreach ( self::BLOCKS as $name => [ $method, $attributes ] ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
				continue;
			}
			register_block_type(
				$name,
				array(
					'api_version'     => 3,
					'editor_script'   => 'igr-blocks',
					'attributes'      => $attributes,
					'render_callback' => static fn( mixed $attrs ): string => Slots::$method( is_array( $attrs ) ? $attrs : array() ),
					'supports'        => array( 'html' => false ),
				)
			);
		}
	}
}
