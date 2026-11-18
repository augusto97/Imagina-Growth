<?php
/**
 * Conciliación del catálogo contra el hub (`GET /v1/s/catalog/hashes`).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Catalog;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Recorre el catálogo por páginas, compara los hashes con los del hub, reenvía las diferencias
 * y emite los borrados de lo que el hub tiene y el sitio ya no. Se reanuda por pasos.
 */
final class CatalogSync {

	public const STATE_OPTION  = 'igr_catalog_sync';
	public const HASHES_OPTION = 'igr_catalog_hub_hashes';
	private const PAGE         = 200;

	/**
	 * Pide una conciliación (se ejecuta en Action Scheduler).
	 */
	public static function queue(): void {
		update_option(
			self::STATE_OPTION,
			array(
				'phase'      => 'fetch',
				'type'       => 'category',
				'cursor'     => 0,
				'started_at' => time(),
				'resent'     => 0,
				'deleted'    => 0,
			),
			false
		);
		Scheduler::single( Scheduler::RECONCILE_STEP, array(), 0 );
	}

	/**
	 * Avanza la conciliación en curso (acción `igr_catalog_reconcile_step`).
	 *
	 * @param int $budget_seconds Tiempo máximo.
	 */
	public static function run( int $budget_seconds = 20 ): void {
		$state   = get_option( self::STATE_OPTION );
		$factory = EventFactory::current();
		if ( null === $factory || ! is_array( $state ) || in_array( $state['phase'] ?? '', array( 'done', 'error' ), true ) ) {
			return;
		}
		$deadline = microtime( true ) + $budget_seconds;
		while ( microtime( true ) < $deadline && 'done' !== $state['phase'] ) {
			$state = self::step( $factory, $state );
			update_option( self::STATE_OPTION, $state, false );
			if ( 'error' === $state['phase'] ) {
				return;
			}
		}
		if ( 'done' !== $state['phase'] ) {
			Scheduler::next( Scheduler::RECONCILE_STEP, 5 );
		} else {
			delete_option( self::HASHES_OPTION );
			Scheduler::async( Scheduler::FLUSH_NOW );
		}
	}

	/**
	 * Un paso de la máquina de estados.
	 *
	 * @param EventFactory         $factory Fábrica.
	 * @param array<string, mixed> $state   Estado.
	 * @return array<string, mixed>
	 */
	private static function step( EventFactory $factory, array $state ): array {
		$type = (string) $state['type'];
		switch ( $state['phase'] ) {
			case 'fetch':
				$hashes = self::fetch_hashes( $type );
				if ( null === $hashes ) {
					$state['phase'] = 'error';
					return $state;
				}
				update_option( self::HASHES_OPTION, $hashes, false );
				$state['phase']  = 'compare';
				$state['cursor'] = 0;
				return $state;

			case 'compare':
				$hashes = get_option( self::HASHES_OPTION, array() );
				$hashes = is_array( $hashes ) ? $hashes : array();
				$ids    = 'category' === $type ? self::category_ids( (int) $state['cursor'] ) : self::product_ids( (int) $state['cursor'] );
				if ( array() === $ids ) {
					$state['phase'] = 'deletes';
					return $state;
				}
				foreach ( $ids as $id ) {
					$state['cursor'] = $id;
					$remote          = $hashes[ (string) $id ] ?? null;
					unset( $hashes[ (string) $id ] );
					if ( 'category' === $type ) {
						$term = get_term( $id, 'product_cat' );
						if ( $term instanceof \WP_Term ) {
							$props = ProductPayload::category( $term );
							if ( ProductPayload::category_hash( $props ) !== $remote ) {
								CatalogCollector::category( $factory, $term, true, 'server', true );
								++$state['resent'];
							}
						}
						continue;
					}
					$product = wc_get_product( $id );
					if ( $product instanceof \WC_Product && ProductPayload::from( $product )['hash'] !== $remote ) {
						CatalogCollector::upsert( $factory, $product, true, 'server', true );
						++$state['resent'];
					}
				}
				update_option( self::HASHES_OPTION, $hashes, false );
				return $state;

			case 'deletes':
				$hashes = get_option( self::HASHES_OPTION, array() );
				foreach ( array_keys( is_array( $hashes ) ? $hashes : array() ) as $id ) {
					$event = 'category' === $type ? 'catalog.category_deleted' : 'catalog.product_deleted';
					$key   = 'category' === $type ? 'category_id' : 'product_id';
					Recorder::record( $factory->make( $event, array( $key => (string) $id ) ) );
					++$state['deleted'];
				}
				if ( 'category' === $type ) {
					$state['type']  = 'product';
					$state['phase'] = 'fetch';
				} else {
					$state['phase']       = 'done';
					$state['finished_at'] = time();
				}
				return $state;
		}
		$state['phase'] = 'done';
		return $state;
	}

	/**
	 * Descarga todos los hashes que tiene el hub.
	 *
	 * @param string $type `product` o `category`.
	 * @return array<string, string>|null
	 */
	private static function fetch_hashes( string $type ): ?array {
		$out    = array();
		$cursor = '';
		for ( $i = 0; $i < 1000; $i++ ) {
			$url      = add_query_arg(
				array(
					'type'   => $type,
					'cursor' => $cursor,
					'limit'  => 1000,
				),
				Connection::api_url( 'v1/s/catalog/hashes' )
			);
			$response = Http::signed( 'GET', $url, null, Connection::site_id(), Connection::secret(), false, 15 );
			if ( ! $response->ok() || ! is_array( $response->json ) ) {
				Logger::warning( 'No se pudieron leer los hashes del catálogo: ' . $response->describe() );
				return null;
			}
			foreach ( (array) ( $response->json['items'] ?? array() ) as $item ) {
				if ( is_array( $item ) && isset( $item['id'], $item['hash'] ) ) {
					$out[ (string) $item['id'] ] = (string) $item['hash'];
				}
			}
			$cursor = $response->json['next_cursor'] ?? null;
			if ( ! is_string( $cursor ) || '' === $cursor ) {
				break;
			}
		}
		return $out;
	}

	/**
	 * IDs de productos y variaciones después del cursor.
	 *
	 * @param int $after Cursor.
	 * @return list<int>
	 */
	public static function product_ids( int $after, int $limit = self::PAGE ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending','future') AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after,
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}

	/**
	 * Conteo de productos y variaciones.
	 */
	public static function product_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ('product','product_variation') AND post_status IN ('publish','private','draft','pending','future')" );
	}

	/**
	 * IDs de categorías después del cursor.
	 *
	 * @param int $after Cursor.
	 * @return list<int>
	 */
	public static function category_ids( int $after, int $limit = self::PAGE ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'product_cat' AND t.term_id > %d ORDER BY t.term_id ASC LIMIT %d",
				$after,
				$limit
			)
		);
		return array_map( 'intval', $ids );
	}
}
