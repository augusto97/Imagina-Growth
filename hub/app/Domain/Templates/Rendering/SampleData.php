<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

/**
 * Datos de ejemplo para la vista previa sin contacto real (y para documentar
 * qué espera cada variable). Los flujos y campañas deben mandar `vars` con estas
 * mismas formas (RenderContext).
 */
final class SampleData
{
    /** @return array<string, mixed> */
    public static function vars(string $storeUrl = 'https://tienda.co'): array
    {
        $base = rtrim($storeUrl !== '' ? $storeUrl : 'https://tienda.co', '/');

        return [
            'contacto' => ['nombre' => 'Laura', 'apellido' => 'Gómez', 'nombre_completo' => 'Laura Gómez', 'ciudad' => 'Manizales', 'pais' => 'CO', 'puntos' => 1250, 'nivel' => 'Oro', 'pedidos' => 3],
            'carrito' => [
                'items' => [
                    ['nombre' => 'Pintura Azul Cielo 1 galón', 'cantidad' => 2, 'precio' => '141000', 'imagen' => '', 'url' => $base.'/producto/pintura-azul'],
                    ['nombre' => 'Rodillo profesional 9"', 'cantidad' => 1, 'precio' => '38000', 'imagen' => '', 'url' => $base.'/producto/rodillo'],
                ],
                'cantidad_items' => 3,
                'subtotal' => '320000',
                'url_restaurar' => $base.'/?igr_cart=ejemplo',
            ],
            'cupon' => ['codigo' => 'VUELVE-7K2Q9XAB', 'descuento' => '10%', 'vence' => now()->addDays(7)->toDateString()],
            'pedido' => ['numero' => '10620', 'total' => '320000', 'estado' => 'Pendiente de pago', 'metodo_pago' => 'PSE', 'url_pago' => $base.'/pagar/10620', 'url' => $base.'/mi-cuenta/pedidos/10620'],
            'producto' => ['nombre' => 'Pintura Azul Cielo 1 galón', 'precio' => '129000', 'precio_anterior' => '141000', 'imagen' => '', 'url' => $base.'/producto/pintura-azul'],
            'cita' => ['fecha' => now()->addDay()->toDateString(), 'hora' => '10:30 a. m.', 'lugar' => 'Calle 23 # 21-40, Manizales', 'url_reprogramar' => $base.'/citas'],
            'aviso' => ['titulo' => 'Nuevo lead de servicios', 'resumen' => 'Laura pidió una cotización desde el formulario de contacto.', 'detalle' => 'Servicio: pintura de fachada. Ciudad: Manizales.', 'url' => $base],
            'enlaces' => ['baja' => '#baja', 'preferencias' => '#preferencias', 'resena' => '#resena?nota=', 'nps' => '#nps?nota=', 'referido' => $base.'/?ref=LAURA10'],
        ];
    }
}
