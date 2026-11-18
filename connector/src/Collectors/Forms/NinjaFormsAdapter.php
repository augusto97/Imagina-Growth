<?php
/**
 * Ninja Forms: `ninja_forms_after_submission`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Ninja Forms: `ninja_forms_after_submission`.
 */
final class NinjaFormsAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return function_exists( 'Ninja_Forms' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'ninja_forms_after_submission', array( self::class, 'handle' ), 20, 1 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $data Datos del envío.
	 */
	public static function handle( mixed $data ): void {
		$data = (array) $data;
		$out  = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $field ) {
			$field = (array) $field;
			$out[] = array(
				'name'  => (string) ( $field['key'] ?? '' ),
				'label' => (string) ( $field['label'] ?? '' ),
				'type'  => (string) ( $field['type'] ?? 'text' ),
				'value' => $field['value'] ?? null,
			);
		}
		$sub_id = $data['actions']['save']['sub_id'] ?? null;
		FormCollector::record( new FormSubmission( 'ninjaforms', (string) ( $data['form_id'] ?? '' ), (string) ( $data['settings']['title'] ?? '' ), $out, null !== $sub_id ? (string) $sub_id : null ) );
	}
}
