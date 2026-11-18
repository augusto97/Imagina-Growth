<?php
/**
 * Elementor Pro Forms: `elementor_pro/forms/new_record`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Elementor Pro Forms: `elementor_pro/forms/new_record`.
 */
final class ElementorFormsAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return defined( 'ELEMENTOR_PRO_VERSION' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'elementor_pro/forms/new_record', array( self::class, 'handle' ), 20, 2 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $record  Registro (ElementorPro\Modules\Forms\Classes\Form_Record).
	 * @param mixed $handler Manejador.
	 */
	public static function handle( mixed $record, mixed $handler = null ): void {
		if ( ! is_object( $record ) || ! method_exists( $record, 'get' ) ) {
			return;
		}
		$out = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$field = (array) $field;
			$out[] = array(
				'name'  => (string) ( $field['id'] ?? $id ),
				'label' => (string) ( $field['title'] ?? '' ),
				'type'  => (string) ( $field['type'] ?? 'text' ),
				'value' => $field['raw_value'] ?? ( $field['value'] ?? null ),
			);
		}
		$id   = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'id' ) : '';
		$name = method_exists( $record, 'get_form_settings' ) ? (string) $record->get_form_settings( 'form_name' ) : '';
		FormCollector::record( new FormSubmission( 'elementor', $id, $name, $out ) );
	}
}
