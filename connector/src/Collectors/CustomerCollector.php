<?php
/**
 * Eventos de clientes: registro, cambios de perfil e inicio de sesión.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Logger;
use ImaginaGrowth\Connector\Support\Phone;
use ImaginaGrowth\Connector\Tracking\RequestContext;

/**
 * `customer.registered`, `customer.updated` (solo los campos que cambiaron) y `customer.logged_in`.
 */
final class CustomerCollector {

	/** Meta de usuario con la última foto enviada, para calcular qué cambió. */
	private const SNAPSHOT_META = '_igr_profile';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'user_register', array( self::class, 'on_register' ), 20, 1 );
		add_action( 'woocommerce_created_customer', array( self::class, 'on_register' ), 20, 1 );
		add_action( 'profile_update', array( self::class, 'on_update' ), 20, 1 );
		add_action( 'woocommerce_customer_save_address', array( self::class, 'on_update' ), 20, 1 );
		add_action( 'wp_login', array( self::class, 'on_login' ), 20, 2 );
	}

	/**
	 * Registro.
	 *
	 * @param mixed $user_id Usuario.
	 */
	public static function on_register( mixed $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || Roles::is_excluded( $user_id ) || ! self::is_customer( $user_id ) ) {
			return;
		}
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		try {
			$ctx = RequestContext::capture();
			Recorder::record( self::registered( $factory, $user_id, false, $ctx ) );
			update_user_meta( $user_id, self::SNAPSHOT_META, self::snapshot( $user_id ) );
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar el cliente: ' . $e->getMessage(), array( 'user' => $user_id ) );
		}
	}

	/**
	 * Sobre de `customer.registered` (también lo usa la importación).
	 *
	 * @param EventFactory                                                         $factory Fábrica.
	 * @param int                                                                  $user_id Usuario.
	 * @param bool                                                                 $import  Importación histórica.
	 * @param array{identity: array<string, mixed>, context: array<string, mixed>} $ctx     Contexto del navegador.
	 * @return array<string, mixed>
	 */
	public static function registered( EventFactory $factory, int $user_id, bool $import = false, array $ctx = array(
		'identity' => array(),
		'context'  => array(),
	) ): array {
		$data       = self::profile( $user_id );
		$user       = get_userdata( $user_id );
		$registered = $user instanceof \WP_User ? Dates::iso( (string) $user->user_registered . ' UTC' ) : null;
		$props      = array(
			'customer_ref'    => 'wc:' . $user_id,
			'first_name'      => $data['first_name'],
			'last_name'       => $data['last_name'],
			'email'           => $data['email'],
			'phone'           => '' !== $data['phone'] ? $data['phone'] : null,
			'marketing_optin' => null,
			'billing'         => \ImaginaGrowth\Connector\Support\Json::obj( self::billing( $data ) ),
			'registered_at'   => $registered,
			'locale'          => self::locale( $user_id ),
		);
		$identity   = array_merge(
			$ctx['identity'],
			array(
				'customer_ref' => 'wc:' . $user_id,
				'email'        => '' !== $data['email'] ? $data['email'] : null,
				'phone'        => '' !== $data['phone'] ? $data['phone'] : null,
			)
		);
		return $factory->make(
			'customer.registered',
			$props,
			array(
				'type'        => $import ? 'import' : 'server',
				'occurred_at' => $registered,
				'key'         => 'customer:' . $user_id . ':registered',
				'identity'    => $identity,
				'context'     => $ctx['context'],
			)
		);
	}

	/**
	 * Cambio de perfil o dirección: envía solo lo que cambió desde la última foto.
	 *
	 * @param mixed $user_id Usuario.
	 */
	public static function on_update( mixed $user_id ): void {
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || Roles::is_excluded( $user_id ) || ! self::is_customer( $user_id ) ) {
			return;
		}
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		$before = get_user_meta( $user_id, self::SNAPSHOT_META, true );
		$before = is_array( $before ) ? $before : array();
		$now    = self::snapshot( $user_id );
		$diff   = array();
		foreach ( $now as $key => $value ) {
			if ( ( $before[ $key ] ?? null ) !== $value ) {
				$diff[ $key ] = $value;
			}
		}
		if ( array() === $diff ) {
			return;
		}
		$props = array(
			'customer_ref' => 'wc:' . $user_id,
			'changed'      => array_keys( $diff ),
		);
		foreach ( array( 'first_name', 'last_name', 'email' ) as $field ) {
			if ( array_key_exists( $field, $diff ) ) {
				$props[ $field ] = $diff[ $field ];
			}
		}
		if ( array_key_exists( 'phone', $diff ) ) {
			$props['phone'] = '' !== $diff['phone'] ? $diff['phone'] : null;
		}
		$billing = array();
		foreach ( array( 'city', 'state', 'country' ) as $field ) {
			if ( array_key_exists( 'billing.' . $field, $diff ) ) {
				$billing[ $field ] = $diff[ 'billing.' . $field ];
			}
		}
		if ( array() !== $billing ) {
			$props['billing'] = $billing;
		}
		try {
			Recorder::record(
				$factory->make(
					'customer.updated',
					$props,
					array(
						'identity' => array(
							'customer_ref' => 'wc:' . $user_id,
							'email'        => '' !== $now['email'] ? $now['email'] : null,
						),
					)
				)
			);
			update_user_meta( $user_id, self::SNAPSHOT_META, $now );
		} catch ( \Throwable $e ) {
			Logger::error( 'Error al registrar el cambio de perfil: ' . $e->getMessage(), array( 'user' => $user_id ) );
		}
	}

	/**
	 * Inicio de sesión.
	 *
	 * @param mixed $login Usuario.
	 * @param mixed $user  WP_User.
	 */
	public static function on_login( mixed $login, mixed $user = null ): void {
		if ( ! $user instanceof \WP_User || Roles::is_excluded( (int) $user->ID ) || ! self::is_customer( (int) $user->ID ) ) {
			return;
		}
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		$ctx = RequestContext::capture();
		Recorder::record(
			$factory->make(
				'customer.logged_in',
				array( 'customer_ref' => 'wc:' . $user->ID ),
				array(
					'identity' => array_merge( $ctx['identity'], array( 'customer_ref' => 'wc:' . $user->ID ) ),
					'context'  => $ctx['context'],
				)
			)
		);
	}

	/**
	 * Datos del perfil.
	 *
	 * @param int $user_id Usuario.
	 * @return array{first_name:string, last_name:string, email:string, phone:string, city:string, state:string, country:string}
	 */
	public static function profile( int $user_id ): array {
		$user    = get_userdata( $user_id );
		$email   = $user instanceof \WP_User ? strtolower( trim( (string) $user->user_email ) ) : '';
		$country = (string) get_user_meta( $user_id, 'billing_country', true );
		$raw     = (string) get_user_meta( $user_id, 'billing_phone', true );
		$phone   = '' !== trim( $raw ) ? ( Phone::e164( $raw, '' !== $country ? $country : self::base_country() ) ?? mb_substr( trim( $raw ), 0, 32 ) ) : '';
		$first   = (string) get_user_meta( $user_id, 'first_name', true );
		$last    = (string) get_user_meta( $user_id, 'last_name', true );
		return array(
			'first_name' => mb_substr( '' !== $first ? $first : (string) get_user_meta( $user_id, 'billing_first_name', true ), 0, 120 ),
			'last_name'  => mb_substr( '' !== $last ? $last : (string) get_user_meta( $user_id, 'billing_last_name', true ), 0, 120 ),
			'email'      => is_email( $email ) ? $email : '',
			'phone'      => $phone,
			'city'       => mb_substr( (string) get_user_meta( $user_id, 'billing_city', true ), 0, 120 ),
			'state'      => mb_substr( (string) get_user_meta( $user_id, 'billing_state', true ), 0, 32 ),
			'country'    => mb_substr( $country, 0, 2 ),
		);
	}

	/**
	 * Foto para comparar en `customer.updated`.
	 *
	 * @param int $user_id Usuario.
	 * @return array<string, string>
	 */
	private static function snapshot( int $user_id ): array {
		$p = self::profile( $user_id );
		return array(
			'first_name'      => $p['first_name'],
			'last_name'       => $p['last_name'],
			'email'           => $p['email'],
			'phone'           => $p['phone'],
			'billing.city'    => $p['city'],
			'billing.state'   => $p['state'],
			'billing.country' => $p['country'],
		);
	}

	/**
	 * Dirección (solo ciudad, estado y país).
	 *
	 * @param array<string, string> $data Perfil.
	 * @return array<string, string>
	 */
	private static function billing( array $data ): array {
		return array_filter(
			array(
				'city'    => $data['city'],
				'state'   => $data['state'],
				'country' => $data['country'],
			),
			static fn( $v ) => '' !== $v
		);
	}

	/**
	 * ¿Es un cliente (rol `customer` o, sin WooCommerce, `subscriber`)?
	 *
	 * @param int $user_id Usuario.
	 */
	public static function is_customer( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof \WP_User ) {
			return false;
		}
		/**
		 * Roles que se consideran clientes.
		 *
		 * @param list<string> $roles Roles.
		 */
		$roles = (array) apply_filters( 'igr_customer_roles', array( 'customer', 'subscriber' ) );
		return array() !== array_intersect( (array) $user->roles, $roles );
	}

	/**
	 * Idioma del usuario.
	 *
	 * @param int $user_id Usuario.
	 */
	private static function locale( int $user_id ): ?string {
		$locale = (string) get_user_meta( $user_id, 'locale', true );
		return 1 === preg_match( '/^[a-z]{2}([-_][A-Za-z]{2})?$/', $locale ) ? $locale : null;
	}

	/**
	 * País base de la tienda.
	 */
	private static function base_country(): string {
		return function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : 'CO';
	}
}
