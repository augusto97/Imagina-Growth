<?php
/**
 * Plugin Name: Imagina Growth · pasarela de tarjeta para la prueba E2E
 * Description: Solo para infra/e2e/fase1.sh. Aprueba el pago al instante, como una pasarela de tarjeta (Wompi, Bold…).
 *
 * @package ImaginaGrowth\E2E
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
			return;
		}

		/** Pasarela que cobra al instante. */
		final class Igr_E2E_Card_Gateway extends WC_Payment_Gateway {
			/** Configuración. */
			public function __construct() {
				$this->id                 = 'igr_e2e_card';
				$this->method_title       = 'Tarjeta (prueba E2E)';
				$this->title              = 'Tarjeta (prueba E2E)';
				$this->has_fields         = false;
				$this->enabled            = 'yes';
				$this->supports           = array( 'products' );
				$this->method_description = 'Aprueba el pago al instante.';
			}

			/**
			 * Cobra y redirige a la página de gracias.
			 *
			 * @param int $order_id Pedido.
			 * @return array<string, string>
			 */
			public function process_payment( $order_id ): array {
				$order = wc_get_order( $order_id );
				$order->payment_complete( 'e2e-' . $order_id );
				WC()->cart->empty_cart();
				return array(
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				);
			}
		}

		add_filter(
			'woocommerce_payment_gateways',
			static function ( array $gateways ): array {
				$gateways[] = 'Igr_E2E_Card_Gateway';
				return $gateways;
			}
		);
	}
);
