<?php
/**
 * `review.submitted` cuando una reseña de producto queda aprobada.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Reseñas nativas de WooCommerce (comentarios `review` con la meta `rating`).
 */
final class ReviewCollector {

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'comment_post', array( self::class, 'on_comment_post' ), 20, 2 );
		add_action( 'transition_comment_status', array( self::class, 'on_transition' ), 20, 3 );
	}

	/**
	 * Comentario nuevo (llega aprobado si la tienda no modera).
	 *
	 * @param mixed $comment_id ID.
	 * @param mixed $approved   1, 0 o 'spam'.
	 */
	public static function on_comment_post( mixed $comment_id, mixed $approved ): void {
		if ( 1 === (int) $approved ) {
			$comment = get_comment( (int) $comment_id );
			if ( $comment instanceof \WP_Comment ) {
				self::record( $comment );
			}
		}
	}

	/**
	 * Cambio de estado (moderación).
	 *
	 * @param mixed $new_status Nuevo.
	 * @param mixed $old_status Anterior.
	 * @param mixed $comment    Comentario.
	 */
	public static function on_transition( mixed $new_status, mixed $old_status, mixed $comment ): void {
		if ( 'approved' === $new_status && $comment instanceof \WP_Comment ) {
			self::record( $comment );
		}
	}

	/**
	 * Sobre de `review.submitted` (también lo usa la importación). Null si no es una reseña con nota.
	 *
	 * @param EventFactory $factory Fábrica.
	 * @param \WP_Comment  $comment Comentario.
	 * @param bool         $import  Importación histórica.
	 * @return array<string, mixed>|null
	 */
	public static function envelope( EventFactory $factory, \WP_Comment $comment, bool $import = false ): ?array {
		if ( 'review' !== $comment->comment_type || 'product' !== get_post_type( (int) $comment->comment_post_ID ) ) {
			return null;
		}
		// Las que publicó el hub (`review.publish`) ya están en el hub: no vuelven como reseña nativa.
		if ( '' !== (string) get_comment_meta( (int) $comment->comment_ID, 'igr_review_id', true ) ) {
			return null;
		}
		$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
		if ( $rating < 1 || $rating > 5 ) {
			return null;
		}
		$status_map = array(
			'1'     => 'approved',
			'0'     => 'pending',
			'spam'  => 'spam',
			'trash' => 'trash',
		);
		$status     = $status_map[ (string) $comment->comment_approved ] ?? 'pending';
		$user_id    = (int) $comment->user_id;
		$email      = strtolower( trim( (string) $comment->comment_author_email ) );
		$created    = Dates::iso( (string) $comment->comment_date_gmt . ' UTC' );
		return $factory->make(
			'review.submitted',
			array(
				'product_id'     => (string) (int) $comment->comment_post_ID,
				'rating'         => $rating,
				'verified_owner' => (bool) get_comment_meta( (int) $comment->comment_ID, 'verified', true ),
				'review_id'      => (string) (int) $comment->comment_ID,
				'status'         => $status,
				'body_length'    => mb_strlen( (string) $comment->comment_content ),
				'customer_ref'   => $user_id > 0 ? 'wc:' . $user_id : null,
				'created_at'     => $created,
			),
			array(
				'type'        => $import ? 'import' : 'server',
				'occurred_at' => $created,
				'key'         => 'review:' . $comment->comment_ID . ':' . $status,
				'identity'    => array(
					'customer_ref' => $user_id > 0 ? 'wc:' . $user_id : null,
					'email'        => is_email( $email ) ? $email : null,
				),
			)
		);
	}

	/**
	 * Registra la reseña.
	 *
	 * @param \WP_Comment $comment Comentario.
	 */
	private static function record( \WP_Comment $comment ): void {
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		try {
			$envelope = self::envelope( $factory, $comment );
			if ( null !== $envelope ) {
				Recorder::record( $envelope );
			}
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar la reseña: ' . $e->getMessage() );
		}
	}
}
