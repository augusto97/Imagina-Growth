<?php
/**
 * Datos de producto que se repiten en pedidos y catálogo, en caché por petición.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Catalog;

/**
 * Categorías, marca y costo de un producto.
 */
final class ProductInfo {

	/** Taxonomías de marca conocidas, en orden de preferencia. */
	private const BRAND_TAXONOMIES = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_marca', 'pa_brand' );

	/** Metas de costo de plugins conocidos (si el COGS nativo no está activo). */
	private const COST_META_KEYS = array( '_wc_cog_cost', '_alg_wc_cog_cost', '_purchase_price' );

	/**
	 * Caché por petición.
	 *
	 * @var array<int, array{category_ids: list<string>, brand: ?string}>
	 */
	private static array $terms = array();

	/**
	 * IDs de categorías (de la madre si es variación).
	 *
	 * @param int $product_id ID del producto o variación.
	 * @return list<string>
	 */
	public static function category_ids( int $product_id ): array {
		return self::terms( $product_id )['category_ids'];
	}

	/**
	 * Marca, si el sitio usa una taxonomía de marcas conocida.
	 *
	 * @param int $product_id ID del producto o variación.
	 */
	public static function brand( int $product_id ): ?string {
		return self::terms( $product_id )['brand'];
	}

	/**
	 * Costo unitario del producto: COGS nativo de WooCommerce o metas conocidas.
	 *
	 * @param \WC_Product $product Producto.
	 */
	public static function cost( \WC_Product $product ): ?float {
		if ( method_exists( $product, 'get_cogs_value' ) && self::cogs_enabled() ) {
			$value = $product->get_cogs_value();
			if ( null !== $value && $value > 0 ) {
				return (float) $value;
			}
		}
		/**
		 * Metas de costo que se consultan si el COGS nativo no está activo.
		 *
		 * @param list<string> $keys Claves de meta.
		 */
		$keys = (array) apply_filters( 'igr_cost_meta_keys', self::COST_META_KEYS );
		foreach ( $keys as $key ) {
			$value = $product->get_meta( (string) $key, true );
			if ( is_numeric( $value ) && (float) $value > 0 ) {
				return (float) $value;
			}
		}
		return null;
	}

	/**
	 * ¿Está activo el costo de los productos (COGS) nativo de WooCommerce?
	 */
	public static function cogs_enabled(): bool {
		return 'yes' === get_option( 'woocommerce_feature_cost_of_goods_sold_enabled', 'no' );
	}

	/**
	 * Limpia la caché (procesos largos).
	 */
	public static function reset(): void {
		self::$terms = array();
	}

	/**
	 * Lee términos con caché.
	 *
	 * @param int $product_id ID.
	 * @return array{category_ids: list<string>, brand: ?string}
	 */
	private static function terms( int $product_id ): array {
		if ( isset( self::$terms[ $product_id ] ) ) {
			return self::$terms[ $product_id ];
		}
		$post_id = $product_id;
		$parent  = (int) wp_get_post_parent_id( $product_id );
		if ( $parent > 0 && 'product_variation' === get_post_type( $product_id ) ) {
			$post_id = $parent;
		}
		$cats  = wc_get_product_term_ids( $post_id, 'product_cat' );
		$brand = null;
		foreach ( self::BRAND_TAXONOMIES as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $post_id, $taxonomy );
			if ( is_array( $terms ) && array() !== $terms ) {
				$brand = mb_substr( (string) $terms[0]->name, 0, 120 );
				break;
			}
		}
		if ( count( self::$terms ) > 2000 ) {
			self::$terms = array();
		}
		self::$terms[ $product_id ] = array(
			'category_ids' => array_values( array_map( 'strval', $cats ) ),
			'brand'        => $brand,
		);
		return self::$terms[ $product_id ];
	}
}
