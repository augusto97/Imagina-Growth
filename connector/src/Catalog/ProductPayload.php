<?php
/**
 * Propiedades de `catalog.product_upserted` y `catalog.category_upserted`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Catalog;

use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Money;

/**
 * Arma el producto completo y su hash de contenido.
 */
final class ProductPayload {

	/**
	 * Propiedades del producto.
	 *
	 * @param \WC_Product $product Producto o variación.
	 * @return array<string, mixed>
	 */
	public static function from( \WC_Product $product ): array {
		$id        = (int) $product->get_id();
		$parent_id = (int) $product->get_parent_id();
		$image_id  = (int) $product->get_image_id();
		if ( 0 === $image_id && $parent_id > 0 ) {
			$parent   = wc_get_product( $parent_id );
			$image_id = $parent instanceof \WC_Product ? (int) $parent->get_image_id() : 0;
		}
		$image = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'woocommerce_single' ) : false;
		$price = (string) $product->get_price( 'edit' );
		if ( '' === $price && $product->is_type( 'variable' ) && $product instanceof \WC_Product_Variable ) {
			$price = (string) $product->get_variation_price( 'min' );
		}
		$cost  = ProductInfo::cost( $product );
		$props = array(
			'product_id'    => (string) $id,
			'parent_id'     => $parent_id > 0 ? (string) $parent_id : null,
			'type'          => (string) $product->get_type(),
			'sku'           => '' !== (string) $product->get_sku( 'edit' ) ? mb_substr( (string) $product->get_sku( 'edit' ), 0, 100 ) : null,
			'name'          => mb_substr( wp_strip_all_tags( (string) $product->get_name() ), 0, 300 ),
			'slug'          => mb_substr( (string) $product->get_slug(), 0, 300 ),
			'permalink'     => mb_substr( (string) $product->get_permalink(), 0, 2048 ),
			'image_url'     => is_string( $image ) ? mb_substr( $image, 0, 2048 ) : null,
			'category_ids'  => ProductInfo::category_ids( $id ),
			'brand'         => ProductInfo::brand( $id ),
			'price'         => Money::format( $price ),
			'regular_price' => Money::format_or_null( $product->get_regular_price( 'edit' ) ),
			'sale_price'    => Money::format_or_null( $product->get_sale_price( 'edit' ) ),
			'stock_status'  => mb_substr( (string) $product->get_stock_status(), 0, 20 ),
			'stock_qty'     => $product->managing_stock() ? (float) $product->get_stock_quantity() : null,
			'rating_avg'    => round( (float) $product->get_average_rating(), 2 ),
			'rating_count'  => (int) $product->get_rating_count(),
			'status'        => mb_substr( (string) $product->get_status(), 0, 20 ),
			'cost'          => null === $cost ? null : Money::format( $cost ),
			'currency'      => get_woocommerce_currency(),
			'created_at'    => Dates::iso( $product->get_date_created() ),
			'modified_at'   => Dates::iso( $product->get_date_modified() ),
		);
		if ( is_float( $props['stock_qty'] ) && floor( $props['stock_qty'] ) === $props['stock_qty'] ) {
			$props['stock_qty'] = (int) $props['stock_qty'];
		}
		$props['hash'] = self::hash( $props );
		return $props;
	}

	/**
	 * Hash del contenido relevante. Excluye la cantidad en stock (va en `catalog.stock_changed`)
	 * y las fechas, para no reenviar el producto con cada venta.
	 *
	 * @param array<string, mixed> $props Propiedades.
	 */
	public static function hash( array $props ): string {
		unset( $props['hash'], $props['stock_qty'], $props['modified_at'], $props['created_at'] );
		ksort( $props );
		return substr( hash( 'sha256', Json::encode( $props ) ), 0, 32 );
	}

	/**
	 * Propiedades de una categoría.
	 *
	 * @param \WP_Term $term Término `product_cat`.
	 * @return array<string, mixed>
	 */
	public static function category( \WP_Term $term ): array {
		return array(
			'category_id' => (string) $term->term_id,
			'parent_id'   => $term->parent > 0 ? (string) $term->parent : null,
			'name'        => mb_substr( html_entity_decode( (string) $term->name, ENT_QUOTES ), 0, 200 ),
			'slug'        => mb_substr( (string) $term->slug, 0, 200 ),
		);
	}

	/**
	 * Hash de una categoría.
	 *
	 * @param array<string, mixed> $props Propiedades.
	 */
	public static function category_hash( array $props ): string {
		ksort( $props );
		return substr( hash( 'sha256', Json::encode( $props ) ), 0, 32 );
	}
}
