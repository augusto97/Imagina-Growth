<?php
/**
 * `coupon.disable` (docs/api-connector.md §6, Fase 2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Deja en borrador un cupón creado por Imagina Growth. Los cupones del dueño de
 * la tienda no se tocan (`409 coupon_not_managed`).
 */
final class CouponDisable implements Command {

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			throw new CommandError( 'woocommerce_missing', 'WooCommerce no está activo', 409 );
		}
		$code = (string) ( new Args( $args ) )->string( 'code', true, 64, '/^[A-Za-z0-9_-]+$/', 3 );
		$id   = wc_get_coupon_id_by_code( $code );
		if ( $id <= 0 ) {
			throw new CommandError( 'not_found', 'No existe un cupón con ese código', 404 );
		}
		$coupon = new \WC_Coupon( $id );
		if ( '1' !== (string) $coupon->get_meta( CouponCreate::META_FLAG ) ) {
			throw new CommandError( 'coupon_not_managed', 'El cupón no lo creó Imagina Growth', 409 );
		}
		if ( 'draft' !== get_post_status( $id ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_status' => 'draft',
				)
			);
			clean_post_cache( $id );
		}
		return array( 'disabled' => true );
	}
}
