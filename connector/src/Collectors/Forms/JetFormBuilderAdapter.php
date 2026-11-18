<?php
/**
 * JetFormBuilder: `jet-form-builder/form-handler/after-send` (verificado en la 3.6). (connector/CLAUDE.md §6).
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * JetFormBuilder: `jet-form-builder/form-handler/after-send` (verificado en la 3.6).
 */
final class JetFormBuilderAdapter implements FormAdapter {

	/**
	 * ¿El plugin está activo?
	 */
	public static function available(): bool {
		return function_exists( 'jet_fb_request_handler' );
	}

	/**
	 * Engancha el hook del plugin.
	 */
	public static function register(): void {
		add_action( 'jet-form-builder/form-handler/after-send', array( self::class, 'handle' ), 20, 2 );
	}

	/**
	 * Convierte el envío del plugin en un FormSubmission.
	 *
	 * @param mixed $handler    Form_Handler.
	 * @param mixed $is_success Éxito.
	 */
	public static function handle( mixed $handler, mixed $is_success = true ): void {
		if ( ! $is_success || ! is_object( $handler ) || ! function_exists( 'jet_fb_request_handler' ) ) {
			return;
		}
		$request = jet_fb_request_handler();
		$data    = method_exists( $request, 'get_request' ) ? (array) $request->get_request() : array();
		$types   = method_exists( $request, 'get_types' ) ? (array) $request->get_types() : array();
		$out     = array();
		foreach ( $data as $name => $value ) {
			if ( ! is_string( $name ) || str_starts_with( $name, '_' ) ) {
				continue;
			}
			$out[] = array(
				'name'  => $name,
				'type'  => (string) ( $types[ $name ] ?? 'text' ),
				'value' => $value,
			);
		}
		$form_id = method_exists( $handler, 'get_form_id' ) ? (int) $handler->get_form_id() : 0;
		FormCollector::record( new FormSubmission( 'jetformbuilder', (string) $form_id, $form_id > 0 ? (string) get_the_title( $form_id ) : '', $out ) );
	}
}
