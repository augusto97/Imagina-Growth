<?php
/**
 * `review.reply` (docs/api-connector.md §6, Fase 3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Respuesta del dueño como comentario hijo aprobado de la reseña (meta `igr_reply`).
 * La misma respuesta repetida devuelve el mismo comentario.
 */
final class ReviewReply implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		$a      = new Args( $args );
		$parent = get_comment( (int) $a->string( 'comment_id', true, 20, '/^[1-9]\d*$/' ) );
		$body   = sanitize_textarea_field( (string) $a->string( 'body', true, 5000 ) );
		$author = sanitize_text_field( (string) $a->string( 'author_name', true, 100 ) );
		if ( ! $parent instanceof \WP_Comment || 'review' !== $parent->comment_type ) {
			throw new CommandError( 'not_found', 'No existe esa reseña', 404 );
		}
		foreach ( get_comments(
			array(
				'parent' => (int) $parent->comment_ID,
				'type'   => 'comment',
				'status' => 'all',
			)
		) as $child ) {
			if ( $child instanceof \WP_Comment && '1' === (string) get_comment_meta( (int) $child->comment_ID, 'igr_reply', true ) && trim( (string) $child->comment_content ) === $body ) {
				return array( 'comment_id' => (string) $child->comment_ID );
			}
		}
		$from = (string) get_option( 'woocommerce_email_from_address', '' );
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => (int) $parent->comment_post_ID,
					'comment_parent'       => (int) $parent->comment_ID,
					'comment_type'         => 'comment',
					'comment_approved'     => 1,
					'comment_author'       => $author,
					'comment_author_email' => is_email( $from ) ? $from : '',
					'comment_content'      => $body,
					'comment_agent'        => 'Imagina Growth',
					'comment_meta'         => array( 'igr_reply' => 1 ),
				)
			)
		);
		if ( ! is_int( $id ) || $id <= 0 ) {
			throw new CommandError( 'save_failed', 'WordPress no pudo guardar la respuesta', 500 );
		}
		return array( 'comment_id' => (string) $id );
	}
}
