<?php
/**
 * Contact Form 7: `wpcf7_mail_sent`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Contact Form 7: `wpcf7_mail_sent`.
 */
final class ContactForm7Adapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'wpcf7_submit', array( self::class, 'handle' ), 20, 2 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * Se registra aunque falle el correo de aviso (`mail_failed`): el lead ya existe.
	 *
	 * @param mixed $form   Formulario.
	 * @param mixed $result Resultado (`status`: mail_sent, mail_failed, spam, validation_failed…).
	 */
	public static function handle( mixed $form, mixed $result = null ): void {
		$status = is_array( $result ) ? (string) ( $result['status'] ?? '' ) : 'mail_sent';
		if ( ! $form instanceof \WPCF7_ContactForm || ! class_exists( 'WPCF7_Submission' ) || ! in_array( $status, array( 'mail_sent', 'mail_failed' ), true ) ) {
			return;
		}
		$submission = \WPCF7_Submission::get_instance();
		$posted     = $submission ? (array) $submission->get_posted_data() : array();
		$fields     = array();
		foreach ( $form->scan_form_tags() as $tag ) {
			if ( '' === (string) $tag->name ) {
				continue;
			}
			$fields[] = array(
				'name'  => (string) $tag->name,
				'label' => '',
				'type'  => (string) $tag->basetype,
				'value' => $posted[ $tag->name ] ?? null,
			);
		}
		FormCollector::record( new FormSubmission( 'cf7', (string) $form->id(), (string) $form->title(), $fields ) );
	}
}
