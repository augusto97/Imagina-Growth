<?php
/**
 * `order.add_note` (docs/api-connector.md §6, Fase 2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Nota en el pedido, privada por defecto (por ejemplo «Pedido recuperado por
 * Imagina Growth»). Con `customer_note`, WooCommerce se la envía al cliente.
 */
final class OrderAddNote implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		$a        = new Args( $args );
		$order_id = (int) $a->string( 'order_id', true, 20, '/^\d+$/' );
		$note     = (string) $a->string( 'note', true, 2000 );
		$customer = $a->bool( 'customer_note', false );
		$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : false;
		if ( ! $order instanceof \WC_Order ) {
			throw new CommandError( 'not_found', 'No existe ese pedido', 404 );
		}
		$note_id = $order->add_order_note( sanitize_textarea_field( $note ), $customer ? 1 : 0, false );
		if ( $note_id <= 0 ) {
			throw new CommandError( 'save_failed', 'WooCommerce no pudo guardar la nota', 500 );
		}
		return array( 'note_id' => (string) $note_id );
	}
}
