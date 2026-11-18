<?php

declare(strict_types=1);

namespace App\Domain\Templates\Support;

/**
 * Variables para el selector del editor (modulos.md M08). Cada una trae su
 * expresión Liquid lista para insertar y un ejemplo. `phase` marca las que se
 * llenan cuando llegue su módulo.
 */
final class VariableCatalog
{
    /** @return list<array{key: string, label: string, items: list<array{expr: string, label: string, example: string, phase?: int}>}> */
    public static function groups(): array
    {
        return [
            ['key' => 'contacto', 'label' => __('Contacto'), 'items' => [
                ['expr' => "{{ contacto.nombre | default: 'hola' }}", 'label' => __('Nombre (con respaldo)'), 'example' => 'Laura'],
                ['expr' => '{{ contacto.apellido }}', 'label' => __('Apellido'), 'example' => 'Gómez'],
                ['expr' => '{{ contacto.ciudad }}', 'label' => __('Ciudad'), 'example' => 'Manizales'],
                ['expr' => '{{ contacto.pedidos }}', 'label' => __('Pedidos'), 'example' => '3'],
                ['expr' => '{{ contacto.puntos }}', 'label' => __('Puntos'), 'example' => '1250', 'phase' => 3],
                ['expr' => '{{ contacto.nivel }}', 'label' => __('Nivel VIP'), 'example' => 'Oro', 'phase' => 3],
            ]],
            ['key' => 'tienda', 'label' => __('Tienda'), 'items' => [
                ['expr' => '{{ tienda.nombre }}', 'label' => __('Nombre del negocio'), 'example' => 'Pinturas La 14'],
                ['expr' => '{{ tienda.url }}', 'label' => __('Dirección de la tienda'), 'example' => 'https://tienda.co'],
                ['expr' => '{{ tienda.whatsapp_url }}', 'label' => __('Enlace de WhatsApp'), 'example' => 'https://wa.me/573001234567'],
                ['expr' => '{{ tienda.direccion }}', 'label' => __('Dirección física'), 'example' => 'Calle 23 # 21-40'],
                ['expr' => '{{ tienda.contacto }}', 'label' => __('Contacto del negocio'), 'example' => 'hola@tienda.co'],
            ]],
            ['key' => 'carrito', 'label' => __('Carrito'), 'items' => [
                ['expr' => '{{ carrito.subtotal | moneda }}', 'label' => __('Subtotal'), 'example' => '$ 320.000'],
                ['expr' => '{{ carrito.cantidad_items }}', 'label' => __('Cantidad de productos'), 'example' => '3'],
                ['expr' => '{{ carrito.url_restaurar }}', 'label' => __('Enlace para recuperar el carrito'), 'example' => 'https://tienda.co/?igr_cart=…'],
                ['expr' => "{% for item in carrito.items %}{{ item.nombre }} × {{ item.cantidad }}\n{% endfor %}", 'label' => __('Lista de productos'), 'example' => 'Pintura Azul × 2'],
            ]],
            ['key' => 'cupon', 'label' => __('Cupón'), 'items' => [
                ['expr' => '{{ cupon.codigo }}', 'label' => __('Código'), 'example' => 'VUELVE-7K2Q9XAB'],
                ['expr' => '{{ cupon.descuento }}', 'label' => __('Descuento'), 'example' => '10%'],
                ['expr' => '{{ cupon.vence | fecha }}', 'label' => __('Vence'), 'example' => '25 de noviembre de 2026'],
            ]],
            ['key' => 'pedido', 'label' => __('Pedido'), 'items' => [
                ['expr' => '{{ pedido.numero }}', 'label' => __('Número'), 'example' => '10620'],
                ['expr' => '{{ pedido.total | moneda }}', 'label' => __('Total'), 'example' => '$ 320.000'],
                ['expr' => '{{ pedido.url_pago }}', 'label' => __('Enlace de pago'), 'example' => 'https://tienda.co/pagar/10620'],
                ['expr' => '{{ pedido.metodo_pago }}', 'label' => __('Medio de pago'), 'example' => 'PSE'],
            ]],
            ['key' => 'producto', 'label' => __('Producto'), 'items' => [
                ['expr' => '{{ producto.nombre }}', 'label' => __('Nombre'), 'example' => 'Pintura Azul Cielo'],
                ['expr' => '{{ producto.precio | moneda }}', 'label' => __('Precio'), 'example' => '$ 129.000'],
                ['expr' => '{{ producto.url }}', 'label' => __('Enlace'), 'example' => 'https://tienda.co/producto/pintura-azul'],
            ]],
            ['key' => 'recomendaciones', 'label' => __('Recomendaciones'), 'items' => [
                ['expr' => "{% for p in recomendaciones.para_ti limit: 3 %}{{ p.nombre }} — {{ p.precio | moneda }}\n{% endfor %}", 'label' => __('Para ti (3)'), 'example' => 'Rodillo profesional — $ 38.000'],
                ['expr' => "{% for p in recomendaciones.mas_vendidos limit: 3 %}{{ p.nombre }}\n{% endfor %}", 'label' => __('Más vendidos (3)'), 'example' => 'Pintura Blanca 1 galón'],
            ]],
            ['key' => 'enlaces', 'label' => __('Enlaces'), 'items' => [
                ['expr' => '{{ enlaces.baja }}', 'label' => __('Darse de baja'), 'example' => 'https://go.tienda.co/u/…'],
                ['expr' => '{{ enlaces.preferencias }}', 'label' => __('Preferencias'), 'example' => 'https://go.tienda.co/preferencias/…'],
            ]],
        ];
    }
}
