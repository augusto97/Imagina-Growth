<?php
/**
 * Contenedor simple y registro de módulos.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector;

use ImaginaGrowth\Connector\Admin\AdminPage;
use ImaginaGrowth\Connector\Backfill\Runner;
use ImaginaGrowth\Connector\Catalog\CatalogCollector;
use ImaginaGrowth\Connector\Catalog\CatalogSync;
use ImaginaGrowth\Connector\Collectors\CartCollector;
use ImaginaGrowth\Connector\Collectors\EarlyCapture;
use ImaginaGrowth\Connector\Onsite\Account;
use ImaginaGrowth\Connector\Onsite\Blocks;
use ImaginaGrowth\Connector\Onsite\CartRestore;
use ImaginaGrowth\Connector\Onsite\Elementor\Registrar as ElementorWidgets;
use ImaginaGrowth\Connector\Onsite\ServiceSlots;
use ImaginaGrowth\Connector\Onsite\Shortcodes;
use ImaginaGrowth\Connector\Onsite\Store;
use ImaginaGrowth\Connector\Onsite\Wallet;
use ImaginaGrowth\Connector\Collectors\CustomerCollector;
use ImaginaGrowth\Connector\Collectors\OrderCollector;
use ImaginaGrowth\Connector\Collectors\ReviewCollector;
use ImaginaGrowth\Connector\Collectors\SiteCollector;
use ImaginaGrowth\Connector\Commands\CommandsController;
use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Collectors\Forms\FormCollector;
use ImaginaGrowth\Connector\Consent\CheckoutConsent;
use ImaginaGrowth\Connector\Privacy\PrivacyTools;
use ImaginaGrowth\Connector\Tracking\Optimizers;
use ImaginaGrowth\Connector\Tracking\PageData;
use ImaginaGrowth\Connector\Tracking\Ping;
use ImaginaGrowth\Connector\Tracking\TrackerConfig;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;

/**
 * Arranque. En una petición pública sin conexión no se registra nada; conectado, solo hooks
 * (ninguna consulta extra: el estado viene de la opción autocargada `igr_state`).
 */
final class Plugin {

	/**
	 * Instancia.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * ¿Ya arrancó?
	 *
	 * @var bool
	 */
	private bool $booted = false;

	/**
	 * Instancia única.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registra los hooks.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 0 );
		add_action( 'plugins_loaded', array( Installer::class, 'maybe_upgrade' ), 5 );
		add_filter( 'cron_schedules', array( Scheduler::class, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

		// Tareas programadas (se registran siempre: son baratas y solo corren en los jobs).
		add_action( Scheduler::FLUSH, array( self::class, 'flush' ) );
		add_action( Scheduler::FLUSH_NOW, array( self::class, 'flush' ) );
		add_action( Scheduler::HEARTBEAT, array( SiteCollector::class, 'heartbeat' ) );
		add_action( Scheduler::PURGE, array( Outbox::class, 'purge' ) );
		add_action( Scheduler::CONFIG, array( RemoteConfig::class, 'refresh' ) );
		add_action( Scheduler::RECONCILE, array( CatalogSync::class, 'queue' ) );
		add_action( Scheduler::RECONCILE_STEP, array( self::class, 'reconcile_step' ) );
		add_action( Scheduler::BACKFILL, array( self::class, 'backfill_step' ) );

		add_action( 'rest_api_init', array( CommandsController::class, 'register_routes' ) );
		// Slots de la Fase 3: siempre registrados para que un shortcode no se vea como texto
		// si el sitio se desconecta (sin conexión no pintan nada).
		Shortcodes::register();
		Blocks::register();
		ServiceSlots::register();
		add_action( 'init', array( Account::class, 'sync_rewrite' ), 99 );

		if ( Connection::is_connected() ) {
			$this->register_collectors();
		}

		if ( is_admin() ) {
			AdminPage::register();
			Admin\SyncNow::register();
			Admin\UpdateNotice::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'igr', Cli\Command::class );
		}
	}

	/**
	 * Recolectores de eventos.
	 */
	private function register_collectors(): void {
		SiteCollector::register();
		CustomerCollector::register();
		ReviewCollector::register();
		FormCollector::register();
		PrivacyTools::register();
		// Navegador: tracker, ping de la cookie y exclusiones en los optimizadores.
		if ( null === TrackerConfig::get() ) {
			add_action( 'admin_init', array( TrackerConfig::class, 'rebuild' ) );
		}
		Ping::register();
		PageData::register();
		Optimizers::register();
		ElementorWidgets::register();
		$woo = static function (): void {
			OrderCollector::register();
			CartCollector::register();
			EarlyCapture::register();
			CatalogCollector::register();
			CheckoutConsent::register();
			CartRestore::register();
			Store::register();
			Account::register();
			Wallet::register();
			add_action( 'woocommerce_thankyou', array( CartCollector::class, 'reset_token' ), 5, 0 );
		};
		// WooCommerce suele cargar después (orden alfabético), pero no siempre.
		if ( did_action( 'woocommerce_loaded' ) ) {
			$woo();
		} else {
			add_action( 'woocommerce_loaded', $woo );
		}
		add_action( 'admin_init', array( self::class, 'ensure_schedule' ) );
	}

	/**
	 * Revisa, como máximo cada hora y solo en el admin, que las tareas recurrentes existan.
	 */
	public static function ensure_schedule(): void {
		$state = Connection::state();
		if ( (int) ( $state['checked_at'] ?? 0 ) > time() - HOUR_IN_SECONDS ) {
			return;
		}
		Scheduler::ensure_recurring();
		Connection::update_state( array( 'checked_at' => time() ) );
	}

	/**
	 * Acción de envío.
	 */
	public static function flush(): void {
		Flusher::run( 20 );
	}

	/**
	 * Acción de importación.
	 */
	public static function backfill_step(): void {
		Runner::step( true );
	}

	/**
	 * Acción de conciliación del catálogo.
	 */
	public static function reconcile_step(): void {
		CatalogSync::run( 20 );
	}

	/**
	 * Traducciones.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'imagina-growth', false, dirname( plugin_basename( IGR_PLUGIN_FILE ) ) . '/languages' );
	}
}
