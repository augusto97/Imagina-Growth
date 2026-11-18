<?php
/**
 * Conteo independiente de pedidos y ventas en WooCommerce para conciliar con el hub (§5.3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Backfill;

use Automattic\WooCommerce\Utilities\OrderUtil;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Money;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Agregado de solo lectura directo en las tablas de pedidos (HPOS o entradas clásicas):
 * recorrer 10.000 pedidos con la API CRUD solo para sumarlos sería lento. Es la única
 * excepción a «siempre CRUD» y no escribe nada. Cada total se redondea a 2 decimales antes
 * de sumar, igual que el hub.
 */
final class Reconciliation {

	/**
	 * Calcula el bloque `reconciliation`.
	 *
	 * @param string $from Fecha `Y-m-d`.
	 * @return array{currency:string, generated_at:string, from:string, orders_by_status:array<string, array{count:int, total:string}>, orders:array{count:int, total:string}, refunds:array{count:int, total:string}}
	 */
	public static function compute( string $from ): array {
		$since    = gmdate( 'Y-m-d H:i:s', Scope::from_timestamp( $from ) );
		$statuses = Scope::statuses();
		$hpos     = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled();
		$rows     = $hpos ? self::hpos_orders( $since, $statuses ) : self::legacy_orders( $since, $statuses );
		$by       = array();
		$count    = 0;
		$total    = '0.00';
		foreach ( $rows as $row ) {
			$status        = str_starts_with( (string) $row['status'], 'wc-' ) ? substr( (string) $row['status'], 3 ) : (string) $row['status'];
			$sum           = Money::from_cents( Money::to_cents( (string) ( $row['total'] ?? '0' ) ) );
			$by[ $status ] = array(
				'count' => (int) $row['n'],
				'total' => $sum,
			);
			$count        += (int) $row['n'];
			$total         = Money::add( $total, $sum );
		}
		ksort( $by );
		$refunds = $hpos ? self::hpos_refunds( $since, $statuses ) : self::legacy_refunds( $since, $statuses );
		return array(
			'currency'         => get_woocommerce_currency(),
			'generated_at'     => Dates::now(),
			'from'             => $from,
			'orders_by_status' => $by,
			'orders'           => array(
				'count' => $count,
				'total' => $total,
			),
			'refunds'          => array(
				'count' => (int) ( $refunds['n'] ?? 0 ),
				'total' => Money::from_cents( Money::to_cents( (string) ( $refunds['total'] ?? '0' ) ) ),
			),
		);
	}

	/**
	 * HPOS: `wc_orders`.
	 *
	 * @param string       $since    Fecha UTC.
	 * @param list<string> $statuses Estados con `wc-`.
	 * @return list<array<string, mixed>>
	 */
	private static function hpos_orders( string $since, array $statuses ): array {
		global $wpdb;
		$table = OrderUtil::get_table_for_orders();
		$in    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT status, COUNT(*) AS n, SUM(ROUND(total_amount, 2)) AS total FROM {$table} WHERE type = 'shop_order' AND date_created_gmt >= %s AND status IN ({$in}) GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $since ), $statuses )
			),
			ARRAY_A
		);
	}

	/**
	 * Entradas clásicas: `posts` + meta `_order_total`.
	 *
	 * @param string       $since    Fecha UTC.
	 * @param list<string> $statuses Estados con `wc-`.
	 * @return list<array<string, mixed>>
	 */
	private static function legacy_orders( string $since, array $statuses ): array {
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.post_status AS status, COUNT(*) AS n, SUM(ROUND(CAST(m.meta_value AS DECIMAL(26,8)), 2)) AS total FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_order_total' WHERE p.post_type = 'shop_order' AND p.post_date_gmt >= %s AND p.post_status IN ({$in}) GROUP BY p.post_status", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $since ), $statuses )
			),
			ARRAY_A
		);
	}

	/**
	 * Reembolsos de los pedidos del rango (HPOS).
	 *
	 * @param string       $since    Fecha UTC.
	 * @param list<string> $statuses Estados del pedido padre.
	 * @return array<string, mixed>
	 */
	private static function hpos_refunds( string $since, array $statuses ): array {
		global $wpdb;
		$table = OrderUtil::get_table_for_orders();
		$in    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, SUM(ROUND(ABS(r.total_amount), 2)) AS total FROM {$table} r INNER JOIN {$table} p ON p.id = r.parent_order_id WHERE r.type = 'shop_order_refund' AND p.type = 'shop_order' AND p.date_created_gmt >= %s AND p.status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $since ), $statuses )
			),
			ARRAY_A
		);
	}

	/**
	 * Reembolsos de los pedidos del rango (entradas clásicas).
	 *
	 * @param string       $since    Fecha UTC.
	 * @param list<string> $statuses Estados del pedido padre.
	 * @return array<string, mixed>
	 */
	private static function legacy_refunds( string $since, array $statuses ): array {
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		return (array) $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS n, SUM(ROUND(ABS(CAST(m.meta_value AS DECIMAL(26,8))), 2)) AS total FROM {$wpdb->posts} r INNER JOIN {$wpdb->posts} p ON p.ID = r.post_parent LEFT JOIN {$wpdb->postmeta} m ON m.post_id = r.ID AND m.meta_key = '_refund_amount' WHERE r.post_type = 'shop_order_refund' AND p.post_type = 'shop_order' AND p.post_date_gmt >= %s AND p.post_status IN ({$in})", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				array_merge( array( $since ), $statuses )
			),
			ARRAY_A
		);
	}
}
