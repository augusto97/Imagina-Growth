<?php
/**
 * Formularios → `form.submitted` con los plugins reales de wordpress.org.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Tests\Wp;

use ImaginaGrowth\Connector\Collectors\Forms\FieldMapper;
use ImaginaGrowth\Connector\Collectors\Forms\FormCollector;
use ImaginaGrowth\Connector\Collectors\Forms\FormSubmission;
use ImaginaGrowth\Connector\Settings;
use ImaginaGrowth\Connector\Tests\Support\WpTestCase;

/**
 * Mapeo automático, consentimientos, campos secretos y contrato.
 */
final class FormsTest extends WpTestCase {

	public function set_up(): void {
		parent::set_up();
		delete_option( Settings::OPTION );
		$this->connect();
		FormCollector::boot();
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) Chrome/130.0';
		$_SERVER['HTTP_REFERER']    = home_url( '/contacto/?utm_source=ig' );
	}

	public function tear_down(): void {
		$_POST = array();
		unset( $_SERVER['HTTP_REFERER'] );
		if ( class_exists( 'WPCF7_Submission' ) ) {
			$prop = new \ReflectionProperty( 'WPCF7_Submission', 'instance' );
			$prop->setAccessible( true );
			$prop->setValue( null, null );
		}
		parent::tear_down();
	}

	public function test_mapeo_automatico_en_espanol(): void {
		$mapped = FieldMapper::map(
			array(
				array(
					'name'  => 'nombre-completo',
					'label' => 'Nombre completo',
					'value' => 'Ana María Ruiz',
				),
				array(
					'label' => 'Correo electrónico',
					'type'  => 'text',
					'value' => 'ana@example.com',
				),
				array(
					'label' => 'Celular / WhatsApp',
					'value' => '+57 300 123 4567',
				),
				array(
					'label' => 'Nombre de la empresa',
					'value' => 'Pinturas La 14',
				),
				array(
					'label' => 'Ciudad',
					'value' => 'Manizales',
				),
				array(
					'label' => '¿En qué servicio estás interesado?',
					'value' => array( 'Pintura', 'Impermeabilización' ),
				),
				array(
					'label' => 'Contraseña',
					'type'  => 'password',
					'value' => 'secreto123',
				),
				array(
					'label' => 'Número de cédula',
					'value' => '1053123456',
				),
				array(
					'name'  => 'adjunto',
					'type'  => 'file',
					'value' => '/tmp/x.pdf',
				),
				array(
					'label' => 'Acepto recibir ofertas por WhatsApp',
					'type'  => 'checkbox',
					'value' => array( 'Sí' ),
				),
				array(
					'label' => 'Acepto la política de tratamiento de datos',
					'type'  => 'acceptance',
					'value' => '1',
				),
				array(
					'label' => 'Quiero el boletín por correo',
					'type'  => 'checkbox',
					'value' => '',
				),
				array(
					'label' => 'Cuéntanos más',
					'type'  => 'textarea',
					'value' => "Hola,\nnecesito una cotización.",
				),
			)
		);
		$this->assertSame(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'María Ruiz',
				'email'      => 'ana@example.com',
				'phone'      => '+57 300 123 4567',
				'company'    => 'Pinturas La 14',
				'city'       => 'Manizales',
				'service'    => 'Pintura, Impermeabilización',
				'message'    => "Hola,\nnecesito una cotización.",
			),
			$mapped['fields']
		);
		$this->assertSame(
			array(
				'whatsapp_marketing' => true,
				'privacy_policy'     => true,
				'email_marketing'    => false,
			),
			$mapped['consents']
		);
		$json = (string) wp_json_encode( $mapped );
		foreach ( array( 'secreto123', '1053123456', 'x.pdf' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $json, 'nunca contraseñas, documentos ni archivos' );
		}
	}

	public function test_envio_generico_cumple_el_contrato(): void {
		$ok = FormCollector::record(
			new FormSubmission(
				'fluentforms',
				'3',
				'Cotización',
				array(
					array(
						'name'  => 'email',
						'type'  => 'email',
						'value' => 'Luis@Example.com',
					),
				),
				'91'
			)
		);
		$this->assertTrue( $ok );
		$raw = $this->outbox_raw( 'form.submitted' );
		$this->assertCount( 1, $raw );
		$this->assertContract( $raw );
		$env = json_decode( $raw[0], true );
		$this->assertSame( 'luis@example.com', $env['identity']['email'] );
		$this->assertSame( '/contacto/', $env['properties']['page_path'], 'del Referer del envío AJAX' );
		$this->assertStringContainsString( '"consents":{}', $raw[0] );
		$this->assertArrayHasKey( 'consent', $env['context'] );

		// Mismo envío (misma entrada): mismo event_id, el outbox no duplica.
		FormCollector::record( new FormSubmission( 'fluentforms', '3', 'Cotización', array(), '91' ) );
		$this->assertCount( 1, $this->outbox_raw( 'form.submitted' ) );
	}

	public function test_contact_form_7(): void {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			$this->markTestSkipped( 'Contact Form 7 no está instalado.' );
		}
		$form = \WPCF7_ContactForm::get_template( array( 'title' => 'Contacto' ) );
		$form->set_properties(
			array(
				'form' => '<label>Tu nombre [text* your-name]</label> <label>Correo [email* your-email]</label> <label>Celular [tel your-phone]</label> [textarea your-message] [acceptance acepto-whatsapp optional] Quiero recibir ofertas por WhatsApp [/acceptance] [submit "Enviar"]',
			)
		);
		$id = $form->save();
		$this->assertGreaterThan( 0, $id );
		$form  = \WPCF7_ContactForm::get_instance( $id );
		$_POST = array(
			'_wpcf7'          => (string) $id,
			'_wpcf7_version'  => WPCF7_VERSION,
			'_wpcf7_locale'   => 'es_CO',
			'_wpcf7_unit_tag' => 'wpcf7-f' . $id . '-o1',
			'your-name'       => 'Ana Ruiz',
			'your-email'      => 'ANA@Example.com',
			'your-phone'      => '300 123 4567',
			'your-message'    => 'Quiero una cotización',
			'acepto-whatsapp' => '1',
		);
		add_filter( 'wpcf7_skip_spam_check', '__return_true' );
		$result = $form->submit( array( 'skip_mail' => true ) );
		$this->assertSame( 'mail_sent', $result['status'] );

		$raw = $this->outbox_raw( 'form.submitted' );
		$this->assertCount( 1, $raw );
		$this->assertContract( $raw );
		$env = json_decode( $raw[0], true );
		$this->assertSame( 'cf7', $env['properties']['form_plugin'] );
		$this->assertSame( (string) $id, $env['properties']['form_id'] );
		$this->assertSame( 'Contacto', $env['properties']['form_name'] );
		$this->assertSame(
			array(
				'first_name' => 'Ana',
				'last_name'  => 'Ruiz',
				'email'      => 'ANA@Example.com',
				'phone'      => '300 123 4567',
				'message'    => 'Quiero una cotización',
			),
			$env['properties']['fields']
		);
		$this->assertSame( array( 'whatsapp_marketing' => true ), $env['properties']['consents'] );
		$this->assertSame( 'ana@example.com', $env['identity']['email'] );
		$this->assertSame( '300 123 4567', $env['identity']['phone'] );
		$this->assertSame( 'server', $env['source']['type'] ?? 'server' );
	}

	public function test_contact_form_7_con_errores_no_registra(): void {
		if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
			$this->markTestSkipped( 'Contact Form 7 no está instalado.' );
		}
		$form = \WPCF7_ContactForm::get_template( array( 'title' => 'Contacto' ) );
		$form->set_properties( array( 'form' => '[email* your-email] [submit]' ) );
		$id    = $form->save();
		$_POST = array(
			'_wpcf7'          => (string) $id,
			'_wpcf7_unit_tag' => 'wpcf7-f' . $id . '-o1',
			'your-email'      => 'no-es-correo',
		);
		add_filter( 'wpcf7_skip_spam_check', '__return_true' );
		$result = \WPCF7_ContactForm::get_instance( $id )->submit( array( 'skip_mail' => true ) );
		$this->assertSame( 'validation_failed', $result['status'] );
		$this->assertSame( array(), $this->outbox_raw( 'form.submitted' ) );
	}

	public function test_wpforms_lite(): void {
		if ( ! function_exists( 'wpforms' ) ) {
			$this->markTestSkipped( 'WPForms Lite no está instalado.' );
		}
		$form_id = wp_insert_post(
			array(
				'post_type'   => 'wpforms',
				'post_status' => 'publish',
				'post_title'  => 'Cotización',
			)
		);
		$data    = array(
			'id'       => (string) $form_id,
			'field_id' => 6,
			'fields'   => array(
				1 => array(
					'id'     => '1',
					'type'   => 'name',
					'label'  => 'Nombre',
					'format' => 'first-last',
				),
				2 => array(
					'id'    => '2',
					'type'  => 'email',
					'label' => 'Correo electrónico',
				),
				3 => array(
					'id'    => '3',
					'type'  => 'text',
					'label' => 'Celular / WhatsApp',
				),
				4 => array(
					'id'    => '4',
					'type'  => 'textarea',
					'label' => 'Mensaje',
				),
				5 => array(
					'id'      => '5',
					'type'    => 'checkbox',
					'label'   => 'Novedades',
					'choices' => array(
						1 => array(
							'label' => 'Acepto recibir ofertas por WhatsApp',
							'value' => '',
						),
					),
				),
			),
			'settings' => array(
				'form_title'          => 'Cotización',
				'antispam'            => '0',
				'antispam_v3'         => '0',
				'ajax_submit'         => '0',
				'notification_enable' => '0',
			),
		);
		wp_update_post(
			array(
				'ID'           => $form_id,
				'post_content' => wpforms_encode( $data ),
			)
		);
		wpforms()->obj( 'process' )->process(
			array(
				'id'     => (string) $form_id,
				'fields' => array(
					1 => array(
						'first' => 'Ana',
						'last'  => 'Ruiz',
					),
					2 => 'ana@example.com',
					3 => '300 123 4567',
					4 => 'Necesito pintar mi casa',
					5 => array( 'Acepto recibir ofertas por WhatsApp' ),
				),
			)
		);
		$this->assertSame( array(), (array) ( wpforms()->obj( 'process' )->errors[ $form_id ] ?? array() ), 'WPForms aceptó el envío' );

		$raw = $this->outbox_raw( 'form.submitted' );
		$this->assertCount( 1, $raw );
		$this->assertContract( $raw );
		$env = json_decode( $raw[0], true );
		$this->assertSame( 'wpforms', $env['properties']['form_plugin'] );
		$this->assertSame( (string) $form_id, $env['properties']['form_id'] );
		$this->assertSame( 'Cotización', $env['properties']['form_name'] );
		$this->assertSame( 'Ana', $env['properties']['fields']['first_name'] );
		$this->assertSame( 'Ruiz', $env['properties']['fields']['last_name'] );
		$this->assertSame( 'ana@example.com', $env['properties']['fields']['email'] );
		$this->assertSame( '300 123 4567', $env['properties']['fields']['phone'] );
		$this->assertSame( 'Necesito pintar mi casa', $env['properties']['fields']['message'] );
		$this->assertTrue( $env['properties']['consents']['whatsapp_marketing'] );
	}

	public function test_formularios_apagados_en_la_pestana_datos(): void {
		remove_all_actions( 'wpcf7_submit' );
		remove_all_actions( 'wpforms_process_complete' );
		Settings::save( array( 'forms' => false ) );
		FormCollector::boot();
		$this->assertFalse( has_action( 'wpcf7_submit' ) );
		$this->assertFalse( has_action( 'wpforms_process_complete' ) );
		Settings::save( array( 'forms' => true ) );
		FormCollector::boot();
		$this->assertSame( class_exists( 'WPCF7_ContactForm' ), (bool) has_action( 'wpcf7_submit' ) );
		$this->assertSame( array_values( array_filter( array( class_exists( 'WPCF7_ContactForm' ) ? 'cf7' : null, function_exists( 'wpforms' ) ? 'wpforms' : null ) ) ), FormCollector::detected() );
	}

	public function test_administradores_no_generan_leads(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse(
			FormCollector::record(
				new FormSubmission(
					'cf7',
					'1',
					'Contacto',
					array(
						array(
							'type'  => 'email',
							'value' => 'admin@example.com',
						),
					)
				)
			)
		);
		wp_set_current_user( 0 );
		$this->assertSame( array(), $this->outbox_raw( 'form.submitted' ) );
	}
}
