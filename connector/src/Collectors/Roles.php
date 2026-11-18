<?php
/**
 * Exclusión por rol (administradores por defecto).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;

/**
 * Los eventos de navegación y carrito de usuarios con roles excluidos no se envían.
 */
final class Roles {

	/**
	 * ¿El usuario tiene un rol excluido?
	 *
	 * @param int|null $user_id Usuario (por defecto, el actual).
	 */
	public static function is_excluded( ?int $user_id = null ): bool {
		$user_id = $user_id ?? get_current_user_id();
		if ( $user_id <= 0 ) {
			return false;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		// Los roles elegidos en la pestaña «Datos» mandan sobre los del hub (copia autocargada).
		$local = TrackerConfig::get()['roles'] ?? null;
		return array() !== array_intersect( (array) $user->roles, is_array( $local ) ? $local : RemoteConfig::list( 'excluded_roles' ) );
	}
}
