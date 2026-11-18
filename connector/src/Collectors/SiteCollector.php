<?php
/**
 * `site.changed` y `site.heartbeat`.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors;

use ImaginaGrowth\Connector\Connection\Capabilities;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Events\Recorder;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Dates;
use ImaginaGrowth\Connector\Support\Logger;

/**
 * Cambios del sitio (para cruzarlos con caídas de conversión) y latido horario.
 */
final class SiteCollector {

	/** Foto de versiones para detectar cambios (autoload = no). */
	private const VERSIONS_OPTION = 'igr_versions';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'activated_plugin', array( self::class, 'on_plugin_activated' ), 20, 1 );
		add_action( 'deactivated_plugin', array( self::class, 'on_plugin_deactivated' ), 20, 1 );
		add_action( 'upgrader_process_complete', array( self::class, 'detect_changes' ), 20, 0 );
		add_action( 'switch_theme', array( self::class, 'on_switch_theme' ), 20, 1 );
		add_action( 'admin_init', array( self::class, 'maybe_detect_changes' ), 20, 0 );
	}

	/**
	 * Plugin activado.
	 *
	 * @param mixed $plugin Archivo del plugin.
	 */
	public static function on_plugin_activated( mixed $plugin ): void {
		$version = self::plugin_version( (string) $plugin );
		self::record( 'plugin_activated', self::plugin_slug( (string) $plugin ), null, $version );
		self::store_versions();
	}

	/**
	 * Plugin desactivado.
	 *
	 * @param mixed $plugin Archivo del plugin.
	 */
	public static function on_plugin_deactivated( mixed $plugin ): void {
		self::record( 'plugin_deactivated', self::plugin_slug( (string) $plugin ), self::plugin_version( (string) $plugin ), null );
		self::store_versions();
	}

	/**
	 * Cambio de tema.
	 *
	 * @param mixed $name Nombre del tema nuevo.
	 */
	public static function on_switch_theme( mixed $name ): void {
		$theme = wp_get_theme();
		self::record( 'theme_switched', mb_substr( (string) $name, 0, 200 ), null, (string) $theme->get( 'Version' ) );
		self::store_versions();
	}

	/**
	 * Revisa versiones en el admin como máximo cada 5 minutos.
	 */
	public static function maybe_detect_changes(): void {
		$stored = get_option( self::VERSIONS_OPTION, array() );
		if ( is_array( $stored ) && (int) ( $stored['checked_at'] ?? 0 ) > time() - 5 * MINUTE_IN_SECONDS ) {
			return;
		}
		self::detect_changes();
	}

	/**
	 * Compara las versiones actuales con la última foto y emite `site.changed`.
	 */
	public static function detect_changes(): void {
		$before = get_option( self::VERSIONS_OPTION, array() );
		$now    = self::versions();
		if ( ! is_array( $before ) || ! isset( $before['plugins'] ) ) {
			update_option( self::VERSIONS_OPTION, $now, false );
			return;
		}
		if ( ( $before['wp'] ?? null ) !== $now['wp'] ) {
			self::record( 'wp_updated', 'wordpress', $before['wp'] ?? null, $now['wp'] ); // phpcs:ignore WordPress.WP.CapitalPDangit.MisspelledInText -- Slug.
		}
		foreach ( $now['plugins'] as $slug => $version ) {
			$old = $before['plugins'][ $slug ] ?? null;
			if ( null !== $old && $old !== $version ) {
				self::record( 'woocommerce' === $slug ? 'wc_updated' : 'plugin_updated', (string) $slug, (string) $old, (string) $version );
			}
		}
		if ( ( $before['theme'] ?? null ) === $now['theme'] && ( $before['theme_version'] ?? null ) !== $now['theme_version'] ) {
			self::record( 'theme_updated', (string) $now['theme'], $before['theme_version'] ?? null, $now['theme_version'] );
		}
		update_option( self::VERSIONS_OPTION, $now, false );
	}

	/**
	 * Latido horario (`site.heartbeat`).
	 */
	public static function heartbeat(): void {
		$factory = EventFactory::current();
		if ( null === $factory ) {
			return;
		}
		$stats = Outbox::stats();
		Recorder::record(
			$factory->make(
				'site.heartbeat',
				array(
					'wp_version'        => (string) get_bloginfo( 'version' ),
					'wc_version'        => defined( 'WC_VERSION' ) ? (string) WC_VERSION : null,
					'php_version'       => PHP_VERSION,
					'plugin_version'    => IGR_VERSION,
					'outbox_pending'    => $stats['pending'] + $stats['sending'],
					'outbox_dead'       => $stats['dead'],
					'oldest_pending_at' => $stats['oldest_pending_at'],
					'last_error'        => Logger::last_error(),
					'capabilities'      => Capabilities::detect(),
				)
			)
		);
	}

	/**
	 * Registra un cambio.
	 *
	 * @param string      $change Tipo.
	 * @param string      $name   Nombre.
	 * @param string|null $from   Versión anterior.
	 * @param string|null $to     Versión nueva.
	 */
	private static function record( string $change, string $name, ?string $from, ?string $to ): void {
		$factory = EventFactory::current();
		if ( null === $factory || '' === $name ) {
			return;
		}
		Recorder::record(
			$factory->make(
				'site.changed',
				array(
					'change'       => $change,
					'name'         => mb_substr( $name, 0, 200 ),
					'from_version' => null !== $from && '' !== $from ? mb_substr( $from, 0, 40 ) : null,
					'to_version'   => null !== $to && '' !== $to ? mb_substr( $to, 0, 40 ) : null,
				)
			)
		);
	}

	/**
	 * Guarda la foto actual.
	 */
	private static function store_versions(): void {
		update_option( self::VERSIONS_OPTION, self::versions(), false );
	}

	/**
	 * Versiones actuales.
	 *
	 * @return array{wp:string, plugins:array<string,string>, theme:string, theme_version:string, checked_at:int}
	 */
	private static function versions(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( (array) get_option( 'active_plugins', array() ) as $file ) {
			$plugins[ self::plugin_slug( (string) $file ) ] = (string) self::plugin_version( (string) $file );
		}
		$theme = wp_get_theme();
		return array(
			'wp'            => (string) get_bloginfo( 'version' ),
			'plugins'       => $plugins,
			'theme'         => (string) $theme->get_stylesheet(),
			'theme_version' => (string) $theme->get( 'Version' ),
			'checked_at'    => time(),
		);
	}

	/**
	 * Slug de un plugin a partir de su archivo.
	 *
	 * @param string $file `carpeta/archivo.php`.
	 */
	private static function plugin_slug( string $file ): string {
		$dir = dirname( $file );
		return '.' === $dir ? basename( $file, '.php' ) : $dir;
	}

	/**
	 * Versión de un plugin.
	 *
	 * @param string $file Archivo del plugin.
	 */
	private static function plugin_version( string $file ): ?string {
		$path = WP_PLUGIN_DIR . '/' . $file;
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = get_file_data( $path, array( 'Version' => 'Version' ) );
		return '' !== $data['Version'] ? $data['Version'] : null;
	}
}
