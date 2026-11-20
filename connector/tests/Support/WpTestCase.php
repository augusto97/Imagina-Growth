<?php
/**
 * Base de las pruebas con WordPress y WooCommerce.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Support;

use ImaginaGrowth\Connector\Connection\Connection;
use ImaginaGrowth\Connector\Connection\RemoteConfig;
use ImaginaGrowth\Connector\Outbox\Outbox;
use ImaginaGrowth\Connector\Support\Json;

/**
 * Conexión simulada, captura de HTTP y fábricas de datos colombianos.
 */
abstract class WpTestCase extends \WP_UnitTestCase {

	public const SITE_ID = 'st_01JD3QG7Y2V8K6N4M5P9R0S1T2';
	public const SECRET  = 'sk_TestSecretTestSecretTestSecretTestSecret12';

	/**
	 * Peticiones HTTP capturadas.
	 *
	 * @var list<array{method:string, url:string, headers:array<string,string>, body:string}>
	 */
	protected array $http = array();

	/**
	 * Respuesta HTTP simulada por defecto.
	 *
	 * @var callable(array<string,mixed>):array<string,mixed>
	 */
	protected $responder;

	public function set_up(): void {
		parent::set_up();
		$this->http      = array();
		$this->responder = static fn( array $req ): array => array(
			'status' => 202,
			'body'   => '{"accepted":0,"rejected":[]}',
		);
		add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Outbox::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		delete_option( RemoteConfig::OPTION );
		RemoteConfig::reset();
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
		Connection::forget();
		parent::tear_down();
	}

	/**
	 * Intercepta todas las llamadas HTTP de la prueba.
	 *
	 * @param mixed                $pre  Valor previo.
	 * @param array<string, mixed> $args Argumentos.
	 * @param string               $url  URL.
	 * @return array<string, mixed>
	 */
	public function capture_http( mixed $pre, array $args, string $url ): array {
		$req          = array(
			'method'  => (string) ( $args['method'] ?? 'GET' ),
			'url'     => $url,
			'headers' => (array) ( $args['headers'] ?? array() ),
			'body'    => (string) ( $args['body'] ?? '' ),
		);
		$this->http[] = $req;
		$res          = ( $this->responder )( $req );
		return array(
			'headers'  => array(),
			'body'     => (string) $res['body'],
			'response' => array(
				'code'    => (int) $res['status'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * Simula una conexión vigente sin pasar por el canje.
	 *
	 * @param list<int>|null $signature Versiones de la firma que anuncia el hub (null: un hub anterior a la v2).
	 */
	protected function connect( ?array $signature = null ): void {
		Connection::store(
			array(
				'signature'  => null === $signature ? null : array( 'versions' => $signature ),
				'site_id'    => self::SITE_ID,
				'public_key' => 'pk_' . str_repeat( 'a', 32 ),
				'secret'     => self::SECRET,
				'workspace'  => array(
					'id'   => 'ws_01JD3QF0A1B2C3D4E5F6G7H8J9',
					'name' => 'Pinturas La 14',
				),
				'endpoints'  => array(
					'ingest_server'  => 'http://hub.test/v1/s',
					'ingest_browser' => 'http://hub.test/v1/b',
					'api'            => 'http://hub.test/',
				),
			)
		);
	}

	/**
	 * Sobres del outbox, decodificados (para leer valores).
	 *
	 * @param string|null $event Filtrar por evento.
	 * @return list<array<string, mixed>>
	 */
	protected function outbox( ?string $event = null ): array {
		return array_map( static fn( string $json ): array => (array) Json::decode( $json ), $this->outbox_raw( $event ) );
	}

	/**
	 * JSON exacto guardado en el outbox (lo que viaja al hub).
	 *
	 * @param string|null $event Filtrar por evento.
	 * @return list<string>
	 */
	protected function outbox_raw( ?string $event = null ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT event, payload FROM ' . Outbox::table() . ' ORDER BY id', ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( null === $event || $event === $row['event'] ) {
				$out[] = (string) $row['payload'];
			}
		}
		return $out;
	}

	/**
	 * Afirma que cada sobre cumple el contrato.
	 *
	 * @param list<array<string, mixed>|string> $envelopes Sobres o JSON del outbox.
	 */
	protected function assertContract( array $envelopes ): void {
		$this->assertNotEmpty( $envelopes, 'no hay eventos para validar' );
		foreach ( $envelopes as $envelope ) {
			$name = is_string( $envelope ) ? (string) ( json_decode( $envelope, true )['event'] ?? '?' ) : (string) ( $envelope['event'] ?? '?' );
			$this->assertSame( array(), Schema::errors( $envelope ), $name . ' no cumple el contrato' );
		}
	}

	/**
	 * Producto simple.
	 *
	 * @param string $price Precio.
	 * @param array  $extra Ajustes.
	 */
	protected function product( string $price = '150000', array $extra = array() ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $extra['name'] ?? 'Pintura Azul 1 galón' );
		$product->set_regular_price( $price );
		$product->set_sku( $extra['sku'] ?? 'PNT-' . wp_generate_password( 6, false ) );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( $extra['stock'] ?? 20 );
		$product->set_status( 'publish' );
		$product->save();
		return $product;
	}

	/**
	 * Pedido con datos colombianos.
	 *
	 * @param array<string, mixed> $args Opciones: gateway, status, customer, email, phone, qty, coupon.
	 */
	protected function order( array $args = array() ): \WC_Order {
		$product = $args['product'] ?? $this->product();
		$order   = wc_create_order( array( 'customer_id' => (int) ( $args['customer'] ?? 0 ) ) );
		$order->add_product( $product, (int) ( $args['qty'] ?? 2 ) );
		$order->set_billing_first_name( 'Ana' );
		$order->set_billing_last_name( 'Ruiz' );
		$order->set_billing_email( (string) ( $args['email'] ?? 'ana@example.com' ) );
		$order->set_billing_phone( (string) ( $args['phone'] ?? '300 123 4567' ) );
		$order->set_billing_city( 'Manizales' );
		$order->set_billing_state( 'CO-CAL' );
		$order->set_billing_country( 'CO' );
		$order->set_shipping_city( 'Manizales' );
		$order->set_shipping_state( 'CO-CAL' );
		$order->set_shipping_country( 'CO' );
		$order->set_payment_method( (string) ( $args['gateway'] ?? 'wompi' ) );
		$order->set_payment_method_title( (string) ( $args['gateway_title'] ?? 'Wompi - PSE' ) );
		$order->set_created_via( 'checkout' );
		if ( ! empty( $args['shipping'] ) ) {
			$ship = new \WC_Order_Item_Shipping();
			$ship->set_method_id( 'flat_rate' );
			$ship->set_method_title( 'Envío nacional' );
			$ship->set_total( '12000' );
			$order->add_item( $ship );
		}
		$order->calculate_totals( false );
		$order->set_status( (string) ( $args['status'] ?? 'pending' ) );
		$order->save();
		return $order;
	}
}
