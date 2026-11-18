<?php
/**
 * Configuración que entrega el hub (`GET /v1/s/config`), en caché un día.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Connection;

use ImaginaGrowth\Connector\Support\Http;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Valores por defecto de docs/api-connector.md §5.1, sobrescritos por el hub.
 */
final class RemoteConfig {

	public const OPTION = 'igr_remote_config';

	private const DEFAULTS = array(
		'paid_statuses'     => array( 'processing', 'completed' ),
		'cod_gateways'      => array( 'cod' ),
		'cod_paid_statuses' => array( 'completed' ),
		'offline_gateways'  => array( 'bacs', 'cheque', 'cod' ),
		'excluded_roles'    => array( 'administrator', 'shop_manager' ),
		'backfill_months'   => 24,
		'early_capture'     => false,
	);

	/**
	 * Caché por petición.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $settings = null;

	/**
	 * Ajustes efectivos.
	 *
	 * @return array<string, mixed>
	 */
	public static function settings(): array {
		if ( null === self::$settings ) {
			$stored         = get_option( self::OPTION, array() );
			$remote         = is_array( $stored ) && is_array( $stored['settings'] ?? null ) ? $stored['settings'] : array();
			self::$settings = array_merge( self::DEFAULTS, array_intersect_key( $remote, self::DEFAULTS ) );
			/**
			 * Permite ajustar la configuración efectiva (por ejemplo, estados pagados personalizados).
			 *
			 * @param array<string, mixed> $settings Ajustes.
			 */
			self::$settings = (array) apply_filters( 'igr_settings', self::$settings );
		}
		return self::$settings;
	}

	/**
	 * Un ajuste como lista de textos.
	 *
	 * @param string $key Clave.
	 * @return list<string>
	 */
	public static function list( string $key ): array {
		$value = self::settings()[ $key ] ?? array();
		return is_array( $value ) ? array_values( array_map( 'strval', $value ) ) : array();
	}

	/**
	 * ¿El pedido cuenta como pagado? Contraentrega solo en `cod_paid_statuses`.
	 *
	 * @param string $status  Estado sin `wc-`.
	 * @param string $gateway ID de la pasarela.
	 */
	public static function is_paid( string $status, string $gateway ): bool {
		if ( in_array( $gateway, self::list( 'cod_gateways' ), true ) ) {
			return in_array( $status, self::list( 'cod_paid_statuses' ), true );
		}
		return in_array( $status, self::list( 'paid_statuses' ), true );
	}

	/**
	 * ¿La pasarela es de pago fuera de línea?
	 *
	 * @param string $gateway ID de la pasarela.
	 */
	public static function is_offline( string $gateway ): bool {
		return '' !== $gateway && in_array( $gateway, self::list( 'offline_gateways' ), true );
	}

	/**
	 * Meses de historial a importar.
	 */
	public static function backfill_months(): int {
		return max( 1, (int) ( self::settings()['backfill_months'] ?? 24 ) );
	}

	/**
	 * Descarga la configuración del hub (solo desde un job, nunca en la petición del visitante).
	 */
	public static function refresh(): bool {
		if ( ! Connection::is_connected() ) {
			return false;
		}
		$response = Http::signed( 'GET', Connection::api_url( 'v1/s/config' ), null, Connection::site_id(), Connection::secret(), false, 10 );
		if ( ! $response->ok() || ! is_array( $response->json ) ) {
			Logger::warning( 'No se pudo leer la configuración del hub: ' . $response->describe() );
			return false;
		}
		$data               = $response->json;
		$data['fetched_at'] = time();
		update_option( self::OPTION, $data, false );
		self::$settings = null;
		\ImaginaGrowth\Connector\Tracking\TrackerConfig::rebuild();
		return true;
	}

	/**
	 * Limpia la caché por petición.
	 */
	public static function reset(): void {
		self::$settings = null;
	}
}
