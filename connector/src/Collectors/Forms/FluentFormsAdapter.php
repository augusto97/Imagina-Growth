<?php
/**
 * Fluent Forms: `fluentform/submission_inserted` (v5+) o `fluentform_submission_inserted`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Fluent Forms: `fluentform/submission_inserted` (v5+) o `fluentform_submission_inserted`.
 */
final class FluentFormsAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return defined( 'FLUENTFORM' ) || defined( 'FLUENTFORM_VERSION' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		// Desde la v5 se disparan los dos hooks: solo se usa uno para no duplicar.
		$new = defined( 'FLUENTFORM_VERSION' ) && version_compare( (string) FLUENTFORM_VERSION, '5.0', '>=' );
		add_action( $new ? 'fluentform/submission_inserted' : 'fluentform_submission_inserted', array( self::class, 'handle' ), 20, 3 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $insert_id Entrada.
	 * @param mixed $data      Datos (por nombre de campo).
	 * @param mixed $form      Formulario.
	 */
	public static function handle( mixed $insert_id, mixed $data, mixed $form ): void {
		$out = array();
		foreach ( (array) $data as $name => $value ) {
			if ( ! is_string( $name ) || str_starts_with( $name, '_' ) || str_starts_with( $name, '__' ) ) {
				continue;
			}
			if ( is_array( $value ) && ( isset( $value['first_name'] ) || isset( $value['last_name'] ) ) ) {
				$out[] = array(
					'name'  => 'first_name',
					'type'  => 'text',
					'value' => $value['first_name'] ?? '',
				);
				$out[] = array(
					'name'  => 'last_name',
					'type'  => 'text',
					'value' => $value['last_name'] ?? '',
				);
				continue;
			}
			$type  = 1 === preg_match( '/^(gdpr|terms)/', $name ) ? 'gdpr' : 'text';
			$out[] = array(
				'name'  => $name,
				'type'  => $type,
				'value' => $value,
			);
		}
		$form = is_object( $form ) ? get_object_vars( $form ) : (array) $form;
		FormCollector::record( new FormSubmission( 'fluentforms', (string) ( $form['id'] ?? '' ), (string) ( $form['title'] ?? '' ), $out, (string) $insert_id ) );
	}
}
