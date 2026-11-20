<?php

declare(strict_types=1);

namespace App\Domain\Templates\Rendering;

use App\Domain\Workspaces\Models\Workspace;

/**
 * Datos de ejemplo para la vista previa sin contacto real (y para documentar
 * qué espera cada variable). Los flujos y campañas deben mandar `vars` con estas
 * mismas formas (RenderContext).
 *
 * Los ejemplos siguen el país del espacio de trabajo (ciudad, dirección, medio de
 * pago, WhatsApp y dominio) y los montos, su moneda; un país sin ejemplos propios
 * usa los de Colombia.
 */
final class SampleData
{
    /** @var array<string, array{negocio: string, ciudad: string, direccion: string, contacto: string, metodo_pago: string, whatsapp: string, url: string}> */
    public const COUNTRIES = [
        'CO' => ['negocio' => 'Pinturas La 14', 'ciudad' => 'Manizales', 'direccion' => 'Calle 23 # 21-40, Manizales', 'contacto' => 'hola@tienda.co', 'metodo_pago' => 'PSE', 'whatsapp' => '573001234567', 'url' => 'https://tienda.co'],
        'MX' => ['negocio' => 'Ferretería La Esperanza', 'ciudad' => 'Puebla', 'direccion' => 'Av. Juárez 120, Col. Centro, Puebla', 'contacto' => 'hola@tienda.mx', 'metodo_pago' => 'OXXO', 'whatsapp' => '525512345678', 'url' => 'https://tienda.mx'],
        'VE' => ['negocio' => 'Pinturas El Ávila', 'ciudad' => 'Valencia', 'direccion' => 'Av. Bolívar Norte, local 12, Valencia', 'contacto' => 'hola@tienda.com.ve', 'metodo_pago' => 'Pago Móvil', 'whatsapp' => '584121234567', 'url' => 'https://tienda.com.ve'],
    ];

    /** @var array<string, array{pintura: int, oferta: int, rodillo: int}> precios de ejemplo en cada moneda */
    public const PRICES = [
        'COP' => ['pintura' => 141000, 'oferta' => 129000, 'rodillo' => 38000],
        'MXN' => ['pintura' => 890, 'oferta' => 790, 'rodillo' => 240],
        'USD' => ['pintura' => 38, 'oferta' => 34, 'rodillo' => 10],
        'EUR' => ['pintura' => 35, 'oferta' => 31, 'rodillo' => 9],
    ];

    /** La moneda de los ejemplos cuando la del espacio de trabajo no tiene precios propios. */
    private const COUNTRY_CURRENCY = ['CO' => 'COP', 'MX' => 'MXN', 'VE' => 'USD'];

    /**
     * Los ejemplos del país y la moneda del espacio de trabajo.
     *
     * @return array<string, mixed>
     */
    public static function forWorkspace(Workspace $workspace, string $storeUrl = ''): array
    {
        return self::vars($storeUrl, (string) ($workspace->country ?: 'CO'), (string) ($workspace->currency ?: 'COP'));
    }

    /** @return array{negocio: string, ciudad: string, direccion: string, contacto: string, metodo_pago: string, whatsapp: string, url: string} */
    public static function country(string $country): array
    {
        return self::COUNTRIES[strtoupper($country)] ?? self::COUNTRIES['CO'];
    }

    /** @return array{pintura: int, oferta: int, rodillo: int} */
    public static function prices(string $country, string $currency): array
    {
        $currency = strtoupper($currency);
        if (! array_key_exists($currency, self::PRICES)) {
            $currency = self::COUNTRY_CURRENCY[strtoupper($country)] ?? 'COP';
        }

        return self::PRICES[$currency];
    }

    /** @return array<string, mixed> */
    public static function vars(string $storeUrl = '', string $country = 'CO', string $currency = 'COP'): array
    {
        $place = self::country($country);
        $price = self::prices($country, $currency);
        $base = rtrim($storeUrl !== '' ? $storeUrl : $place['url'], '/');
        $subtotal = (string) (2 * $price['pintura'] + $price['rodillo']);

        return [
            'contacto' => ['nombre' => 'Laura', 'apellido' => 'Gómez', 'nombre_completo' => 'Laura Gómez', 'ciudad' => $place['ciudad'], 'pais' => array_key_exists(strtoupper($country), self::COUNTRIES) ? strtoupper($country) : 'CO', 'puntos' => 1250, 'nivel' => 'Oro', 'pedidos' => 3],
            'carrito' => [
                'items' => [
                    ['nombre' => 'Pintura Azul Cielo 1 galón', 'cantidad' => 2, 'precio' => (string) $price['pintura'], 'imagen' => '', 'url' => $base.'/producto/pintura-azul'],
                    ['nombre' => 'Rodillo profesional 9"', 'cantidad' => 1, 'precio' => (string) $price['rodillo'], 'imagen' => '', 'url' => $base.'/producto/rodillo'],
                ],
                'cantidad_items' => 3,
                'subtotal' => $subtotal,
                'url_restaurar' => $base.'/?igr_cart=ejemplo',
            ],
            'cupon' => ['codigo' => 'VUELVE-7K2Q9XAB', 'descuento' => '10%', 'vence' => now()->addDays(7)->toDateString()],
            'pedido' => ['numero' => '10620', 'total' => $subtotal, 'estado' => 'Pendiente de pago', 'metodo_pago' => $place['metodo_pago'], 'url_pago' => $base.'/pagar/10620', 'url' => $base.'/mi-cuenta/pedidos/10620'],
            'producto' => ['nombre' => 'Pintura Azul Cielo 1 galón', 'precio' => (string) $price['oferta'], 'precio_anterior' => (string) $price['pintura'], 'imagen' => '', 'url' => $base.'/producto/pintura-azul'],
            'cita' => ['fecha' => now()->addDay()->toDateString(), 'hora' => '10:30 a. m.', 'lugar' => $place['direccion'], 'url_reprogramar' => $base.'/citas'],
            'aviso' => ['titulo' => 'Nuevo lead de servicios', 'resumen' => 'Laura pidió una cotización desde el formulario de contacto.', 'detalle' => 'Servicio: pintura de fachada. Ciudad: '.$place['ciudad'].'.', 'url' => $base],
            'enlaces' => ['baja' => '#baja', 'preferencias' => '#preferencias', 'resena' => '#resena?nota=', 'nps' => '#nps?nota=', 'referido' => $base.'/?ref=LAURA10'],
        ];
    }
}
