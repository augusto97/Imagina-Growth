<?php
/**
 * Arma el sobre de cada evento (docs/eventos.md §2).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Events;

use DateTimeInterface;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Json;
use ImaginaGrowth\Connector\Support\Uuid;

/**
 * Fábrica de sobres para un sitio.
 */
final class EventFactory {

	public const SCHEMA_VERSION = 1;

	/**
	 * Constructor.
	 *
	 * @param string $site_id  ID del sitio (`st_…`).
	 * @param string $platform `woocommerce` o `wordpress`.
	 * @param string $version  Versión del plugin.
	 */
	public function __construct(
		private readonly string $site_id,
		private readonly string $platform,
		private readonly string $version = IGR_VERSION
	) {}

	/**
	 * Fábrica para el sitio conectado.
	 */
	public static function current(): ?self {
		$site_id = \ImaginaGrowth\Connector\Connection\Connection::site_id();
		if ( '' === $site_id ) {
			return null;
		}
		return new self( $site_id, class_exists( 'WooCommerce' ) ? 'woocommerce' : 'wordpress' ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Valor del contrato (source.platform).
	}

	/**
	 * ID del sitio.
	 */
	public function site_id(): string {
		return $this->site_id;
	}

	/**
	 * Arma un sobre.
	 *
	 * @param string                                                                                          $event      Nombre del evento.
	 * @param array<string, mixed>                                                                            $properties Propiedades.
	 * @param array{type?:string, occurred_at?:DateTimeInterface|int|string|null, key?:string|null, identity?:array<string,mixed>, context?:array<string,mixed>} $options Opciones.
	 * @return array<string, mixed>
	 */
	public function make( string $event, array $properties, array $options = array() ): array {
		$occurred = Dates::iso( $options['occurred_at'] ?? null ) ?? Dates::now();
		$key      = $options['key'] ?? null;
		$event_id = null !== $key && '' !== $key ? Uuid::event( $this->site_id, $key ) : Uuid::v7();

		$envelope = array(
			'schema_version' => self::SCHEMA_VERSION,
			'event_id'       => $event_id,
			'event'          => $event,
			'occurred_at'    => $occurred,
			'source'         => array(
				'type'     => $options['type'] ?? 'server',
				'platform' => $this->platform,
				'site_id'  => $this->site_id,
				'sdk'      => 'connector/' . $this->version,
			),
			'identity'       => Json::obj( self::compact( $options['identity'] ?? array() ) ),
			'properties'     => Json::obj( $properties ),
		);
		$context  = self::compact( $options['context'] ?? array() );
		if ( array() !== $context ) {
			$envelope['context'] = $context;
		}
		return $envelope;
	}

	/**
	 * Quita valores nulos, textos vacíos y arreglos vacíos (recursivo) para un sobre compacto.
	 *
	 * @param array<string, mixed> $data Datos.
	 * @return array<string, mixed>
	 */
	public static function compact( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				$value = self::compact( $value );
				if ( array() === $value ) {
					continue;
				}
			} elseif ( null === $value || '' === $value ) {
				continue;
			}
			$out[ $key ] = $value;
		}
		return $out;
	}
}
