<?php
/**
 * WPForms: `wpforms_process_complete`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * WPForms: `wpforms_process_complete`.
 */
final class WpFormsAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return function_exists( 'wpforms' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'wpforms_process_complete', array( self::class, 'handle' ), 20, 4 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $fields    Campos procesados.
	 * @param mixed $entry     Entrada.
	 * @param mixed $form_data Formulario.
	 * @param mixed $entry_id  ID de la entrada (0 en Lite).
	 */
	public static function handle( mixed $fields, mixed $entry = null, mixed $form_data = null, mixed $entry_id = 0 ): void {
		$out = array();
		foreach ( (array) $fields as $field ) {
			$field = (array) $field;
			$type  = (string) ( $field['type'] ?? 'text' );
			if ( 'name' === $type && ( isset( $field['first'] ) || isset( $field['last'] ) ) ) {
				$out[] = array(
					'name'  => 'first_name',
					'type'  => 'text',
					'value' => $field['first'] ?? '',
				);
				$out[] = array(
					'name'  => 'last_name',
					'type'  => 'text',
					'value' => $field['last'] ?? '',
				);
				continue;
			}
			$out[] = array(
				'name'  => (string) ( $field['name'] ?? '' ),
				'label' => '',
				'type'  => $type,
				'value' => $field['value'] ?? null,
			);
		}
		$form = (array) $form_data;
		FormCollector::record( new FormSubmission( 'wpforms', (string) ( $form['id'] ?? '' ), (string) ( $form['settings']['form_title'] ?? '' ), $out, (int) $entry_id > 0 ? (string) $entry_id : null ) );
	}
}
