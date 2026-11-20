<?php
/**
 * Pestañas Estado y Diagnóstico sin jerga (auditoría U5) y aviso de versión del plugin.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Admin\AdminPage;
use ImaginaGrowth\Connector\Admin\UpdateNotice;
use ImaginaGrowth\Connector\Admin\Wording;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Events\EventFactory;
use ImaginaGrowth\Connector\Outbox\Flusher;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Lo que ve el dueño de la tienda.
 */
final class AdminWordingTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		$this->connect();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		unset( $_GET['tab'] );
		set_current_screen( 'front' );
		delete_option( Flusher::STATUS_OPTION );
		parent::tear_down();
	}

	/**
	 * La configuración del hub con las versiones del plugin.
	 *
	 * @param string $min    Mínima.
	 * @param string $latest Última.
	 * @param string $url    Descarga.
	 */
	private function versions( string $min, string $latest, string $url = 'https://growth.imagina.cloud/downloads/imagina-growth-connector.zip' ): void {
		update_option(
			RemoteConfig::OPTION,
			array(
				'settings'           => array(),
				'min_plugin_version' => $min,
				'plugin'             => array(
					'min_version'    => $min,
					'latest_version' => $latest,
					'download_url'   => $url,
				),
			),
			false
		);
		RemoteConfig::reset();
	}

	/**
	 * HTML de los avisos del admin.
	 */
	private function notices(): string {
		ob_start();
		UpdateNotice::render();
		return (string) ob_get_clean();
	}

	/**
	 * HTML de una pestaña de la página del plugin.
	 *
	 * @param string $tab Pestaña.
	 */
	private function page( string $tab ): string {
		$_GET['tab'] = $tab;
		ob_start();
		AdminPage::render();
		return (string) ob_get_clean();
	}

	public function test_version_menor_que_la_minima_avisa_en_todo_el_admin_con_el_enlace_del_hub_y_sin_http(): void {
		$this->versions( '9.0.0', '9.0.1' );
		set_current_screen( 'edit-post' );
		$html = $this->notices();
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'ya no es compatible', $html );
		$this->assertStringContainsString( 'https://growth.imagina.cloud/downloads/imagina-growth-connector.zip', $html );
		$this->assertStringContainsString( IGR_VERSION, $html );
		$this->assertSame( array(), $this->http, 'el aviso usa la configuración guardada, sin pedir nada al hub' );
	}

	public function test_version_nueva_disponible_en_el_escritorio_y_se_puede_ocultar_hasta_la_siguiente(): void {
		$this->versions( '0.1.0', '9.9.9' );
		set_current_screen( 'edit-post' );
		$this->assertSame( '', $this->notices(), 'el aviso informativo no sale en cualquier pantalla' );
		set_current_screen( 'dashboard' );
		$html = $this->notices();
		$this->assertStringContainsString( 'notice-info', $html );
		$this->assertStringContainsString( '9.9.9', $html );
		$this->assertStringContainsString( UpdateNotice::DISMISS_ARG, $html );

		update_user_meta( get_current_user_id(), UpdateNotice::DISMISS_META, '9.9.9' );
		$this->assertSame( '', $this->notices() );
		$this->versions( '0.1.0', '9.9.10' );
		$this->assertStringContainsString( '9.9.10', $this->notices(), 'una versión más nueva vuelve a avisar' );
	}

	public function test_al_dia_sin_aviso_y_un_enlace_raro_cae_al_del_hub_conectado(): void {
		$this->versions( '0.1.0', IGR_VERSION );
		set_current_screen( 'dashboard' );
		$this->assertSame( '', $this->notices() );
		$this->assertSame( 'current', UpdateNotice::state()['status'] );

		$this->versions( '9.0.0', '9.0.0', 'javascript:alert(1)' );
		$this->assertSame( 'http://hub.test/downloads/imagina-growth-connector.zip', UpdateNotice::state()['url'] );
		$this->assertStringNotContainsString( 'javascript:', $this->notices() );

		// Un editor sin permiso de actualizar plugins no ve el aviso.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( '', $this->notices() );
	}

	public function test_estado_y_diagnostico_en_palabras_de_la_tienda(): void {
		$this->versions( '0.1.0', IGR_VERSION );
		$factory = EventFactory::current();
		$this->assertNotNull( $factory );
		Outbox::insert( $factory->make( 'order.paid', array( 'order' => array( 'order_id' => '1' ) ) ) );
		Outbox::insert( $factory->make( 'cart.item_added', array( 'cart_token' => 'abcdef0123456789' ) ) );
		$dead = $factory->make( 'checkout.started', array( 'cart_token' => 'abcdef0123456789' ) );
		Outbox::insert( $dead );
		global $wpdb;
		$id = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . Outbox::table() . ' WHERE event_id = %s', $dead['event_id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		Outbox::mark_dead( array( $id ), 'rejected: schema' );
		update_option(
			Flusher::STATUS_OPTION,
			array(
				'last_success_at' => time() - 120,
				'last_error'      => 'HTTP 401 signature',
			),
			false
		);

		$status = $this->page( 'status' );
		$this->assertStringContainsString( 'Pedidos pagados', $status );
		$this->assertStringContainsString( 'Productos agregados al carrito', $status );
		$this->assertStringNotContainsString( '<th style="width:240px">order.paid</th>', $status );
		$this->assertStringContainsString( 'no reconoce la conexión', $status );
		$this->assertStringNotContainsString( 'HTTP 401', $status );
		$this->assertStringContainsString( 'al día', $status );

		$diagnostics = $this->page( 'diagnostics' );
		$this->assertStringContainsString( 'Datos de tu tienda por enviar', $diagnostics );
		$this->assertStringContainsString( Wording::status( 'dead' ), $diagnostics );
		$this->assertStringContainsString( 'Visitas a la página de pago', $diagnostics );
		$this->assertStringContainsString( 'no aceptó este dato', $diagnostics );
		$this->assertStringNotContainsString( '>Outbox<', $diagnostics );
		$this->assertStringNotContainsString( '<th>event_id</th>', $diagnostics );
		$this->assertStringNotContainsString( '<th style="width:240px">pending</th>', $diagnostics );
		$this->assertStringContainsString( '<details>', $diagnostics, 'el informe técnico queda plegado para soporte' );
	}

	public function test_errores_traducidos(): void {
		$this->assertStringContainsString( 'desconectado', Wording::error( 'HTTP 403 site_disconnected' ) );
		$this->assertStringContainsString( 'DNS', Wording::error( 'network: cURL error 6: Could not resolve host: growth.imagina.cloud' ) );
		$this->assertStringContainsString( 'se reintenta solo', Wording::error( 'HTTP 503' ) );
		$this->assertSame( '', Wording::error( '' ) );
		$this->assertSame( 'Eventos personalizados', Wording::event( 'custom.cotizacion' ) );
	}
}
