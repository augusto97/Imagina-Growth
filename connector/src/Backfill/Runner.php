<?php
/**
 * Importación histórica (connector/CLAUDE.md §7, docs/api-connector.md §5.3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Backfill;

use ImaginaGrowth\Connector\Catalog\CatalogCollector;
use ImaginaGrowth\Connector\Catalog\CatalogSync;
use ImaginaGrowth\Connector\Catalog\ProductInfo;
use ImaginaGrowth\Connector\Collectors\CustomerCollector;
use ImaginaGrowth\Connector\Collectors\ReviewCollector;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\OrderEvents;
use ImaginaGrowth\Connector\Events\OrderPayload;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Scheduler;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Money;

/**
 * Productos → categorías → clientes → pedidos → reembolsos → reseñas → suscripciones,
 * en lotes de 200 cada 10 s, con estado reanudable en `igr_backfill_state`.
 * Todos los eventos llevan `source.type = import` y los mismos `event_id` que en vivo.
 */
final class Runner {

	public const OPTION = 'igr_backfill_state';

	public const PHASES = array( 'products', 'categories', 'customers', 'orders', 'refunds', 'reviews', 'subscriptions' );

	private const DEFAULT_BATCH = 200;
	private const DEFAULT_DELAY = 10;

	/**
	 * Estado actual.
	 *
	 * @return array<string, mixed>
	 */
	public static function state(): array {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) ? $state : array();
	}

	/**
	 * Inicia (o reinicia) la importación. No hace HTTP: el primer paso corre en Action Scheduler.
	 *
	 * @param string|null $from    Fecha `Y-m-d` (por defecto, hoy − meses configurados).
	 * @param bool        $restart Reiniciar aunque haya una en curso o terminada.
	 * @param array{batch_size?:int, delay?:int} $options Opciones.
	 * @return bool false si ya hay una en curso y no se pidió reiniciar.
	 */
	public static function start( ?string $from = null, bool $restart = false, array $options = array() ): bool {
		$current = self::state();
		if ( ! $restart && in_array( $current['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return false;
		}
		if ( null === $from || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) ) {
			$from = Scope::default_from( RemoteConfig::backfill_months() );
		}
		$state = array(
			'status'         => 'queued',
			'from'           => $from,
			'started_at'     => Dates::now(),
			'finished_at'    => null,
			'phase'          => self::PHASES[0],
			'cursor'         => 0,
			'entities'       => self::totals( $from ),
			'reconciliation' => function_exists( 'wc_get_order_statuses' ) ? Reconciliation::compute( $from ) : null,
			'counted'        => array(),
			'error'          => null,
			'batch_size'     => max( 1, (int) ( $options['batch_size'] ?? self::DEFAULT_BATCH ) ),
			'delay'          => max( 0, (int) ( $options['delay'] ?? self::DEFAULT_DELAY ) ),
			'run_id'         => wp_generate_password( 12, false ),
		);
		// Los totales de pedidos y reembolsos siguen el mismo filtro que la conciliación.
		if ( is_array( $state['reconciliation'] ) ) {
			$state['entities']['orders']['total']  = (int) $state['reconciliation']['orders']['count'];
			$state['entities']['refunds']['total'] = (int) $state['reconciliation']['refunds']['count'];
		}
		update_option( self::OPTION, $state, false );
		Scheduler::next( Scheduler::BACKFILL, 0 );
		return true;
	}

	/**
	 * Pausa o reanuda.
	 *
	 * @param bool $pause Pausar.
	 */
	public static function pause( bool $pause ): void {
		$state = self::state();
		if ( array() === $state ) {
			return;
		}
		if ( $pause && in_array( $state['status'], array( 'queued', 'running' ), true ) ) {
			$state['status'] = 'paused';
		} elseif ( ! $pause && 'paused' === $state['status'] ) {
			$state['status'] = 'running';
			Scheduler::next( Scheduler::BACKFILL, 0 );
		}
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Acción `igr_backfill_step`: procesa un lote y programa el siguiente.
	 *
	 * @param bool $schedule_next Programar el siguiente paso (false en `wp igr backfill --now`).
	 * @return array<string, mixed> Estado después del paso.
	 */
	public static function step( bool $schedule_next = true ): array {
		$state   = self::state();
		$factory = EventFactory::current();
		if ( null === $factory || ! in_array( $state['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return $state;
		}
		if ( ! self::lock() ) {
			return $state;
		}
		try {
			if ( $schedule_next && self::overloaded() ) {
				$state['throttled_at'] = time();
				update_option( self::OPTION, $state, false );
				Scheduler::next( Scheduler::BACKFILL, MINUTE_IN_SECONDS );
				return $state;
			}
			$state['status'] = 'running';
			$state           = self::process( $factory, $state );
		} catch ( \Throwable $e ) {
			$state['status'] = 'failed';
			$state['error']  = mb_substr( $e->getMessage(), 0, 500 );
			Logger::error( 'La importación histórica falló: ' . $e->getMessage() );
		} finally {
			self::unlock();
		}
		update_option( self::OPTION, $state, false );

		if ( 'running' === $state['status'] && $schedule_next ) {
			Scheduler::next( Scheduler::BACKFILL, (int) $state['delay'] );
		}
		if ( $schedule_next ) {
			Scheduler::async( Scheduler::FLUSH_NOW );
		}
		self::report( $state );
		return $state;
	}

	/**
	 * Procesa un lote de la fase actual.
	 *
	 * @param EventFactory         $factory Fábrica.
	 * @param array<string, mixed> $state   Estado.
	 * @return array<string, mixed>
	 */
	private static function process( EventFactory $factory, array $state ): array {
		$batch = (int) $state['batch_size'];
		$phase = (string) $state['phase'];
		$done  = 0;
		$more  = false;

		switch ( $phase ) {
			case 'products':
				$ids = CatalogSync::product_ids( (int) $state['cursor'], $batch );
				foreach ( $ids as $id ) {
					$product = wc_get_product( $id );
					if ( $product instanceof \WC_Product ) {
						CatalogCollector::upsert( $factory, $product, true, 'import' );
					}
					$state['cursor'] = $id;
					++$done;
				}
				$more = count( $ids ) === $batch;
				break;

			case 'categories':
				$ids = CatalogSync::category_ids( (int) $state['cursor'], $batch );
				foreach ( $ids as $id ) {
					$term = get_term( $id, 'product_cat' );
					if ( $term instanceof \WP_Term ) {
						CatalogCollector::category( $factory, $term, true, 'import' );
					}
					$state['cursor'] = $id;
					++$done;
				}
				$more = count( $ids ) === $batch;
				break;

			case 'customers':
				$page  = (int) $state['cursor'] + 1;
				$query = new \WP_User_Query( self::customer_query( $batch, $page ) );
				$ids   = array_map( 'intval', (array) $query->get_results() );
				cache_users( $ids );
				foreach ( $ids as $id ) {
					Recorder::record( CustomerCollector::registered( $factory, $id, true ) );
					++$done;
				}
				$state['cursor'] = $page;
				$more            = count( $ids ) === $batch;
				break;

			case 'orders':
				$page   = (int) $state['cursor'] + 1;
				$result = wc_get_orders( self::order_query( (string) $state['from'], 'shop_order', $batch, $page ) );
				$orders = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : array();
				$events = new OrderEvents( $factory );
				foreach ( $orders as $order ) {
					if ( ! $order instanceof \WC_Order ) {
						continue;
					}
					$payload = OrderPayload::from( $order );
					Recorder::record( $events->created( $order, true, $payload ) );
					if ( self::was_paid( $order ) ) {
						Recorder::record( $events->paid( $order, true, $payload ) );
					}
					$status                               = (string) $order->get_status();
					$state['counted'][ $status ]['count'] = (int) ( $state['counted'][ $status ]['count'] ?? 0 ) + 1;
					$state['counted'][ $status ]['total'] = Money::add( (string) ( $state['counted'][ $status ]['total'] ?? '0.00' ), $payload['totals']['total'] );
					++$done;
				}
				$state['cursor'] = $page;
				$more            = is_object( $result ) && $page < (int) ( $result->max_num_pages ?? 0 );
				break;

			case 'refunds':
				$page    = (int) $state['cursor'] + 1;
				$result  = wc_get_orders( self::order_query( (string) $state['from'], 'shop_order_refund', $batch, $page ) );
				$refunds = is_object( $result ) && isset( $result->orders ) ? (array) $result->orders : array();
				$events  = new OrderEvents( $factory );
				$from_ts = Scope::from_timestamp( (string) $state['from'] );
				$valid   = array_map( static fn( string $s ): string => substr( $s, 3 ), Scope::statuses() );
				foreach ( $refunds as $refund ) {
					if ( ! $refund instanceof \WC_Order_Refund ) {
						continue;
					}
					$parent  = wc_get_order( $refund->get_parent_id() );
					$created = $parent instanceof \WC_Order ? $parent->get_date_created() : null;
					if ( ! $parent instanceof \WC_Order || null === $created || $created->getTimestamp() < $from_ts || ! in_array( $parent->get_status(), $valid, true ) ) {
						continue;
					}
					// La foto del pedido ya viajó en `order.created` con el total reembolsado.
					Recorder::record( $events->refunded( $parent, $refund, true, false ) );
					++$done;
				}
				$state['cursor'] = $page;
				$more            = is_object( $result ) && $page < (int) ( $result->max_num_pages ?? 0 );
				break;

			case 'reviews':
				$page     = (int) $state['cursor'] + 1;
				$comments = get_comments(
					array(
						'type'    => 'review',
						'status'  => 'approve',
						'orderby' => 'comment_ID',
						'order'   => 'ASC',
						'number'  => $batch,
						'paged'   => $page,
					)
				);
				foreach ( (array) $comments as $comment ) {
					if ( $comment instanceof \WP_Comment ) {
						$envelope = ReviewCollector::envelope( $factory, $comment, true );
						if ( null !== $envelope ) {
							Recorder::record( $envelope );
						}
						++$done;
					}
				}
				$state['cursor'] = $page;
				$more            = count( (array) $comments ) === $batch;
				break;

			case 'subscriptions':
				$done = self::subscriptions( $factory, $state, $batch, $more );
				break;
		}

		$state['entities'][ $phase ]['sent'] = (int) ( $state['entities'][ $phase ]['sent'] ?? 0 ) + $done;
		ProductInfo::reset();

		if ( ! $more ) {
			$index = array_search( $phase, self::PHASES, true );
			$next  = false === $index ? null : ( self::PHASES[ $index + 1 ] ?? null );
			if ( null === $next ) {
				$state['status']      = 'completed';
				$state['phase']       = 'done';
				$state['finished_at'] = Dates::now();
				// La conciliación se recalcula al terminar: el hub compara contra este corte.
				if ( function_exists( 'wc_get_order_statuses' ) ) {
					$state['reconciliation'] = Reconciliation::compute( (string) $state['from'] );
				}
				$state = self::cross_check( $state );
			} else {
				$state['phase']  = $next;
				$state['cursor'] = 0;
			}
		}
		return $state;
	}

	/**
	 * ¿El pedido ya se pagó? (Mismo criterio que en vivo; un pedido reembolsado se pagó.)
	 *
	 * @param \WC_Order $order Pedido.
	 */
	private static function was_paid( \WC_Order $order ): bool {
		$status = (string) $order->get_status();
		if ( RemoteConfig::is_paid( $status, (string) $order->get_payment_method() ) ) {
			return true;
		}
		return 'refunded' === $status && null !== $order->get_date_paid();
	}

	/**
	 * Suscripciones (solo con WooCommerce Subscriptions).
	 *
	 * @param EventFactory         $factory Fábrica.
	 * @param array<string, mixed> $state   Estado (se actualiza el cursor).
	 * @param int                  $batch   Tamaño.
	 * @param bool                 $more    ¿Hay más?
	 */
	private static function subscriptions( EventFactory $factory, array &$state, int $batch, bool &$more ): int {
		if ( ! function_exists( 'wcs_get_subscriptions' ) ) {
			$more = false;
			return 0;
		}
		$page = (int) $state['cursor'] + 1;
		$subs = wcs_get_subscriptions(
			array(
				'subscriptions_per_page' => $batch,
				'paged'                  => $page,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
			)
		);
		$done = 0;
		foreach ( (array) $subs as $sub ) {
			if ( ! is_object( $sub ) || ! method_exists( $sub, 'get_id' ) ) {
				continue;
			}
			$next = method_exists( $sub, 'get_date' ) ? $sub->get_date( 'next_payment' ) : '';
			Recorder::record(
				$factory->make(
					'subscription.created',
					array(
						'subscription_id' => (string) $sub->get_id(),
						'status'          => (string) $sub->get_status(),
						'next_payment_at' => $next ? Dates::iso( $next . ' UTC' ) : null,
						'total'           => Money::format( $sub->get_total() ),
						'currency'        => (string) $sub->get_currency(),
						'billing_period'  => method_exists( $sub, 'get_billing_period' ) ? (string) $sub->get_billing_period() : null,
						'customer_ref'    => $sub->get_customer_id() ? 'wc:' . $sub->get_customer_id() : null,
						'parent_order_id' => $sub->get_parent_id() ? (string) $sub->get_parent_id() : null,
					),
					array(
						'type'        => 'import',
						'occurred_at' => $sub->get_date_created(),
						'key'         => 'subscription:' . $sub->get_id() . ':created',
						'identity'    => array( 'customer_ref' => $sub->get_customer_id() ? 'wc:' . $sub->get_customer_id() : null ),
					)
				)
			);
			++$done;
		}
		$state['cursor'] = $page;
		$more            = count( (array) $subs ) === $batch;
		return $done;
	}

	/**
	 * Categorías de producto. Sin WooCommerce la taxonomía no existe y wp_count_terms() devuelve un WP_Error:
	 * un sitio corporativo no tiene categorías que importar.
	 */
	private static function category_count(): int {
		if ( ! taxonomy_exists( 'product_cat' ) ) {
			return 0;
		}
		$count = wp_count_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		return is_wp_error( $count ) ? 0 : (int) $count;
	}

	/**
	 * Totales por entidad al empezar.
	 *
	 * @param string $from Fecha.
	 * @return array<string, array{total:int, sent:int}>
	 */
	private static function totals( string $from ): array {
		$wc    = function_exists( 'wc_get_orders' );
		$count = static function ( string $type ) use ( $from ): int {
			$query           = self::order_query( $from, $type, 1, 1 );
			$query['return'] = 'ids';
			$result          = wc_get_orders( $query );
			return is_object( $result ) ? (int) ( $result->total ?? 0 ) : 0;
		};
		$users = new \WP_User_Query( array_merge( self::customer_query( 1, 1 ), array( 'count_total' => true ) ) );
		return array(
			'products'      => array(
				'total' => CatalogSync::product_count(),
				'sent'  => 0,
			),
			'categories'    => array(
				'total' => self::category_count(),
				'sent'  => 0,
			),
			'customers'     => array(
				'total' => (int) $users->get_total(),
				'sent'  => 0,
			),
			'orders'        => array(
				'total' => $wc ? $count( 'shop_order' ) : 0,
				'sent'  => 0,
			),
			'refunds'       => array(
				'total' => $wc ? $count( 'shop_order_refund' ) : 0,
				'sent'  => 0,
			),
			'reviews'       => array(
				'total' => (int) get_comments(
					array(
						'type'   => 'review',
						'status' => 'approve',
						'count'  => true,
					)
				),
				'sent'  => 0,
			),
			'subscriptions' => array(
				'total' => 0,
				'sent'  => 0,
			),
		);
	}

	/**
	 * Consulta de pedidos o reembolsos del rango, paginada por ID.
	 *
	 * @param string $from  Fecha.
	 * @param string $type  `shop_order` o `shop_order_refund`.
	 * @param int    $limit Tamaño de página.
	 * @param int    $page  Página.
	 * @return array<string, mixed>
	 */
	private static function order_query( string $from, string $type, int $limit, int $page ): array {
		return array(
			'type'         => $type,
			'status'       => 'shop_order' === $type ? Scope::statuses() : array_keys( wc_get_order_statuses() ),
			'date_created' => '>=' . Scope::from_timestamp( $from ),
			'orderby'      => 'ID',
			'order'        => 'ASC',
			'limit'        => $limit,
			'paged'        => $page,
			'paginate'     => true,
		);
	}

	/**
	 * Consulta de clientes.
	 *
	 * @param int $limit Tamaño de página.
	 * @param int $page  Página.
	 * @return array<string, mixed>
	 */
	private static function customer_query( int $limit, int $page ): array {
		return array(
			'role__in'    => (array) apply_filters( 'igr_customer_roles', array( 'customer', 'subscriber' ) ),
			'orderby'     => 'ID',
			'order'       => 'ASC',
			'number'      => $limit,
			'paged'       => $page,
			'fields'      => 'ID',
			'count_total' => false,
		);
	}

	/**
	 * Verifica que lo enviado coincide con el conteo independiente.
	 *
	 * @param array<string, mixed> $state Estado.
	 * @return array<string, mixed>
	 */
	private static function cross_check( array $state ): array {
		$expected = $state['reconciliation']['orders_by_status'] ?? null;
		if ( ! is_array( $expected ) ) {
			return $state;
		}
		$counted = (array) ( $state['counted'] ?? array() );
		ksort( $counted );
		$diff = array();
		foreach ( array_unique( array_merge( array_keys( $expected ), array_keys( $counted ) ) ) as $status ) {
			$a = $expected[ $status ] ?? array(
				'count' => 0,
				'total' => '0.00',
			);
			$b = $counted[ $status ] ?? array(
				'count' => 0,
				'total' => '0.00',
			);
			if ( (int) $a['count'] !== (int) $b['count'] || Money::to_cents( (string) $a['total'] ) !== Money::to_cents( (string) $b['total'] ) ) {
				$diff[ $status ] = array(
					'expected' => $a,
					'sent'     => $b,
				);
			}
		}
		if ( array() !== $diff ) {
			$state['warning'] = 'Lo enviado no coincide con el conteo de WooCommerce (pedidos creados o borrados durante la importación).';
			$state['diff']    = $diff;
			Logger::warning( 'Diferencias entre lo importado y el conteo de WooCommerce', array( 'statuses' => array_keys( $diff ) ) );
		}
		return $state;
	}

	/**
	 * Informa el progreso al hub (`PUT /v1/s/backfill`). Solo desde el job.
	 *
	 * @param array<string, mixed> $state Estado.
	 */
	public static function report( array $state ): void {
		if ( ! Connection::is_connected() || array() === $state ) {
			return;
		}
		$body     = array(
			'status'         => $state['status'],
			'from'           => $state['from'],
			'started_at'     => $state['started_at'],
			'finished_at'    => $state['finished_at'],
			'phase'          => $state['phase'],
			'entities'       => $state['entities'],
			'reconciliation' => $state['reconciliation'],
			'error'          => $state['error'],
		);
		$response = Http::signed( 'PUT', Connection::api_url( 'v1/s/backfill' ), Json::encode( $body ), Connection::site_id(), Connection::secret(), false, 10 );
		if ( ! $response->ok() ) {
			Logger::warning( 'No se pudo informar el progreso de la importación: ' . $response->describe() );
		}
	}

	/**
	 * ¿La carga del servidor es alta?
	 */
	private static function overloaded(): bool {
		if ( ! function_exists( 'sys_getloadavg' ) ) {
			return false;
		}
		$load = sys_getloadavg();
		if ( ! is_array( $load ) ) {
			return false;
		}
		$cpus = 2;
		if ( is_readable( '/proc/cpuinfo' ) ) {
			$cpuinfo = (string) file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$cpus    = max( 1, substr_count( $cpuinfo, 'processor' ) );
		}
		/**
		 * Carga máxima (promedio de 1 minuto) antes de pausar la importación.
		 *
		 * @param float $max Carga máxima.
		 */
		$max = (float) apply_filters( 'igr_backfill_max_load', $cpus * 1.5 );
		return (float) $load[0] > $max;
	}

	/**
	 * Bloqueo para no correr dos pasos a la vez.
	 */
	private static function lock(): bool {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', 'igr_backfill_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Libera el bloqueo.
	 */
	private static function unlock(): void {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'igr_backfill_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
