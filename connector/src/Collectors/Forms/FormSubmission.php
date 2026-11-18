<?php
/**
 * Envío normalizado de un formulario.
 *
 * @package ImaginaGrowth\Connector
 */

declare(strict_types=1);

namespace ImaginaGrowth\Connector\Collectors\Forms;

/**
 * Lo que cada adaptador entrega a FormCollector.
 */
final class FormSubmission {

	/**
	 * @param string                                                                      $plugin    `cf7`, `wpforms`…
	 * @param string                                                                      $form_id   ID del formulario.
	 * @param string                                                                      $form_name Nombre visible.
	 * @param list<array{name?: string, label?: string, type?: string, value?: mixed}> $fields    Campos crudos.
	 * @param string|null                                                                 $entry_id  ID de la entrada, si el plugin la guarda.
	 */
	public function __construct(
		public readonly string $plugin,
		public readonly string $form_id,
		public readonly string $form_name,
		public readonly array $fields,
		public readonly ?string $entry_id = null
	) {}
}
