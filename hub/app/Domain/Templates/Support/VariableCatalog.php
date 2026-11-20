<?php

declare(strict_types=1);

namespace App\Domain\Templates\Support;

use App\Domain\Templates\Liquid\TemplateFilters;
use App\Domain\Templates\Rendering\SampleData;
use App\Domain\Workspaces\Models\Workspace;

/**
 * Variables para el selector del editor (modulos.md M08). Cada una trae su
 * expresión Liquid lista para insertar y un ejemplo. `phase` marca las que se
 * llenan cuando llegue su módulo. Los ejemplos salen de `SampleData` con el país,
 * la moneda y el idioma del espacio de trabajo (sin él, los de Colombia).
 */
final class VariableCatalog
{
    /** @return list<array{key: string, label: string, items: list<array{expr: string, label: string, example: string, phase?: int}>}> */
    public static function groups(?Workspace $workspace = null): array
    {
        $country = (string) ($workspace?->country ?: 'CO');
        $currency = (string) ($workspace?->currency ?: 'COP');
        $locale = str_replace('-', '_', (string) ($workspace?->locale ?: 'es_CO'));
        $place = SampleData::country($country);
        $price = SampleData::prices($country, $currency);
        $money = fn (int $amount): string => TemplateFilters::money($amount, $currency, $locale);
        $url = $place['url'];
        $host = (string) parse_url($url, PHP_URL_HOST);

        return [
            ['key' => 'contacto', 'label' => __('Contacto'), 'items' => [
                ['expr' => "{{ contacto.nombre | default: 'hola' }}", 'label' => __('Nombre (con respaldo)'), 'example' => 'Laura'],
                ['expr' => '{{ contacto.apellido }}', 'label' => __('Apellido'), 'example' => 'Gómez'],
                ['expr' => '{{ contacto.ciudad }}', 'label' => __('Ciudad'), 'example' => $place['ciudad']],
                ['expr' => '{{ contacto.pedidos }}', 'label' => __('Pedidos'), 'example' => '3'],
                ['expr' => '{{ contacto.puntos }}', 'label' => __('Puntos'), 'example' => '1250', 'phase' => 3],
                ['expr' => '{{ contacto.nivel }}', 'label' => __('Nivel VIP'), 'example' => 'Oro', 'phase' => 3],
            ]],
            ['key' => 'tienda', 'label' => __('Tienda'), 'items' => [
                ['expr' => '{{ tienda.nombre }}', 'label' => __('Nombre del negocio'), 'example' => $place['negocio']],
                ['expr' => '{{ tienda.url }}', 'label' => __('Dirección de la tienda'), 'example' => $url],
                ['expr' => '{{ tienda.whatsapp_url }}', 'label' => __('Enlace de WhatsApp'), 'example' => 'https://wa.me/'.$place['whatsapp']],
                ['expr' => '{{ tienda.direccion }}', 'label' => __('Dirección física'), 'example' => $place['direccion']],
                ['expr' => '{{ tienda.contacto }}', 'label' => __('Contacto del negocio'), 'example' => $place['contacto']],
            ]],
            ['key' => 'carrito', 'label' => __('Carrito'), 'items' => [
                ['expr' => '{{ carrito.subtotal | moneda }}', 'label' => __('Subtotal'), 'example' => $money(2 * $price['pintura'] + $price['rodillo'])],
                ['expr' => '{{ carrito.cantidad_items }}', 'label' => __('Cantidad de productos'), 'example' => '3'],
                ['expr' => '{{ carrito.url_restaurar }}', 'label' => __('Enlace para recuperar el carrito'), 'example' => $url.'/?igr_cart=…'],
                ['expr' => "{% for item in carrito.items %}{{ item.nombre }} × {{ item.cantidad }}\n{% endfor %}", 'label' => __('Lista de productos'), 'example' => 'Pintura Azul × 2'],
            ]],
            ['key' => 'cupon', 'label' => __('Cupón'), 'items' => [
                ['expr' => '{{ cupon.codigo }}', 'label' => __('Código'), 'example' => 'VUELVE-7K2Q9XAB'],
                ['expr' => '{{ cupon.descuento }}', 'label' => __('Descuento'), 'example' => '10%'],
                ['expr' => '{{ cupon.vence | fecha }}', 'label' => __('Vence'), 'example' => '25 de noviembre de 2026'],
            ]],
            ['key' => 'pedido', 'label' => __('Pedido'), 'items' => [
                ['expr' => '{{ pedido.numero }}', 'label' => __('Número'), 'example' => '10620'],
                ['expr' => '{{ pedido.total | moneda }}', 'label' => __('Total'), 'example' => $money(2 * $price['pintura'] + $price['rodillo'])],
                ['expr' => '{{ pedido.url_pago }}', 'label' => __('Enlace de pago'), 'example' => $url.'/pagar/10620'],
                ['expr' => '{{ pedido.metodo_pago }}', 'label' => __('Medio de pago'), 'example' => $place['metodo_pago']],
            ]],
            ['key' => 'producto', 'label' => __('Producto'), 'items' => [
                ['expr' => '{{ producto.nombre }}', 'label' => __('Nombre'), 'example' => 'Pintura Azul Cielo'],
                ['expr' => '{{ producto.precio | moneda }}', 'label' => __('Precio'), 'example' => $money($price['oferta'])],
                ['expr' => '{{ producto.url }}', 'label' => __('Enlace'), 'example' => $url.'/producto/pintura-azul'],
            ]],
            ['key' => 'recomendaciones', 'label' => __('Recomendaciones'), 'items' => [
                ['expr' => "{% for p in recomendaciones.para_ti limit: 3 %}{{ p.nombre }} — {{ p.precio | moneda }}\n{% endfor %}", 'label' => __('Para ti (3)'), 'example' => 'Rodillo profesional — '.$money($price['rodillo'])],
                ['expr' => "{% for p in recomendaciones.mas_vendidos limit: 3 %}{{ p.nombre }}\n{% endfor %}", 'label' => __('Más vendidos (3)'), 'example' => 'Pintura Blanca 1 galón'],
            ]],
            ['key' => 'enlaces', 'label' => __('Enlaces'), 'items' => [
                ['expr' => '{{ enlaces.baja }}', 'label' => __('Darse de baja'), 'example' => 'https://go.'.$host.'/u/…'],
                ['expr' => '{{ enlaces.preferencias }}', 'label' => __('Preferencias'), 'example' => 'https://go.'.$host.'/preferencias/…'],
            ]],
        ];
    }
}
