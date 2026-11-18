<?php
/**
 * Casillas de consentimiento de marketing en el checkout (connector/CLAUDE.md §11, cumplimiento.md §3.1).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Consent;

use ImaginaGrowth\Connector\Connection\RemoteConfig;

/**
 * Una casilla por canal, **sin marcar**, con el texto y la versión que da el hub
 * (`/v1/s/config consent.texts`). Se guarda en la meta `_igr_consents` del pedido
 * y viaja en `order.consents`. Checkout clásico y de bloques.
 */
final class CheckoutConsent {

	public const META      = '_igr_consents';
	private const CHANNELS = array( 'whatsapp', 'email' );
	private const FIELD    = 'imagina-growth/marketing-';

	/**
	 * Registra los hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_review_order_before_submit', array( self::class, 'render_classic' ) );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'save_classic' ), 20, 1 );
		if ( did_action( 'woocommerce_init' ) ) {
			self::register_block_fields();
		} else {
			add_action( 'woocommerce_init', array( self::class, 'register_block_fields' ) );
		}
		add_action( 'woocommerce_set_additional_field_value', array( self::class, 'save_block_field' ), 10, 4 );
	}

	/**
	 * Textos por canal: los del hub o, si no hay, los locales.
	 *
	 * @return array<string, array{label: string, version: string}>
	 */
	public static function texts(): array {
		$out    = array();
		$stored = get_option( RemoteConfig::OPTION, array() );
		foreach ( (array) ( is_array( $stored ) ? ( $stored['consent']['texts'] ?? array() ) : array() ) as $text ) {
			if ( is_array( $text ) && 'marketing_consent' === ( $text['kind'] ?? '' ) && in_array( $text['channel'] ?? '', self::CHANNELS, true ) && '' !== trim( (string) ( $text['label'] ?? '' ) ) ) {
				$out[ (string) $text['channel'] ] = array(
					'label'   => mb_substr( wp_strip_all_tags( (string) $text['label'] ), 0, 500 ),
					'version' => mb_substr( (string) ( $text['version'] ?? 'v1' ), 0, 16 ),
				);
			}
		}
		if ( array() !== $out ) {
			return $out;
		}
		return array(
			'whatsapp' => array(
				'label'   => __( 'Quiero recibir ofertas y novedades por WhatsApp. Puedo darme de baja cuando quiera.', 'imagina-growth' ),
				'version' => 'local-1',
			),
			'email'    => array(
				'label'   => __( 'Quiero recibir ofertas y novedades por correo. Puedo darme de baja cuando quiera.', 'imagina-growth' ),
				'version' => 'local-1',
			),
		);
	}

	/**
	 * Checkout clásico: las casillas antes del botón de pagar.
	 */
	public static function render_classic(): void {
		echo '<div class="igr-consents" style="margin:0 0 1em">';
		foreach ( self::texts() as $channel => $text ) {
			printf(
				'<p class="form-row igr-consent"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox"><input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="igr_consent[%1$s]" value="1"> <span>%2$s</span></label></p>',
				esc_attr( $channel ),
				esc_html( $text['label'] )
			);
		}
		$privacy = get_privacy_policy_url();
		if ( '' !== $privacy ) {
			printf( '<p class="igr-consent-privacy"><small><a href="%s" target="_blank" rel="noopener">%s</a></small></p>', esc_url( $privacy ), esc_html__( 'Cómo tratamos tus datos', 'imagina-growth' ) );
		}
		echo '</div>';
	}

	/**
	 * Checkout clásico: guarda lo que marcó la persona (lo no marcado queda en `false`).
	 *
	 * @param mixed $order Pedido.
	 */
	public static function save_classic( mixed $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce ya verificó el nonce del checkout.
		$posted = isset( $_POST['igr_consent'] ) && is_array( $_POST['igr_consent'] ) ? array_map( 'sanitize_key', array_keys( wp_unslash( $_POST['igr_consent'] ) ) ) : array();
		$texts  = self::texts();
		$values = array();
		foreach ( array_keys( $texts ) as $channel ) {
			$values[ $channel ] = in_array( $channel, $posted, true );
		}
		$order->update_meta_data( self::META, self::meta( $values, $texts, 'checkout' ) );
	}

	/**
	 * Checkout de bloques: un campo adicional por canal (WooCommerce 8.9+).
	 */
	public static function register_block_fields(): void {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		foreach ( self::texts() as $channel => $text ) {
			try {
				woocommerce_register_additional_checkout_field(
					array(
						'id'       => self::FIELD . $channel,
						'label'    => $text['label'],
						'location' => 'order',
						'type'     => 'checkbox',
						'required' => false,
					)
				);
			} catch ( \Throwable $e ) {
				// Ya registrado o API distinta: el checkout sigue funcionando.
				unset( $e );
			}
		}
	}

	/**
	 * Checkout de bloques: WooCommerce guarda cada campo; aquí se refleja en `_igr_consents`.
	 *
	 * @param mixed $key    Campo.
	 * @param mixed $value  Valor.
	 * @param mixed $group  Grupo.
	 * @param mixed $object Pedido o cliente.
	 */
	public static function save_block_field( mixed $key, mixed $value, mixed $group, mixed $object ): void {
		if ( ! $object instanceof \WC_Order || ! is_string( $key ) || ! str_starts_with( $key, self::FIELD ) ) {
			return;
		}
		$channel = substr( $key, strlen( self::FIELD ) );
		$texts   = self::texts();
		if ( ! isset( $texts[ $channel ] ) ) {
			return;
		}
		$current = $object->get_meta( self::META, true );
		$values  = array();
		foreach ( array_keys( $texts ) as $c ) {
			$values[ $c ] = is_array( $current ) && ! empty( $current[ $c . '_marketing' ] );
		}
		$values[ $channel ] = in_array( $value, array( true, 1, '1', 'true', 'yes', 'on' ), true );
		$object->update_meta_data( self::META, self::meta( $values, $texts, 'checkout' ) );
	}

	/**
	 * Meta del pedido con el formato de `order.consents` (eventos.md §5).
	 *
	 * @param array<string, bool>                                $values Canal → marcado.
	 * @param array<string, array{label: string, version: string}> $texts  Textos mostrados.
	 * @param string                                             $source Origen.
	 * @return array<string, mixed>
	 */
	public static function meta( array $values, array $texts, string $source ): array {
		$out      = array();
		$versions = array();
		foreach ( $values as $channel => $checked ) {
			$out[ $channel . '_marketing' ] = (bool) $checked;
			$versions[ $channel ]           = $texts[ $channel ]['version'] ?? '';
		}
		$unique              = array_values( array_unique( array_filter( $versions ) ) );
		$out['text_version'] = 1 === count( $unique ) ? (string) $unique[0] : mb_substr( implode( '|', array_map( static fn( $c, $v ) => substr( $c, 0, 2 ) . ':' . $v, array_keys( $versions ), $versions ) ), 0, 32 );
		$out['source']       = $source;
		return $out;
	}
}
