<?php
/**
 * `coupon.create` (docs/api-connector.md §6, Fase 2).
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
 * Cupón único en WooCommerce (`WC_Coupon`). Es idempotente por código: los mismos
 * datos devuelven el mismo cupón; un código existente con otros datos (o que no
 * creó Imagina Growth) responde `409 coupon_exists`.
 */
final class CouponCreate implements Command {

	public const META_FLAG   = 'igr_coupon';
	public const META_SOURCE = 'igr_source';
	public const META_HASH   = 'igr_coupon_hash';

	private const KINDS = array(
		'percent'       => 'percent',
		'fixed_cart'    => 'fixed_cart',
		'fixed_product' => 'fixed_product',
		'free_shipping' => 'fixed_cart',
	);

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		if ( ! class_exists( 'WC_Coupon' ) ) {
			throw new CommandError( 'woocommerce_missing', 'WooCommerce no está activo', 409 );
		}
		$a    = new Args( $args );
		$spec = array(
			'code'               => (string) $a->string( 'code', true, 64, '/^[A-Za-z0-9_-]+$/', 3 ),
			'kind'               => (string) $a->string( 'kind', true, 20, '/^(percent|fixed_cart|fixed_product|free_shipping)$/' ),
			'amount'             => $a->money( 'amount' ) ?? '0',
			'expires_at'         => $a->string( 'expires_at', false, 10, '/^\d{4}-\d{2}-\d{2}$/' ),
			'usage_limit'        => $a->int( 'usage_limit', 1, 1, 1000 ),
			'email_restrictions' => $a->emails( 'email_restrictions' ),
			'individual_use'     => $a->bool( 'individual_use', true ),
			'minimum_amount'     => $a->money( 'minimum_amount' ),
			'product_ids'        => $a->ids( 'product_ids' ),
			'category_ids'       => $a->ids( 'category_ids' ),
			'free_shipping'      => $a->bool( 'free_shipping', 'free_shipping' === ( $args['kind'] ?? '' ) ),
			'description'        => $a->string( 'description', false, 200 ) ?? '',
		);
		if ( 'percent' === $spec['kind'] && (float) $spec['amount'] > 100 ) {
			throw Args::invalid( 'amount', 'no puede pasar de 100 en un porcentaje' );
		}
		if ( 'free_shipping' !== $spec['kind'] && (float) $spec['amount'] <= 0 ) {
			throw Args::invalid( 'amount', 'debe ser mayor que 0' );
		}
		if ( null !== $spec['expires_at'] ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $spec['expires_at'], Dates::tz() );
			// createFromFormat acepta el 31 de febrero (lo pasa a marzo): la fecha debe volver igual.
			if ( false === $date || $date->format( 'Y-m-d' ) !== $spec['expires_at'] ) {
				throw Args::invalid( 'expires_at', 'no es una fecha válida' );
			}
		}
		$hash = md5( Json::encode( $spec ) );

		$existing = wc_get_coupon_id_by_code( $spec['code'] );
		if ( $existing > 0 ) {
			$coupon = new \WC_Coupon( $existing );
			if ( '1' === (string) $coupon->get_meta( self::META_FLAG ) && $hash === (string) $coupon->get_meta( self::META_HASH ) ) {
				return array(
					'coupon_id' => (string) $coupon->get_id(),
					'code'      => (string) $coupon->get_code(),
				);
			}
			throw new CommandError( 'coupon_exists', 'Ya existe un cupón con ese código y otros datos', 409 );
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $spec['code'] );
		$coupon->set_discount_type( self::KINDS[ $spec['kind'] ] );
		$coupon->set_amount( 'free_shipping' === $spec['kind'] ? '0' : $spec['amount'] );
		$coupon->set_usage_limit( $spec['usage_limit'] );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( $spec['individual_use'] );
		$coupon->set_free_shipping( $spec['free_shipping'] );
		$coupon->set_email_restrictions( $spec['email_restrictions'] );
		$coupon->set_product_ids( $spec['product_ids'] );
		$coupon->set_product_categories( $spec['category_ids'] );
		if ( null !== $spec['minimum_amount'] ) {
			$coupon->set_minimum_amount( $spec['minimum_amount'] );
		}
		if ( null !== $spec['expires_at'] ) {
			// Vale hasta el final del día indicado, en la zona horaria de la tienda.
			$end = new \DateTimeImmutable( $spec['expires_at'] . ' 23:59:59', Dates::tz() );
			$coupon->set_date_expires( $end->getTimestamp() );
		}
		$coupon->set_description( '' !== $spec['description'] ? $spec['description'] : 'Imagina Growth' );
		$coupon->update_meta_data( self::META_FLAG, '1' );
		$coupon->update_meta_data( self::META_SOURCE, $spec['description'] );
		$coupon->update_meta_data( self::META_HASH, $hash );
		$id = $coupon->save();
		if ( $id <= 0 ) {
			throw new CommandError( 'save_failed', 'WooCommerce no pudo guardar el cupón', 500 );
		}
		return array(
			'coupon_id' => (string) $id,
			'code'      => (string) $coupon->get_code(),
		);
	}
}
