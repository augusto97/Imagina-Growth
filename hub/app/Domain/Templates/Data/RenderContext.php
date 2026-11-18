<?php

declare(strict_types=1);

namespace App\Domain\Templates\Data;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Datos de una plantilla Liquid (M08). Las variables que ve quien escribe son
 * en español; `contacto` y `tienda` las arma el renderizador, el resto llega en
 * `vars` desde el flujo o la campaña:
 *
 * - `contacto`: nombre, apellido, ciudad, puntos, nivel (nunca correo ni teléfono completos).
 * - `tienda`: nombre, url, whatsapp, moneda.
 * - `carrito`: items[{nombre, cantidad, precio, imagen, url}], subtotal, moneda, url_restaurar.
 * - `cupon`: codigo, descuento, vence.
 * - `pedido`: numero, total, estado, url_pago.
 * - `producto` / `recomendaciones`: para_ti, mas_vendidos (listas de productos).
 * - `enlaces`: baja, preferencias (los pone el despachador: cada mensaje los suyos).
 */
final class RenderContext
{
    public const VARIABLES = ['contacto', 'tienda', 'carrito', 'cupon', 'pedido', 'producto', 'recomendaciones', 'enlaces'];

    /**
     * @param  array<string, mixed>  $vars
     */
    public function __construct(
        public readonly Workspace $workspace,
        public readonly ?Contact $contact,
        public readonly array $vars = [],
    ) {}
}
