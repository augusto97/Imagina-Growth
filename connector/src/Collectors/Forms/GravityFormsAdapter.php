<?php
/**
 * Gravity Forms: `gform_after_submission`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Gravity Forms: `gform_after_submission`.
 */
final class GravityFormsAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return class_exists( 'GFForms' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'gform_after_submission', array( self::class, 'handle' ), 20, 2 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $entry Entrada.
	 * @param mixed $form  Formulario.
	 */
	public static function handle( mixed $entry, mixed $form ): void {
		$entry = (array) $entry;
		$form  = (array) $form;
		$out   = array();
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			$f     = is_object( $field ) ? get_object_vars( $field ) : (array) $field;
			$id    = (string) ( $f['id'] ?? '' );
			$type  = (string) ( $f['type'] ?? 'text' );
			$label = (string) ( $f['label'] ?? '' );
			if ( 'name' === $type ) {
				$out[] = array(
					'name'  => 'first_name',
					'type'  => 'text',
					'value' => $entry[ $id . '.3' ] ?? '',
				);
				$out[] = array(
					'name'  => 'last_name',
					'type'  => 'text',
					'value' => $entry[ $id . '.6' ] ?? '',
				);
				continue;
			}
			$value = $entry[ $id ] ?? null;
			if ( null === $value || '' === $value ) {
				// Casillas y consentimiento guardan cada opción en «id.N».
				$parts = array();
				foreach ( $entry as $k => $v ) {
					if ( is_string( $k ) && str_starts_with( $k, $id . '.' ) && '' !== (string) $v ) {
						$parts[] = $v;
					}
				}
				$value = $parts;
			}
			$out[] = array(
				'name'  => (string) ( $f['adminLabel'] ?? '' ),
				'label' => $label,
				'type'  => $type,
				'value' => $value,
			);
		}
		FormCollector::record( new FormSubmission( 'gravity', (string) ( $form['id'] ?? '' ), (string) ( $form['title'] ?? '' ), $out, isset( $entry['id'] ) ? (string) $entry['id'] : null ) );
	}
}
