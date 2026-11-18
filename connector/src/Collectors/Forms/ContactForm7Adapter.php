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
		add_action( 'wpcf7_mail_sent', array( self::class, 'handle' ), 20, 1 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $form Formulario.
	 */
	public static function handle( mixed $form ): void {
		if ( ! $form instanceof \WPCF7_ContactForm || ! class_exists( 'WPCF7_Submission' ) ) {
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
