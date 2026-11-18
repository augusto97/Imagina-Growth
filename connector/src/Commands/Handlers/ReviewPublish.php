<?php
/**
 * `review.publish` (docs/api-connector.md §6, Fase 3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Json;

/**
 * Publica una reseña recogida por el hub como reseña nativa de WooCommerce: comentario
 * `review` aprobado con las metas `rating`, `verified`, `igr_review_id`, `igr_media` e
 * `igr_title`. Idempotente por `review_id`: si ya existe devuelve la misma (y la vuelve a
 * aprobar si se había despublicado).
 */
final class ReviewPublish implements Command {

	public const META_ID = 'igr_review_id';

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		$a          = new Args( $args );
		$review_id  = (string) $a->string( 'review_id', true, 64, '/^rv_[A-Za-z0-9_-]{1,60}$/' );
		$product_id = (int) $a->string( 'product_id', true, 20, '/^[1-9]\d*$/' );
		$rating     = $a->int( 'rating', 0, 1, 5 );
		if ( 0 === $rating ) {
			throw Args::invalid( 'rating', 'es obligatorio' );
		}
		$title    = $a->string( 'title', false, 200, null, 0 );
		$body     = (string) $a->string( 'body', false, 5000, null, 0 );
		$author   = (string) $a->string( 'author_name', true, 100 );
		$email    = $a->string( 'author_email', false, 254 );
		$verified = $a->bool( 'verified', false );
		$order_id = $a->string( 'order_id', false, 20, '/^\d+$/' );
		$media    = self::media( $args['media'] ?? null );
		$created  = Dates::iso( $a->string( 'created_at', false, 40 ) );

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() ) {
			throw new CommandError( 'not_found', 'No existe ese producto', 404 );
		}

		$existing = self::find( $review_id );
		if ( null !== $existing ) {
			if ( '1' !== (string) $existing->comment_approved ) {
				wp_set_comment_status( (int) $existing->comment_ID, 'approve' );
				self::refresh( $product_id );
			}
			return array( 'comment_id' => (string) $existing->comment_ID );
		}

		$user_id = 0;
		if ( null !== $order_id && function_exists( 'wc_get_order' ) ) {
			$order   = wc_get_order( (int) $order_id );
			$user_id = $order instanceof \WC_Order ? (int) $order->get_customer_id() : 0;
		}
		$email   = null !== $email && is_email( $email ) ? strtolower( $email ) : '';
		$gmt     = null !== $created ? gmdate( 'Y-m-d H:i:s', (int) strtotime( $created ) ) : current_time( 'mysql', true );
		$meta    = array(
			'rating'      => $rating,
			'verified'    => $verified ? 1 : 0,
			self::META_ID => $review_id,
		);
		$comment = array(
			'comment_post_ID'      => $product_id,
			'comment_type'         => 'review',
			'comment_approved'     => 1,
			'comment_author'       => sanitize_text_field( $author ),
			'comment_author_email' => $email,
			'comment_content'      => sanitize_textarea_field( $body ),
			'comment_date_gmt'     => $gmt,
			'comment_date'         => get_date_from_gmt( $gmt ),
			'user_id'              => $user_id,
			'comment_agent'        => 'Imagina Growth',
		);
		$id      = wp_insert_comment( wp_slash( $comment ) );
		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new CommandError( 'save_failed', 'WordPress no pudo guardar la reseña', 500 );
		}
		foreach ( $meta as $key => $value ) {
			add_comment_meta( $id, $key, $value, true );
		}
		if ( null !== $title && '' !== $title ) {
			add_comment_meta( $id, 'igr_title', sanitize_text_field( $title ), true );
		}
		if ( array() !== $media ) {
			add_comment_meta( $id, 'igr_media', wp_slash( Json::encode( $media ) ), true );
		}
		self::refresh( $product_id );
		return array( 'comment_id' => (string) $id );
	}

	/**
	 * Reseña ya publicada con ese `review_id`.
	 *
	 * @param string $review_id ID del hub.
	 */
	public static function find( string $review_id ): ?\WP_Comment {
		$found = get_comments(
			array(
				'meta_key'   => self::META_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Solo al publicar desde el hub.
				'meta_value' => $review_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'type'       => 'review',
				'status'     => 'all',
				'number'     => 1,
			)
		);
		return is_array( $found ) && isset( $found[0] ) && $found[0] instanceof \WP_Comment ? $found[0] : null;
	}

	/**
	 * Recalcula el promedio y el conteo de reseñas del producto.
	 *
	 * @param int $product_id Producto.
	 */
	public static function refresh( int $product_id ): void {
		if ( class_exists( \WC_Comments::class ) ) {
			\WC_Comments::clear_transients( $product_id );
		}
	}

	/**
	 * Fotos y videos: hasta 10, solo URLs http(s).
	 *
	 * @param mixed $value Lista.
	 * @return list<array{url: string, type: string, thumb?: string}>
	 */
	private static function media( mixed $value ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			throw Args::invalid( 'media', 'debe ser una lista de hasta 10 archivos' );
		}
		$out = array();
		foreach ( $value as $m ) {
			$url  = is_array( $m ) ? esc_url_raw( (string) ( $m['url'] ?? '' ), array( 'http', 'https' ) ) : '';
			$type = is_array( $m ) ? (string) ( $m['type'] ?? 'image' ) : '';
			if ( '' === $url || strlen( $url ) > 2048 || ! in_array( $type, array( 'image', 'video' ), true ) ) {
				throw Args::invalid( 'media', 'tiene un archivo inválido' );
			}
			$item  = array(
				'url'  => $url,
				'type' => $type,
			);
			$thumb = esc_url_raw( (string) ( $m['thumb'] ?? '' ), array( 'http', 'https' ) );
			if ( '' !== $thumb && strlen( $thumb ) <= 2048 ) {
				$item['thumb'] = $thumb;
			}
			$out[] = $item;
		}
		return $out;
	}
}
