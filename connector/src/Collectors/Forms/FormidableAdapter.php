<?php
/**
 * Formidable Forms: `frm_after_create_entry`. (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Formidable Forms: `frm_after_create_entry`.
 */
final class FormidableAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return class_exists( 'FrmEntry' ) && class_exists( 'FrmField' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'frm_after_create_entry', array( self::class, 'handle' ), 30, 2 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $entry_id Entrada.
	 * @param mixed $form_id  Formulario.
	 */
	public static function handle( mixed $entry_id, mixed $form_id ): void {
		$entry = \FrmEntry::getOne( (int) $entry_id, true );
		if ( ! is_object( $entry ) ) {
			return;
		}
		$metas = (array) ( $entry->metas ?? array() );
		$out   = array();
		foreach ( (array) \FrmField::get_all_for_form( (int) $form_id ) as $field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			$out[] = array(
				'name'  => (string) ( $field->field_key ?? '' ),
				'label' => (string) ( $field->name ?? '' ),
				'type'  => (string) ( $field->type ?? 'text' ),
				'value' => $metas[ $field->id ] ?? null,
			);
		}
		$form = class_exists( 'FrmForm' ) ? \FrmForm::getOne( (int) $form_id ) : null;
		FormCollector::record( new FormSubmission( 'formidable', (string) $form_id, is_object( $form ) ? (string) ( $form->name ?? '' ) : '', $out, (string) $entry_id ) );
	}
}
