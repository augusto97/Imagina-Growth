<?php
/**
 * Tienda colombiana de prueba para `wp igr demo-store` (CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Cli;

use ImaginaGrowth\Connector\Events\Recorder;

/**
 * Ferretería y pinturas en Manizales con clientes en todo el país: productos en COP, clientes
 * registrados e invitados, pedidos de 24 meses con estacionalidad (prima, Día de la Madre,
 * Amor y Amistad, Black Friday, Navidad), PSE, Nequi, contraentrega y transferencia, cupones,
 * reembolsos y reseñas. Los correos usan dominios reservados (example.com) para que nunca
 * llegue un mensaje a una persona real. Los recolectores quedan suprimidos: los datos entran
 * al hub por la importación histórica.
 */
final class DemoStore {

	public const OPTION = 'igr_demo_store';

	/**
	 * Ciudades: [ciudad, código ISO 3166-2, peso].
	 */
	private const CITIES = array(
		array( 'Bogotá', 'CO-DC', 26 ),
		array( 'Medellín', 'CO-ANT', 13 ),
		array( 'Cali', 'CO-VAC', 9 ),
		array( 'Manizales', 'CO-CAL', 8 ),
		array( 'Pereira', 'CO-RIS', 6 ),
		array( 'Barranquilla', 'CO-ATL', 6 ),
		array( 'Cartagena', 'CO-BOL', 4 ),
		array( 'Bucaramanga', 'CO-SAN', 4 ),
		array( 'Armenia', 'CO-QUI', 3 ),
		array( 'Ibagué', 'CO-TOL', 3 ),
		array( 'Cúcuta', 'CO-NSA', 2 ),
		array( 'Villavicencio', 'CO-MET', 2 ),
		array( 'Pasto', 'CO-NAR', 2 ),
		array( 'Santa Marta', 'CO-MAG', 2 ),
		array( 'Neiva', 'CO-HUI', 2 ),
		array( 'Chía', 'CO-CUN', 1 ),
		array( 'Envigado', 'CO-ANT', 1 ),
		array( 'Tunja', 'CO-BOY', 1 ),
		array( 'Popayán', 'CO-CAU', 1 ),
		array( 'Montería', 'CO-COR', 1 ),
		array( 'Chinchiná', 'CO-CAL', 1 ),
		array( 'Dosquebradas', 'CO-RIS', 1 ),
	);

	private const FIRST_NAMES = array( 'Ana', 'María', 'Luisa', 'Camila', 'Valentina', 'Daniela', 'Paula', 'Laura', 'Natalia', 'Carolina', 'Andrea', 'Juliana', 'Sofía', 'Isabel', 'Diana', 'Paola', 'Gloria', 'Marta', 'Lina', 'Sara', 'Juan', 'Carlos', 'Andrés', 'Felipe', 'Santiago', 'Sebastián', 'Diego', 'Jorge', 'Luis', 'Alejandro', 'Camilo', 'Mauricio', 'Óscar', 'Julián', 'Ricardo', 'Hernán', 'Fabio', 'Jaime', 'Esteban', 'Mateo' );

	private const LAST_NAMES = array( 'García', 'Rodríguez', 'Martínez', 'López', 'González', 'Hernández', 'Ramírez', 'Torres', 'Gómez', 'Díaz', 'Restrepo', 'Ospina', 'Giraldo', 'Valencia', 'Cardona', 'Arango', 'Zuluaga', 'Londoño', 'Castaño', 'Quintero', 'Morales', 'Jiménez', 'Ruiz', 'Vargas', 'Rojas', 'Muñoz', 'Salazar', 'Mejía', 'Correa', 'Henao' );

	private const EMAIL_DOMAINS = array( 'example.com', 'example.net', 'example.org' );

	/**
	 * Pasarelas: [id, título, peso].
	 */
	private const GATEWAYS = array(
		array( 'wompi', 'Wompi - PSE', 32 ),
		array( 'nequi', 'Nequi', 18 ),
		array( 'cod', 'Pago contra entrega', 20 ),
		array( 'bacs', 'Transferencia bancaria (Bancolombia)', 15 ),
		array( 'epayco', 'ePayco - Tarjeta de crédito', 15 ),
	);

	/**
	 * Generador aleatorio con semilla.
	 *
	 * @var SeededRandom
	 */
	private SeededRandom $rng;

	/**
	 * Registro de progreso.
	 *
	 * @var callable(string):void
	 */
	private $log;

	/**
	 * Productos creados: id → [precio, costo, peso, nombre, variaciones].
	 *
	 * @var list<array{id:int, variation:int, price:float, weight:float}>
	 */
	private array $catalog = array();

	/**
	 * Constructor.
	 *
	 * @param int                   $seed Semilla.
	 * @param callable(string):void $log  Registro.
	 */
	public function __construct( int $seed, callable $log ) {
		$this->rng = new SeededRandom( $seed );
		$this->log = $log;
	}

	/**
	 * Crea la tienda.
	 *
	 * @param int $customers Clientes (registrados + invitados).
	 * @param int $orders    Pedidos.
	 * @param int $months    Meses hacia atrás.
	 * @return array<string, int>
	 */
	public function run( int $customers, int $orders, int $months ): array {
		return Recorder::suppress(
			function () use ( $customers, $orders, $months ): array {
				$this->quiet();
				$this->settings();
				$this->products();
				$people            = $this->customers( $customers, $months );
				$result            = $this->orders( $people, $orders, $months );
				$result['reviews'] = $this->reviews( $result['review_candidates'] );
				unset( $result['review_candidates'] );
				$result['products']  = count( $this->catalog );
				$result['customers'] = count( $people );
				update_option( self::OPTION, array_merge( $result, array( 'created_at' => time() ) ), false );
				return $result;
			}
		);
	}

	/**
	 * Sin correos, sin descuento de stock y sin importaciones de Analytics por pedido.
	 */
	private function quiet(): void {
		if ( ! defined( 'WP_IMPORTING' ) ) {
			define( 'WP_IMPORTING', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Constante del núcleo que silencia efectos de importación.
		}
		add_filter( 'pre_wp_mail', '__return_false' );
		foreach ( WC()->mailer()->get_emails() as $email ) {
			add_filter( 'woocommerce_email_enabled_' . $email->id, '__return_false' );
		}
		foreach ( array( 'woocommerce_payment_complete', 'woocommerce_order_status_completed', 'woocommerce_order_status_processing', 'woocommerce_order_status_on-hold' ) as $hook ) {
			remove_action( $hook, 'wc_maybe_reduce_stock_levels' );
		}
		foreach ( array( 'woocommerce_order_status_cancelled', 'woocommerce_order_status_pending' ) as $hook ) {
			remove_action( $hook, 'wc_maybe_increase_stock_levels' );
		}
		// Analytics importa por lotes programados en vez de una acción por pedido.
		update_option( 'woocommerce_analytics_scheduled_import', 'yes' );
		add_filter( 'wp_hash_password_options', static fn( $options ) => array_merge( (array) $options, array( 'cost' => 4 ) ) );
		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
	}

	/**
	 * Ajustes de la tienda.
	 */
	private function settings(): void {
		update_option( 'woocommerce_currency', 'COP' );
		update_option( 'woocommerce_price_num_decimals', '0' );
		update_option( 'woocommerce_price_thousand_sep', '.' );
		update_option( 'woocommerce_price_decimal_sep', ',' );
		update_option( 'woocommerce_default_country', 'CO:CO-CAL' );
		update_option( 'woocommerce_store_city', 'Manizales' );
		update_option( 'woocommerce_store_address', 'Carrera 23 # 65-14' );
		update_option( 'woocommerce_calc_taxes', 'no' );
		update_option( 'timezone_string', 'America/Bogota' );
		update_option( 'blogname', 'Pinturas y Ferretería La 14' );
	}

	/**
	 * Categorías, marcas y ~60 productos (4 variables).
	 */
	private function products(): void {
		$cats = array(
			'Pinturas'               => array( 'Vinilos', 'Esmaltes', 'Impermeabilizantes', 'Estucos y masillas' ),
			'Herramientas'           => array( 'Manuales', 'Eléctricas' ),
			'Ferretería'             => array( 'Tornillería', 'Cerraduras y candados' ),
			'Plomería'               => array(),
			'Eléctricos'             => array(),
			'Adhesivos y selladores' => array(),
		);
		$ids  = array();
		foreach ( $cats as $parent => $children ) {
			$ids[ $parent ] = $this->term( $parent, 'product_cat' );
			foreach ( $children as $child ) {
				$ids[ $child ] = $this->term( $child, 'product_cat', $ids[ $parent ] );
			}
		}
		// [nombre, categoría, marca, precio COP, peso de popularidad].
		$simple = array(
			array( 'Vinilo Tipo 1 Blanco Galón', 'Vinilos', 'Pintuco', 98900, 9 ),
			array( 'Vinilo Koraza Exteriores Blanco Galón', 'Vinilos', 'Pintuco', 132900, 7 ),
			array( 'Vinilo Viniltex Blanco Cuñete 5 galones', 'Vinilos', 'Pintuco', 389900, 5 ),
			array( 'Vinilo Tipo 2 Colores Galón', 'Vinilos', 'Sapolin', 64900, 6 ),
			array( 'Vinilo Intervinil Blanco Galón', 'Vinilos', 'Pintuco', 74900, 6 ),
			array( 'Pintura para Cielos Blanca Galón', 'Vinilos', 'Corona', 58900, 3 ),
			array( 'Anticorrosivo Gris Galón', 'Esmaltes', 'Pintuco', 92500, 4 ),
			array( 'Esmalte Sintético Negro Cuarto', 'Esmaltes', 'Sapolin', 28900, 4 ),
			array( 'Laca Catalizada Transparente Galón', 'Esmaltes', 'Pintuco', 118000, 2 ),
			array( 'Pintura para Tráfico Amarilla Galón', 'Esmaltes', 'Pintuco', 99900, 1 ),
			array( 'Impermeabilizante Sika Techos 20 kg', 'Impermeabilizantes', 'Sika', 289000, 3 ),
			array( 'Sikalastic 560 Galón', 'Impermeabilizantes', 'Sika', 189900, 2 ),
			array( 'Igol Imprimante 1 galón', 'Impermeabilizantes', 'Sika', 79900, 2 ),
			array( 'Estuco Plástico Cuñete', 'Estucos y masillas', 'Pintuco', 119900, 4 ),
			array( 'Masilla Acrílica 1/4 galón', 'Estucos y masillas', 'Corona', 21900, 3 ),
			array( 'Rodillo de Felpa 9 pulgadas', 'Manuales', 'Goya', 18900, 8 ),
			array( 'Brocha Profesional 3 pulgadas', 'Manuales', 'Goya', 9900, 8 ),
			array( 'Bandeja para Pintura Plástica', 'Manuales', 'Goya', 8500, 5 ),
			array( 'Cinta de Enmascarar 1 pulgada x 40 m', 'Manuales', '3M', 6900, 9 ),
			array( 'Martillo de Uña 16 oz', 'Manuales', 'Truper', 24900, 4 ),
			array( 'Juego de Destornilladores 6 piezas', 'Manuales', 'Stanley', 39900, 4 ),
			array( 'Flexómetro 5 m', 'Manuales', 'Stanley', 22900, 5 ),
			array( 'Llave Expansiva 10 pulgadas', 'Manuales', 'Truper', 35900, 3 ),
			array( 'Nivel de Burbuja 24 pulgadas', 'Manuales', 'Truper', 29900, 2 ),
			array( 'Taladro Percutor 1/2 pulgada 650 W', 'Eléctricas', 'Bosch', 289900, 3 ),
			array( 'Pulidora 4 1/2 pulgadas 850 W', 'Eléctricas', 'DeWalt', 329900, 2 ),
			array( 'Atornillador Inalámbrico 12 V', 'Eléctricas', 'Black+Decker', 219900, 2 ),
			array( 'Sierra Caladora 450 W', 'Eléctricas', 'Black+Decker', 189900, 1 ),
			array( 'Pistola de Calor 1500 W', 'Eléctricas', 'Truper', 119900, 1 ),
			array( 'Tornillo Drywall 6x1 pulgada (100 und)', 'Tornillería', 'Fijaciones La 14', 7900, 7 ),
			array( 'Chazo Plástico 1/4 (100 und)', 'Tornillería', 'Fijaciones La 14', 5900, 6 ),
			array( 'Tornillo Autoperforante 8x1/2 (100 und)', 'Tornillería', 'Fijaciones La 14', 12900, 4 ),
			array( 'Puntilla con Cabeza 2 pulgadas (1 lb)', 'Tornillería', 'Fijaciones La 14', 6500, 5 ),
			array( 'Candado de Seguridad 40 mm', 'Cerraduras y candados', 'Yale', 32900, 3 ),
			array( 'Cerradura de Pomo Alcoba', 'Cerraduras y candados', 'Yale', 49900, 3 ),
			array( 'Cerradura de Seguridad Puerta Principal', 'Cerraduras y candados', 'Yale', 189900, 2 ),
			array( 'Tubo PVC Presión 1/2 pulgada x 6 m', 'Plomería', 'Pavco', 18900, 4 ),
			array( 'Codo PVC 1/2 pulgada (10 und)', 'Plomería', 'Pavco', 6900, 4 ),
			array( 'Llave de Paso 1/2 pulgada', 'Plomería', 'Grival', 24900, 3 ),
			array( 'Grifería Lavamanos Monocontrol', 'Plomería', 'Grival', 159900, 2 ),
			array( 'Sanitario Ahorrador Blanco', 'Plomería', 'Corona', 459900, 1 ),
			array( 'Limpiador y Soldadura PVC 1/32 galón', 'Plomería', 'Pavco', 15900, 3 ),
			array( 'Cable Eléctrico Calibre 12 (rollo 100 m)', 'Eléctricos', 'Centelsa', 249900, 2 ),
			array( 'Bombillo LED 9 W (pack x 4)', 'Eléctricos', 'Philips', 23900, 7 ),
			array( 'Toma Doble con Polo a Tierra', 'Eléctricos', 'Legrand', 8900, 4 ),
			array( 'Interruptor Sencillo', 'Eléctricos', 'Legrand', 7900, 4 ),
			array( 'Extensión Eléctrica 5 m', 'Eléctricos', 'Philips', 21900, 3 ),
			array( 'Reflector LED 50 W', 'Eléctricos', 'Philips', 69900, 2 ),
			array( 'Silicona Transparente 280 ml', 'Adhesivos y selladores', 'Sika', 19900, 6 ),
			array( 'Pegante Bóxer 1/4 galón', 'Adhesivos y selladores', 'Bóxer', 26900, 3 ),
			array( 'Espuma Expansiva 500 ml', 'Adhesivos y selladores', 'Sika', 32900, 2 ),
			array( 'Pegacor Gris 25 kg', 'Adhesivos y selladores', 'Corona', 34900, 4 ),
			array( 'Sellador de Juntas Blanco 2 kg', 'Adhesivos y selladores', 'Corona', 14900, 3 ),
			array( 'Lija de Agua N.º 150 (pliego)', 'Manuales', '3M', 2500, 7 ),
			array( 'Espátula Metálica 4 pulgadas', 'Manuales', 'Goya', 11900, 4 ),
			array( 'Overol de Pintor Desechable', 'Manuales', '3M', 18900, 2 ),
		);
		foreach ( $simple as $i => $row ) {
			$product = new \WC_Product_Simple();
			$product->set_name( $row[0] );
			$product->set_sku( 'L14-' . str_pad( (string) ( $i + 1 ), 4, '0', STR_PAD_LEFT ) );
			$product->set_regular_price( (string) $row[3] );
			if ( 0 === $i % 9 ) {
				$product->set_sale_price( (string) ( round( $row[3] * 0.85 / 100 ) * 100 ) );
			}
			$product->set_category_ids( array( $ids[ $row[1] ] ) );
			$product->set_manage_stock( true );
			$product->set_stock_quantity( 0 === $i % 17 ? 0 : $this->rng->getInt( 4, 240 ) );
			$product->set_status( 'publish' );
			$product->update_meta_data( '_wc_cog_cost', (string) round( $row[3] * $this->rng->getInt( 52, 68 ) / 100 ) );
			$id = (int) $product->save();
			$this->brand( $id, $row[2] );
			$this->catalog[] = array(
				'id'        => $id,
				'variation' => 0,
				'price'     => (float) $product->get_price(),
				'weight'    => (float) $row[4],
			);
		}
		// Variables: [nombre, categoría, marca, [[atributo, precio], …], peso].
		$variable = array(
			array( 'Esmalte Doméstico Blanco', 'Esmaltes', 'Pintuco', array( array( 'Cuarto', 34900 ), array( 'Galón', 109900 ) ), 5 ),
			array( 'Vinilo Viniltex Color', 'Vinilos', 'Pintuco', array( array( 'Galón', 89900 ), array( 'Cuñete', 339900 ) ), 6 ),
			array( 'Brocha Popular', 'Manuales', 'Goya', array( array( '1 pulgada', 3900 ), array( '2 pulgadas', 5900 ), array( '4 pulgadas', 10900 ) ), 6 ),
			array( 'Thinner Corriente', 'Esmaltes', 'Sapolin', array( array( 'Botella', 9900 ), array( 'Galón', 39900 ) ), 4 ),
		);
		foreach ( $variable as $row ) {
			$attribute = new \WC_Product_Attribute();
			$attribute->set_name( 'Presentación' );
			$attribute->set_options( array_column( $row[3], 0 ) );
			$attribute->set_visible( true );
			$attribute->set_variation( true );
			$parent = new \WC_Product_Variable();
			$parent->set_name( $row[0] );
			$parent->set_sku( 'L14-V' . substr( md5( $row[0] ), 0, 4 ) );
			$parent->set_category_ids( array( $ids[ $row[1] ] ) );
			$parent->set_attributes( array( $attribute ) );
			$parent->set_status( 'publish' );
			$parent_id = (int) $parent->save();
			$this->brand( $parent_id, $row[2] );
			foreach ( $row[3] as $j => $variant ) {
				$variation = new \WC_Product_Variation();
				$variation->set_parent_id( $parent_id );
				$variation->set_attributes( array( sanitize_title( 'Presentación' ) => $variant[0] ) );
				$variation->set_regular_price( (string) $variant[1] );
				$variation->set_sku( $parent->get_sku() . '-' . ( $j + 1 ) );
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $this->rng->getInt( 5, 120 ) );
				$variation->update_meta_data( '_wc_cog_cost', (string) round( $variant[1] * 0.6 ) );
				$variation_id    = (int) $variation->save();
				$this->catalog[] = array(
					'id'        => $parent_id,
					'variation' => $variation_id,
					'price'     => (float) $variant[1],
					'weight'    => $row[4] / count( $row[3] ),
				);
			}
			\WC_Product_Variable::sync( $parent_id );
		}
		wp_defer_term_counting( false );
		wp_defer_term_counting( true );
		( $this->log )( sprintf( 'Productos: %d (con variaciones).', count( $this->catalog ) ) );
	}

	/**
	 * Clientes: 65 % registrados, el resto invitados.
	 *
	 * @param int $count  Cantidad.
	 * @param int $months Meses.
	 * @return list<array<string, mixed>>
	 */
	private function customers( int $count, int $months ): array {
		$people = array();
		$used   = array();
		$start  = time() - $months * 30 * DAY_IN_SECONDS;
		for ( $i = 0; $i < $count; $i++ ) {
			$first = $this->pick( self::FIRST_NAMES );
			$last  = $this->pick( self::LAST_NAMES ) . ( $this->rng->getInt( 1, 100 ) <= 40 ? ' ' . $this->pick( self::LAST_NAMES ) : '' );
			$city  = $this->weighted( self::CITIES, 2 );
			$base  = $this->ascii( strtolower( $first . '.' . strtok( $last, ' ' ) ) );
			do {
				$email = $base . $this->rng->getInt( 1, 999 ) . '@' . $this->pick( self::EMAIL_DOMAINS );
			} while ( isset( $used[ $email ] ) );
			$used[ $email ] = true;
			$mobile         = '3' . $this->pick( array( '00', '01', '02', '04', '05', '10', '11', '12', '13', '14', '15', '16', '17', '18', '20', '21', '50', '51' ) ) . str_pad( (string) $this->rng->getInt( 0, 9999999 ), 7, '0', STR_PAD_LEFT );
			$phone          = match ( $this->rng->getInt( 1, 4 ) ) {
				1 => '+57 ' . $mobile,
				2 => substr( $mobile, 0, 3 ) . ' ' . substr( $mobile, 3, 3 ) . ' ' . substr( $mobile, 6 ),
				default => $mobile,
			};
			$person = array(
				'user_id'    => 0,
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'phone'      => $phone,
				'city'       => $city[0],
				'state'      => $city[1],
				'address'    => 'Calle ' . $this->rng->getInt( 1, 120 ) . ' # ' . $this->rng->getInt( 1, 90 ) . '-' . $this->rng->getInt( 1, 99 ),
			);
			if ( $this->rng->getInt( 1, 100 ) <= 65 ) {
				$registered = $start + $this->rng->getInt( 0, max( 1, time() - $start - 30 * DAY_IN_SECONDS ) );
				$user_id    = wp_insert_user(
					array(
						'user_login'      => sanitize_user( strstr( $email, '@', true ) . $i, true ),
						'user_email'      => $email,
						'user_pass'       => wp_generate_password( 16 ),
						'first_name'      => $first,
						'last_name'       => $last,
						'display_name'    => $first . ' ' . $last,
						'role'            => 'customer',
						'user_registered' => gmdate( 'Y-m-d H:i:s', $registered ),
					)
				);
				if ( ! is_wp_error( $user_id ) ) {
					$person['user_id'] = (int) $user_id;
					foreach ( array(
						'billing_first_name' => $first,
						'billing_last_name'  => $last,
						'billing_email'      => $email,
						'billing_phone'      => $phone,
						'billing_city'       => $city[0],
						'billing_state'      => $city[1],
						'billing_country'    => 'CO',
						'billing_address_1'  => $person['address'],
					) as $key => $value ) {
						update_user_meta( (int) $user_id, $key, $value );
					}
				}
			}
			$people[] = $person;
			if ( 0 === ( $i + 1 ) % 500 ) {
				( $this->log )( sprintf( 'Clientes: %d / %d', $i + 1, $count ) );
			}
		}
		return $people;
	}

	/**
	 * Pedidos: la mayoría de los clientes compra una vez; unos pocos (contratistas) muchas.
	 *
	 * @param list<array<string, mixed>> $people Clientes.
	 * @param int                        $total  Pedidos.
	 * @param int                        $months Meses.
	 * @return array<string, mixed>
	 */
	private function orders( array $people, int $total, int $months ): array {
		$n      = count( $people );
		$counts = array();
		foreach ( $people as $i => $p ) {
			$r            = $this->rng->getInt( 1, 100 );
			$counts[ $i ] = $r <= 55 ? 1 : ( $r <= 75 ? $this->rng->getInt( 2, 3 ) : ( $r <= 90 ? $this->rng->getInt( 4, 9 ) : $this->rng->getInt( 10, 50 ) ) );
		}
		// Ajusta la suma exacta repartiendo la diferencia entre los clientes frecuentes.
		$diff = $total - array_sum( $counts );
		while ( 0 !== $diff ) {
			$i = $this->rng->getInt( 0, $n - 1 );
			if ( $diff > 0 && $counts[ $i ] >= 2 ) {
				++$counts[ $i ];
				--$diff;
			} elseif ( $diff < 0 && $counts[ $i ] > 1 ) {
				--$counts[ $i ];
				++$diff;
			}
		}

		$now     = time();
		$start   = $now - $months * 30 * DAY_IN_SECONDS;
		$coupons = $this->coupons();
		$made    = 0;
		$stats   = array(
			'orders'  => 0,
			'refunds' => 0,
		);
		$reviews = array();
		$t0      = microtime( true );
		foreach ( $people as $i => $person ) {
			$dates = array();
			for ( $k = 0; $k < $counts[ $i ]; $k++ ) {
				$dates[] = $this->order_time( $start, $now );
			}
			sort( $dates );
			foreach ( $dates as $ts ) {
				$order = $this->order( $person, $ts, $now, $coupons, $stats );
				if ( null !== $order && 'completed' === $order->get_status() && $person['user_id'] > 0 && $this->rng->getInt( 1, 100 ) <= 7 ) {
					$reviews[] = array( $order->get_id(), $ts );
				}
				++$made;
				if ( 0 === $made % 500 ) {
					( $this->log )( sprintf( 'Pedidos: %d / %d (%.1f pedidos/s)', $made, $total, $made / max( 0.001, microtime( true ) - $t0 ) ) );
					wp_cache_flush_runtime();
				}
			}
		}
		$stats['review_candidates'] = $reviews;
		return $stats;
	}

	/**
	 * Crea un pedido.
	 *
	 * @param array<string, mixed> $person  Cliente.
	 * @param int                  $ts      Hora del pedido.
	 * @param int                  $now     Ahora.
	 * @param array<string, int>   $coupons Cupones.
	 * @param array<string, int>   $stats   Contadores.
	 */
	private function order( array $person, int $ts, int $now, array $coupons, array &$stats ): ?\WC_Order {
		$gateway = $this->weighted( self::GATEWAYS, 2 );
		$order   = new \WC_Order();
		$order->set_created_via( 'checkout' );
		$order->set_currency( 'COP' );
		$order->set_customer_id( (int) $person['user_id'] );
		$order->set_payment_method( $gateway[0] );
		$order->set_payment_method_title( $gateway[1] );
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$order->{"set_{$type}_first_name"}( (string) $person['first_name'] );
			$order->{"set_{$type}_last_name"}( (string) $person['last_name'] );
			$order->{"set_{$type}_address_1"}( (string) $person['address'] );
			$order->{"set_{$type}_city"}( (string) $person['city'] );
			$order->{"set_{$type}_state"}( (string) $person['state'] );
			$order->{"set_{$type}_country"}( 'CO' );
		}
		$order->set_billing_email( (string) $person['email'] );
		$order->set_billing_phone( (string) $person['phone'] );
		$order->set_customer_ip_address( '190.' . $this->rng->getInt( 24, 159 ) . '.' . $this->rng->getInt( 0, 255 ) . '.' . $this->rng->getInt( 1, 254 ) );
		$order->set_customer_user_agent( $this->rng->getInt( 1, 100 ) <= 72 ? 'Mozilla/5.0 (Linux; Android 14; SM-A546E) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' );

		$lines = $this->rng->getInt( 1, 100 ) <= 50 ? 1 : $this->rng->getInt( 2, 4 );
		$used  = array();
		for ( $l = 0; $l < $lines; $l++ ) {
			$entry = $this->weighted_catalog();
			$key   = $entry['id'] . ':' . $entry['variation'];
			if ( isset( $used[ $key ] ) ) {
				continue;
			}
			$used[ $key ] = true;
			$product      = wc_get_product( $entry['variation'] > 0 ? $entry['variation'] : $entry['id'] );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}
			$qty = $entry['price'] < 15000 ? $this->rng->getInt( 1, 8 ) : ( $entry['price'] < 100000 ? $this->rng->getInt( 1, 3 ) : 1 );
			$order->add_product( $product, $qty );
		}
		$order->calculate_totals( false );
		$subtotal = (float) $order->get_subtotal();

		$shipping = new \WC_Order_Item_Shipping();
		if ( 'Manizales' === $person['city'] && $this->rng->getInt( 1, 100 ) <= 30 ) {
			$shipping->set_method_id( 'local_pickup' );
			$shipping->set_method_title( 'Recoger en tienda (Manizales)' );
			$shipping->set_total( '0' );
		} elseif ( $subtotal >= 250000 ) {
			$shipping->set_method_id( 'free_shipping' );
			$shipping->set_method_title( 'Envío gratis' );
			$shipping->set_total( '0' );
		} else {
			$shipping->set_method_id( 'flat_rate' );
			$shipping->set_method_title( 'Envío nacional' );
			$shipping->set_total( 'Manizales' === $person['city'] ? '6000' : '14000' );
		}
		$order->add_item( $shipping );

		$coupon = $this->coupon_for( $ts, $coupons );
		if ( null !== $coupon ) {
			$order->apply_coupon( $coupon );
		}
		$order->calculate_totals( false );

		$age    = $now - $ts;
		$status = $this->status( $gateway[0], $age );
		$date   = new \WC_DateTime( '@' . $ts );
		$order->set_date_created( $date );
		if ( in_array( $status, array( 'processing', 'completed', 'refunded' ), true ) && ( 'cod' !== $gateway[0] || 'completed' === $status || 'refunded' === $status ) ) {
			$paid_at = 'cod' === $gateway[0] ? $ts + $this->rng->getInt( 2, 6 ) * DAY_IN_SECONDS : $ts + $this->rng->getInt( 60, 7200 );
			$order->set_date_paid( new \WC_DateTime( '@' . min( $now, $paid_at ) ) );
		} elseif ( 'processing' === $status ) {
			// Contraentrega por despachar: WooCommerce marcaría date_paid; se deja sin fecha de pago.
			$order->set_date_paid( null );
		}
		if ( in_array( $status, array( 'completed', 'refunded' ), true ) ) {
			$order->set_date_completed( new \WC_DateTime( '@' . min( $now, $ts + $this->rng->getInt( 2, 8 ) * DAY_IN_SECONDS ) ) );
		}
		$final = 'refunded' === $status ? 'completed' : $status;
		$order->set_status( $final );
		$order->save();
		++$stats['orders'];

		// Reembolsos: totales (estado refunded) y parciales sobre pedidos completados.
		$partial = 'completed' === $status && $this->rng->getInt( 1, 100 ) <= 2;
		if ( 'refunded' === $status || $partial ) {
			$amount = 'refunded' === $status ? (float) $order->get_total() : round( (float) $order->get_total() * $this->rng->getInt( 15, 50 ) / 100 / 100 ) * 100;
			$refund = wc_create_refund(
				array(
					'order_id'       => $order->get_id(),
					'amount'         => (string) $amount,
					'reason'         => $this->pick( array( 'Producto defectuoso', 'Color diferente al esperado', 'El cliente desistió de la compra', 'Faltó un producto en el envío' ) ),
					'refund_payment' => false,
					'restock_items'  => false,
				)
			);
			if ( $refund instanceof \WC_Order_Refund ) {
				$refund->set_date_created( new \WC_DateTime( '@' . min( $now, $ts + $this->rng->getInt( 5, 20 ) * DAY_IN_SECONDS ) ) );
				$refund->save();
				++$stats['refunds'];
			}
			$order = wc_get_order( $order->get_id() );
		}
		return $order instanceof \WC_Order ? $order : null;
	}

	/**
	 * Estado según la antigüedad y la pasarela.
	 *
	 * @param string $gateway Pasarela.
	 * @param int    $age     Segundos desde el pedido.
	 */
	private function status( string $gateway, int $age ): string {
		$r = $this->rng->getInt( 1, 1000 );
		if ( $age < 4 * DAY_IN_SECONDS ) {
			if ( 'bacs' === $gateway ) {
				return $r <= 550 ? 'on-hold' : ( $r <= 900 ? 'processing' : 'cancelled' );
			}
			if ( 'cod' === $gateway ) {
				return $r <= 800 ? 'processing' : ( $r <= 900 ? 'completed' : 'cancelled' );
			}
			if ( 'wompi' === $gateway && $r <= 120 ) {
				return 'pending';
			}
			return $r <= 550 ? 'processing' : ( $r <= 900 ? 'completed' : ( $r <= 950 ? 'failed' : 'cancelled' ) );
		}
		if ( 'cod' === $gateway ) {
			return $r <= 870 ? 'completed' : 'cancelled';
		}
		if ( $r <= 830 ) {
			return 'completed';
		}
		if ( $r <= 900 ) {
			return 'cancelled';
		}
		if ( $r <= 960 ) {
			return 'failed';
		}
		return 'refunded';
	}

	/**
	 * Hora de un pedido con estacionalidad colombiana y crecimiento.
	 *
	 * @param int $start Inicio.
	 * @param int $now   Ahora.
	 */
	private function order_time( int $start, int $now ): int {
		$tz = new \DateTimeZone( 'America/Bogota' );
		for ( $attempt = 0; $attempt < 50; $attempt++ ) {
			$ts    = $this->rng->getInt( $start, $now - 600 );
			$date  = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( $tz );
			$month = (int) $date->format( 'n' );
			$day   = (int) $date->format( 'j' );
			$w     = 1.0 + 0.5 * ( $ts - $start ) / max( 1, $now - $start ); // Crecimiento.
			$w    *= match ( $month ) {
				12 => 1.5,
				6 => 1.25,
				5 => 1.15,
				9 => 1.1,
				11 => $day >= 20 ? 1.6 : 1.1,
				1, 2 => 0.85,
				default => 1.0,
			};
			$w   *= '7' === $date->format( 'N' ) ? 0.55 : 1.0;
			$hour = (int) $date->format( 'G' );
			$w   *= $hour < 7 ? 0.1 : ( $hour >= 22 ? 0.4 : 1.0 );
			if ( $this->rng->getFloat( 0, 2.6 ) <= $w ) {
				return $ts;
			}
		}
		return $this->rng->getInt( $start, $now - 600 );
	}

	/**
	 * Cupones de la tienda.
	 *
	 * @return array<string, int>
	 */
	private function coupons(): array {
		$defs = array(
			'BIENVENIDA10' => array( 'percent', 10 ),
			'PRIMA15'      => array( 'percent', 15 ),
			'BLACK20'      => array( 'percent', 20 ),
			'ENVIO0'       => array( 'fixed_cart', 10000 ),
		);
		$out  = array();
		foreach ( $defs as $code => $def ) {
			$existing = wc_get_coupon_id_by_code( $code );
			if ( $existing ) {
				$out[ $code ] = (int) $existing;
				continue;
			}
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->set_discount_type( $def[0] );
			$coupon->set_amount( (string) $def[1] );
			$out[ $code ] = (int) $coupon->save();
		}
		return $out;
	}

	/**
	 * Cupón según la fecha (prima en junio y diciembre, Black Friday a fin de noviembre).
	 *
	 * @param int                $ts      Hora.
	 * @param array<string, int> $coupons Cupones.
	 */
	private function coupon_for( int $ts, array $coupons ): ?string {
		$date  = ( new \DateTimeImmutable( '@' . $ts ) )->setTimezone( new \DateTimeZone( 'America/Bogota' ) );
		$month = (int) $date->format( 'n' );
		$r     = $this->rng->getInt( 1, 100 );
		if ( 11 === $month && (int) $date->format( 'j' ) >= 20 && $r <= 35 ) {
			return 'BLACK20';
		}
		if ( in_array( $month, array( 6, 12 ), true ) && $r <= 18 ) {
			return 'PRIMA15';
		}
		if ( $r <= 9 ) {
			return 'BIENVENIDA10';
		}
		return $r <= 12 ? 'ENVIO0' : null;
	}

	/**
	 * Reseñas de compras completadas, con nota sesgada a 4-5 estrellas.
	 *
	 * @param list<array{0:int, 1:int}> $candidates Pedidos y fechas.
	 */
	private function reviews( array $candidates ): int {
		$count    = 0;
		$products = array();
		$texts    = array(
			5 => array( 'Excelente calidad, cubre muy bien con dos manos.', 'Llegó rápido a Bogotá y bien empacado.', 'Muy buen producto, lo recomiendo.', 'El mejor precio que encontré y la entrega fue puntual.' ),
			4 => array( 'Buen producto, el envío tardó un poco más de lo esperado.', 'Cumple lo prometido.', 'Buena relación calidad-precio.' ),
			3 => array( 'Normal, esperaba un poco más.', 'Funciona, pero el empaque llegó golpeado.' ),
			2 => array( 'El color no era igual al de la foto.', 'Demoró mucho la entrega.' ),
			1 => array( 'Llegó dañado y tocó devolverlo.' ),
		);
		foreach ( $candidates as $candidate ) {
			$order = wc_get_order( $candidate[0] );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}
			$items = array_values( $order->get_items() );
			$item  = $items[0] ?? null;
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$r      = $this->rng->getInt( 1, 100 );
			$rating = $r <= 62 ? 5 : ( $r <= 86 ? 4 : ( $r <= 93 ? 3 : ( $r <= 97 ? 2 : 1 ) ) );
			$when   = min( time(), $candidate[1] + $this->rng->getInt( 6, 25 ) * DAY_IN_SECONDS );
			$id     = wp_insert_comment(
				array(
					'comment_post_ID'      => $item->get_product_id(),
					'comment_author'       => $order->get_billing_first_name() . ' ' . mb_substr( $order->get_billing_last_name(), 0, 1 ) . '.',
					'comment_author_email' => $order->get_billing_email(),
					'comment_content'      => $this->pick( $texts[ $rating ] ),
					'comment_type'         => 'review',
					'comment_approved'     => 1,
					'user_id'              => $order->get_customer_id(),
					'comment_date'         => wp_date( 'Y-m-d H:i:s', $when ),
					'comment_date_gmt'     => gmdate( 'Y-m-d H:i:s', $when ),
				)
			);
			if ( $id ) {
				add_comment_meta( (int) $id, 'rating', $rating );
				add_comment_meta( (int) $id, 'verified', 1 );
				$products[ $item->get_product_id() ] = true;
				++$count;
			}
		}
		foreach ( array_keys( $products ) as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( $product instanceof \WC_Product ) {
				\WC_Comments::clear_transients( $product_id );
			}
		}
		wp_defer_comment_counting( false );
		wp_defer_term_counting( false );
		return $count;
	}

	/**
	 * Crea o reutiliza un término.
	 *
	 * @param string $name     Nombre.
	 * @param string $taxonomy Taxonomía.
	 * @param int    $parent   Padre.
	 */
	private function term( string $name, string $taxonomy, int $parent = 0 ): int {
		$existing = term_exists( $name, $taxonomy, $parent );
		if ( is_array( $existing ) ) {
			return (int) $existing['term_id'];
		}
		$created = wp_insert_term( $name, $taxonomy, array( 'parent' => $parent ) );
		return is_array( $created ) ? (int) $created['term_id'] : 0;
	}

	/**
	 * Asigna la marca (taxonomía de marcas de WooCommerce si existe).
	 *
	 * @param int    $product_id Producto.
	 * @param string $brand      Marca.
	 */
	private function brand( int $product_id, string $brand ): void {
		if ( taxonomy_exists( 'product_brand' ) ) {
			$term_id = $this->term( $brand, 'product_brand' );
			if ( $term_id > 0 ) {
				wp_set_object_terms( $product_id, array( $term_id ), 'product_brand' );
			}
		}
	}

	/**
	 * Producto del catálogo según su popularidad.
	 *
	 * @return array{id:int, variation:int, price:float, weight:float}
	 */
	private function weighted_catalog(): array {
		$sum = array_sum( array_column( $this->catalog, 'weight' ) );
		$r   = $this->rng->getFloat( 0, $sum );
		foreach ( $this->catalog as $entry ) {
			$r -= $entry['weight'];
			if ( $r <= 0 ) {
				return $entry;
			}
		}
		return $this->catalog[ count( $this->catalog ) - 1 ];
	}

	/**
	 * Elemento al azar de una lista con pesos en la columna `$col`.
	 *
	 * @param list<array<int, mixed>> $rows Filas.
	 * @param int                     $col  Columna del peso.
	 * @return array<int, mixed>
	 */
	private function weighted( array $rows, int $col ): array {
		$sum = array_sum( array_column( $rows, $col ) );
		$r   = $this->rng->getInt( 1, (int) $sum );
		foreach ( $rows as $row ) {
			$r -= (int) $row[ $col ];
			if ( $r <= 0 ) {
				return $row;
			}
		}
		return $rows[0];
	}

	/**
	 * Elemento al azar.
	 *
	 * @param list<string> $list Lista.
	 */
	private function pick( array $list ): string {
		return $list[ $this->rng->getInt( 0, count( $list ) - 1 ) ];
	}

	/**
	 * Quita tildes para el correo.
	 *
	 * @param string $text Texto.
	 */
	private function ascii( string $text ): string {
		return (string) preg_replace( '/[^a-z0-9.]/', '', remove_accents( $text ) );
	}
}
