<?php
/**
 * `product.lookup` (docs/api-connector.md §6, Fase 2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;
use ImaginaGrowth\Connector\Support\Money;

/**
 * Precio y stock **en vivo** de productos publicados, por IDs o por búsqueda.
 * Lo usan los flujos (stock antes de un aviso) y el agente de ventas (Fase 4):
 * nunca devuelve productos privados, en borrador u ocultos del catálogo.
 */
final class ProductLookup implements Command {

	private const MAX_LIMIT      = 20;
	private const MAX_VARIATIONS = 20;

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			throw new CommandError( 'woocommerce_missing', 'WooCommerce no está activo', 409 );
		}
		$a      = new Args( $args );
		$ids    = $a->ids( 'ids', 50 );
		$search = $a->string( 'search', false, 100 );
		$limit  = $a->int( 'limit', 10, 1, self::MAX_LIMIT );
		if ( array() === $ids && null === $search ) {
			throw Args::invalid( 'ids', 'o search es obligatorio' );
		}
		if ( array() === $ids ) {
			$store = \WC_Data_Store::load( 'product' );
			$found = $store->search_products( wc_clean( (string) $search ), '', false, false, $limit * 3 );
			$ids   = array_values( array_filter( array_map( 'intval', (array) $found ) ) );
		}

		$products = array();
		foreach ( $ids as $id ) {
			$product = wc_get_product( $id );
			if ( ! $product instanceof \WC_Product || $product instanceof \WC_Product_Variation || ! self::visible( $product ) ) {
				continue;
			}
			$products[] = self::product( $product );
			if ( count( $products ) >= $limit ) {
				break;
			}
		}
		return array( 'products' => $products );
	}

	/**
	 * ¿Se puede mostrar a un cliente?
	 *
	 * @param \WC_Product $product Producto.
	 */
	private static function visible( \WC_Product $product ): bool {
		return 'publish' === $product->get_status() && '' === (string) $product->get_post_password() && 'hidden' !== $product->get_catalog_visibility();
	}

	/**
	 * Datos de un producto.
	 *
	 * @param \WC_Product $product Producto.
	 * @return array<string, mixed>
	 */
	private static function product( \WC_Product $product ): array {
		$variations = array();
		if ( $product instanceof \WC_Product_Variable ) {
			foreach ( array_slice( $product->get_children(), 0, self::MAX_VARIATIONS ) as $child_id ) {
				$variation = wc_get_product( $child_id );
				if ( ! $variation instanceof \WC_Product_Variation || 'publish' !== $variation->get_status() ) {
					continue;
				}
				$attributes = array();
				foreach ( $variation->get_variation_attributes( false ) as $name => $value ) {
					$attributes[ wc_attribute_label( (string) $name, $product ) ] = (string) $value;
				}
				$variations[] = array(
					'id'           => (string) $variation->get_id(),
					'attributes'   => (object) $attributes,
					'price'        => Money::format( $variation->get_price() ),
					'stock_status' => (string) $variation->get_stock_status(),
					'stock_qty'    => $variation->managing_stock() ? (int) $variation->get_stock_quantity() : null,
				);
			}
		}
		$image = (int) $product->get_image_id();
		$url   = $image > 0 ? wp_get_attachment_image_url( $image, 'woocommerce_thumbnail' ) : false;
		return array(
			'id'            => (string) $product->get_id(),
			'name'          => wp_strip_all_tags( (string) $product->get_name() ),
			'sku'           => '' !== (string) $product->get_sku() ? (string) $product->get_sku() : null,
			'price'         => Money::format( $product->get_price() ),
			'regular_price' => Money::format_or_null( '' !== (string) $product->get_regular_price() ? $product->get_regular_price() : null ),
			'sale_price'    => $product->is_on_sale() ? Money::format_or_null( $product->get_sale_price() ) : null,
			'stock_status'  => (string) $product->get_stock_status(),
			'stock_qty'     => $product->managing_stock() ? (int) $product->get_stock_quantity() : null,
			'url'           => (string) $product->get_permalink(),
			'image'         => is_string( $url ) ? $url : null,
			'variations'    => $variations,
		);
	}
}
