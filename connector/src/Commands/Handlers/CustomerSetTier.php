<?php
/**
 * `customer.set_tier` (docs/api-connector.md §6, Fase 3).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Commands\Handlers;

use ImaginaGrowth\Connector\Commands\Args;
use ImaginaGrowth\Connector\Commands\Command;
use ImaginaGrowth\Connector\Commands\CommandError;

/**
 * Nivel VIP del cliente: metas `igr_tier` e `igr_tier_name` y, si viene `role`, ese rol
 * además de los que ya tiene (para precios por rol). Quita el rol del nivel anterior
 * (`igr_tier_role`). Nunca toca roles con permisos: si el rol no existe se crea solo con
 * `read`; si existe y da más permisos, o el usuario es administrador o gestor de la
 * tienda, el rol no se cambia.
 */
final class CustomerSetTier implements Command {

	public const META      = 'igr_tier';
	public const META_NAME = 'igr_tier_name';
	public const META_ROLE = 'igr_tier_role';
	private const RESERVED = array( 'administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager' );

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $args Argumentos.
	 */
	public function handle( array $args ): array {
		$a    = new Args( $args );
		$ref  = (string) $a->string( 'customer_ref', true, 70, '/^wc:[1-9]\d*$/' );
		$key  = $a->string( 'tier_key', false, 64, '/^[A-Za-z0-9_-]+$/' );
		$name = $a->string( 'tier_name', false, 100 );
		$role = $a->string( 'role', false, 60, '/^[a-z0-9_-]{2,60}$/' );
		$user = get_user_by( 'id', (int) substr( $ref, 3 ) );
		if ( ! $user instanceof \WP_User ) {
			throw new CommandError( 'not_found', 'No existe ese cliente', 404 );
		}
		if ( null !== $role && ( in_array( $role, self::RESERVED, true ) || ! self::safe_role( $role, (string) ( $name ?? $role ) ) ) ) {
			throw new CommandError( 'role_not_allowed', 'Ese rol tiene permisos: no se asigna desde Imagina Growth', 422 );
		}
		if ( null === $key ) {
			delete_user_meta( $user->ID, self::META );
			delete_user_meta( $user->ID, self::META_NAME );
		} else {
			update_user_meta( $user->ID, self::META, $key );
			update_user_meta( $user->ID, self::META_NAME, sanitize_text_field( (string) ( $name ?? $key ) ) );
		}
		if ( $user->has_cap( 'manage_options' ) || $user->has_cap( 'manage_woocommerce' ) ) {
			return array( 'ok' => true );
		}
		$previous = (string) get_user_meta( $user->ID, self::META_ROLE, true );
		if ( '' !== $previous && $previous !== $role && ! in_array( $previous, self::RESERVED, true ) ) {
			$user->remove_role( $previous );
		}
		if ( null !== $role && null !== $key ) {
			if ( ! in_array( $role, $user->roles, true ) ) {
				$user->add_role( $role );
			}
			update_user_meta( $user->ID, self::META_ROLE, $role );
		} else {
			delete_user_meta( $user->ID, self::META_ROLE );
		}
		return array( 'ok' => true );
	}

	/**
	 * El rol existe solo con `read` (o se crea así).
	 *
	 * @param string $role Rol.
	 * @param string $name Nombre visible si hay que crearlo.
	 */
	private static function safe_role( string $role, string $name ): bool {
		$existing = get_role( $role );
		if ( null === $existing ) {
			add_role( $role, sanitize_text_field( $name ), array( 'read' => true ) );
			return null !== get_role( $role );
		}
		foreach ( (array) $existing->capabilities as $cap => $granted ) {
			if ( $granted && ! in_array( $cap, array( 'read', 'level_0' ), true ) ) {
				return false;
			}
		}
		return true;
	}
}
