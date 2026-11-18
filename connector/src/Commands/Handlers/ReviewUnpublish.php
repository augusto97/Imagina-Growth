<?php
/**
 * `review.unpublish` (docs/api-connector.md §6, Fase 3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Deja la reseña pendiente (la moderación del hub la rechazó después de publicada).
 */
final class ReviewUnpublish implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		$a       = new Args( $args );
		$comment = get_comment( (int) $a->string( 'comment_id', true, 20, '/^[1-9]\d*$/' ) );
		if ( ! $comment instanceof \WP_Comment || 'review' !== $comment->comment_type ) {
			throw new CommandError( 'not_found', 'No existe esa reseña', 404 );
		}
		if ( '0' !== (string) $comment->comment_approved && ! wp_set_comment_status( (int) $comment->comment_ID, 'hold' ) ) {
			throw new CommandError( 'save_failed', 'WordPress no pudo cambiar el estado de la reseña', 500 );
		}
		ReviewPublish::refresh( (int) $comment->comment_post_ID );
		return array( 'ok' => true );
	}
}
