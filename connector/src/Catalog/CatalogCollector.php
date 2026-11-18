<?php
/**
 * Catálogo en tiempo real: productos, variaciones, stock, precios y categorías (connector/CLAUDE.md §7).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Catalog;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Money;

/**
 * Los cambios se agrupan por petición y se procesan al final (un guardado en el admin dispara
 * varios hooks). El hash de contenido evita reenviar lo que no cambió.
 */
final class CatalogCollector {

	public const HASH_META         = '_igr_hash';
	public const PRICE_META        = '_igr_price';
	public const STOCK_STATUS_META = '_igr_stock_status';

	/**
	 * Productos por procesar en esta petición.
	 *
	 * @var array<int, true>
	 */
	private static array $pending = array();

	/**
	 * Cambios de stock por procesar: id → [qty_changed, status_changed].
	 *
	 * @var array<int, true>
	 */
	private static array $stock = array();

	/**
	 * ¿Ya se registró el procesamiento al final de la petición?
	 *
	 * @var bool
	 */
	private static bool $shutdown_registered = false;

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		foreach ( array( 'woocommerce_new_product', 'woocommerce_update_product', 'woocommerce_new_product_variation', 'woocommerce_update_product_variation' ) as $hook ) {
			add_action( $hook, array( self::class, 'queue_product' ), 20, 1 );
		}
		add_action( 'untrashed_post', array( self::class, 'queue_post' ), 20, 1 );
		add_action( 'before_delete_post', array( self::class, 'on_delete' ), 20, 1 );
		add_action( 'wp_trash_post', array( self::class, 'on_delete' ), 20, 1 );

		add_action( 'woocommerce_product_set_stock', array( self::class, 'on_stock' ), 20, 1 );
		add_action( 'woocommerce_variation_set_stock', array( self::class, 'on_stock' ), 20, 1 );
		add_action( 'woocommerce_product_set_stock_status', array( self::class, 'on_stock_status' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( self::class, 'on_stock_status' ), 20, 3 );

		add_action( 'created_product_cat', array( self::class, 'on_category' ), 20, 1 );
		add_action( 'edited_product_cat', array( self::class, 'on_category' ), 20, 1 );
		add_action( 'delete_product_cat', array( self::class, 'on_category_deleted' ), 20, 1 );
	}

	/**
	 * Encola un producto.
	 *
	 * @param mixed $product_id ID.
	 */
	public static function queue_product( mixed $product_id ): void {
		if ( Recorder::is_suppressed() ) {
			return;
		}
		self::$pending[ (int) $product_id ] = true;
		self::ensure_shutdown();
	}

	/**
	 * Producto restaurado de la papelera.
	 *
	 * @param mixed $post_id ID.
	 */
	public static function queue_post( mixed $post_id ): void {
		if ( in_array( get_post_type( (int) $post_id ), array( 'product', 'product_variation' ), true ) ) {
			delete_post_meta( (int) $post_id, self::HASH_META );
			self::queue_product( $post_id );
		}
	}

	/**
	 * Producto borrado o enviado a la papelera.
	 *
	 * @param mixed $post_id ID.
	 */
	public static function on_delete( mixed $post_id ): void {
		$post_id = (int) $post_id;
		if ( ! in_array( get_post_type( $post_id ), array( 'product', 'product_variation' ), true ) || Recorder::is_suppressed() ) {
			return;
		}
		unset( self::$pending[ $post_id ] );
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		Recorder::record( $factory->make( 'catalog.product_deleted', array( 'product_id' => (string) $post_id ) ) );
		delete_post_meta( $post_id, self::HASH_META );
	}

	/**
	 * Cambió la cantidad en stock.
	 *
	 * @param mixed $product Producto.
	 */
	public static function on_stock( mixed $product ): void {
		if ( $product instanceof \WC_Product && ! Recorder::is_suppressed() ) {
			self::$stock[ (int) $product->get_id() ] = true;
			self::ensure_shutdown();
		}
	}

	/**
	 * Cambió el estado de stock.
	 *
	 * @param mixed $product_id ID.
	 * @param mixed $status     Estado.
	 * @param mixed $product    Producto.
	 */
	public static function on_stock_status( mixed $product_id, mixed $status = null, mixed $product = null ): void {
		if ( ! Recorder::is_suppressed() ) {
			self::$stock[ (int) $product_id ] = true;
			self::ensure_shutdown();
		}
	}

	/**
	 * Procesa lo acumulado (al final de la petición, o a mano en pruebas y CLI).
	 */
	public static function flush(): void {
		$factory       = EventFactory::current();
		$pending       = array_keys( self::$pending );
		$stock         = array_keys( self::$stock );
		self::$pending = array();
		self::$stock   = array();
		if ( null === $factory ) {
			return;
		}
		foreach ( $stock as $id ) {
			try {
				self::stock_changed( $factory, $id );
			} catch ( \Throwable $e ) {
				Logger::error( 'Error al registrar el stock: ' . $e->getMessage(), array( 'product' => $id ) );
			}
		}
		foreach ( $pending as $id ) {
			try {
				$product = wc_get_product( $id );
				if ( $product instanceof \WC_Product ) {
					self::upsert( $factory, $product );
				}
			} catch ( \Throwable $e ) {
				Logger::error( 'Error al registrar el producto: ' . $e->getMessage(), array( 'product' => $id ) );
			}
		}
	}

	/**
	 * Registra `catalog.product_upserted` si el hash cambió (y `catalog.price_changed` si cambió el precio).
	 *
	 * @param EventFactory $factory Fábrica.
	 * @param \WC_Product  $product Producto.
	 * @param bool         $force   Enviar aunque el hash no haya cambiado.
	 * @param string       $type    `server` o `import`.
	 * @param bool         $fresh_id Usar un event_id nuevo (conciliación: reenviar algo que el hub no tiene).
	 * @return bool true si se registró.
	 */
	public static function upsert( EventFactory $factory, \WC_Product $product, bool $force = false, string $type = 'server', bool $fresh_id = false ): bool {
		if ( 'auto-draft' === $product->get_status() || 'trash' === $product->get_status() ) {
			return false;
		}
		$id    = (int) $product->get_id();
		$props = ProductPayload::from( $product );
		$prev  = (string) get_post_meta( $id, self::HASH_META, true );
		if ( ! $force && $prev === $props['hash'] ) {
			return false;
		}
		$recorded  = Recorder::record(
			$factory->make(
				'catalog.product_upserted',
				$props,
				array(
					'type'        => $type,
					'occurred_at' => 'import' === $type ? $product->get_date_modified() : null,
					'key'         => $fresh_id ? null : 'product:' . $id . ':' . $props['hash'],
				)
			)
		);
		$old_price = get_post_meta( $id, self::PRICE_META, true );
		if ( 'server' === $type && '' !== (string) $old_price && (string) $old_price !== $props['price'] ) {
			Recorder::record(
				$factory->make(
					'catalog.price_changed',
					array(
						'product_id' => (string) $id,
						'from'       => Money::format( $old_price ),
						'to'         => $props['price'],
						'currency'   => $props['currency'],
					)
				)
			);
		}
		update_post_meta( $id, self::HASH_META, $props['hash'] );
		update_post_meta( $id, self::PRICE_META, $props['price'] );
		update_post_meta( $id, self::STOCK_STATUS_META, $props['stock_status'] );
		return $recorded || $force;
	}

	/**
	 * Registra `catalog.stock_changed`.
	 *
	 * @param EventFactory $factory Fábrica.
	 * @param int          $id      Producto.
	 */
	private static function stock_changed( EventFactory $factory, int $id ): void {
		$product = wc_get_product( $id );
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$from = (string) get_post_meta( $id, self::STOCK_STATUS_META, true );
		$to   = (string) $product->get_stock_status();
		$qty  = $product->managing_stock() ? $product->get_stock_quantity() : null;
		Recorder::record(
			$factory->make(
				'catalog.stock_changed',
				array(
					'product_id'  => (string) $id,
					'from_status' => '' !== $from ? $from : null,
					'to_status'   => $to,
					'qty'         => null === $qty ? null : ( (float) (int) $qty === (float) $qty ? (int) $qty : (float) $qty ),
				)
			)
		);
		update_post_meta( $id, self::STOCK_STATUS_META, $to );
	}

	/**
	 * Categoría creada o editada.
	 *
	 * @param mixed $term_id ID.
	 */
	public static function on_category( mixed $term_id ): void {
		if ( Recorder::is_suppressed() ) {
			return;
		}
		$term    = get_term( (int) $term_id, 'product_cat' );
		$factory = EventFactory::current();
		if ( $term instanceof \WP_Term && null !== $factory ) {
			self::category( $factory, $term );
		}
	}

	/**
	 * Registra `catalog.category_upserted` si cambió.
	 *
	 * @param EventFactory $factory  Fábrica.
	 * @param \WP_Term     $term     Término.
	 * @param bool         $force    Enviar aunque no haya cambiado.
	 * @param string       $type     `server` o `import`.
	 * @param bool         $fresh_id Usar un event_id nuevo.
	 */
	public static function category( EventFactory $factory, \WP_Term $term, bool $force = false, string $type = 'server', bool $fresh_id = false ): bool {
		$props = ProductPayload::category( $term );
		$hash  = ProductPayload::category_hash( $props );
		if ( ! $force && get_term_meta( $term->term_id, self::HASH_META, true ) === $hash ) {
			return false;
		}
		$ok = Recorder::record(
			$factory->make(
				'catalog.category_upserted',
				$props + array( 'hash' => $hash ),
				array(
					'type' => $type,
					'key'  => $fresh_id ? null : 'category:' . $term->term_id . ':' . $hash,
				)
			)
		);
		update_term_meta( $term->term_id, self::HASH_META, $hash );
		return $ok || $force;
	}

	/**
	 * Categoría borrada.
	 *
	 * @param mixed $term_id ID.
	 */
	public static function on_category_deleted( mixed $term_id ): void {
		$factory = EventFactory::current();
		if ( null !== $factory && ! Recorder::is_suppressed() ) {
			Recorder::record( $factory->make( 'catalog.category_deleted', array( 'category_id' => (string) (int) $term_id ) ) );
		}
	}

	/**
	 * Registra el procesamiento al final de la petición.
	 */
	private static function ensure_shutdown(): void {
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			add_action( 'shutdown', array( self::class, 'flush' ), 5 );
		}
	}
}
