<?php

declare(strict_types=1);

namespace App\Domain\Quotes\Support;

/**
 * Plantillas por oficio del asistente del cotizador (M18): preguntas, opciones con su emoji y precios de ejemplo en
 * la moneda del espacio de trabajo. Los precios están pensados en dólares y se pasan a pesos con una equivalencia
 * aproximada y redondeada a dos cifras: son un punto de partida que el negocio cambia por los suyos, no una tarifa.
 *
 * Cada definición pasa por `QuoteEngine::normalize` (hay una prueba para cada plantilla y moneda).
 */
final class QuoteTemplates
{
    /** Pesos por dólar, aproximados: solo para que los ejemplos tengan un orden de magnitud creíble. */
    private const FACTOR = ['COP' => 4000, 'MXN' => 18, 'USD' => 1];

    /** Redondeo del total según la moneda. */
    private const ROUND_TO = ['COP' => 1000, 'MXN' => 10, 'USD' => 5];

    public const KEYS = ['pintura', 'limpieza', 'pisos', 'jardineria', 'mudanzas', 'fumigacion', 'diseno_web', 'catering', 'reparaciones', 'clases'];

    /**
     * @return list<array{key: string, icon: string, kind: string, name: string, description: string, definition: array<string, mixed>}>
     */
    public static function all(string $currency): array
    {
        $currency = isset(self::FACTOR[$currency]) ? $currency : 'USD';
        $p = fn (float $usd): float => self::price($usd, $currency);
        $round = (float) self::ROUND_TO[$currency];
        $def = fn (array $d): array => $d + ['currency' => null, 'round_to' => $round, 'range_pct' => 10, 'base_price' => 0, 'minimum' => 0,
            'note' => null, 'internal_notes' => null, 'packages' => [], 'intro' => null];
        $opt = fn (string $value, string $label, string $icon, float $price = 0, float $multiplier = 1, ?string $description = null): array => array_filter(
            ['value' => $value, 'label' => $label, 'icon' => $icon, 'price' => $price, 'multiplier' => $multiplier, 'description' => $description],
            fn ($v) => $v !== null,
        );

        return [
            [
                'key' => 'pintura', 'icon' => '🎨', 'kind' => 'area', 'name' => __('Pintura'),
                'description' => __('Por metro cuadrado, con el tipo de pintura y los extras.'),
                'definition' => $def([
                    'intro' => __('Responde tres preguntas y mira cuánto cuesta pintar tu espacio.'),
                    'base_price' => $p(15), 'minimum' => $p(80),
                    'note' => __('Incluye mano de obra y pintura. El precio final se confirma en la visita.'),
                    'fields' => [
                        ['key' => 'area', 'label' => __('¿Cuántos metros cuadrados vas a pintar?'), 'type' => 'number', 'icon' => '📐', 'unit' => 'm²',
                            'help' => __('Suma paredes y techos. Una habitación mediana tiene unos 35 m² de paredes.'),
                            'min' => 5, 'max' => 3000, 'step' => 1, 'default' => 40, 'unit_price' => $p(3.5), 'required' => true],
                        ['key' => 'tipo', 'label' => __('¿Qué pintura prefieres?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('vinilo', __('Vinilo'), '🏠', 0, 1, __('Paredes interiores, acabado mate')),
                            $opt('lavable', __('Lavable premium'), '✨', 0, 1.25, __('Resiste manchas y limpieza')),
                            $opt('exterior', __('Exterior impermeable'), '🌦️', 0, 1.4, __('Fachadas y muros al aire libre')),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Necesitas algo más?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('resanes', __('Resanes y estuco'), '🧱', $p(30)),
                            $opt('puertas', __('Puertas y rejas'), '🚪', $p(25)),
                            $opt('limpieza', __('Limpieza final'), '🧹', $p(15)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'limpieza', 'icon' => '🧽', 'kind' => 'area', 'name' => __('Limpieza'),
                'description' => __('Hogares y oficinas por metro cuadrado, general, profunda o después de obra.'),
                'definition' => $def([
                    'intro' => __('Cuéntanos el tamaño del lugar y el tipo de limpieza.'),
                    'base_price' => $p(10), 'minimum' => $p(30),
                    'fields' => [
                        ['key' => 'area', 'label' => __('¿Cuántos metros cuadrados tiene el lugar?'), 'type' => 'number', 'icon' => '📐', 'unit' => 'm²',
                            'help' => __('Un apartamento de dos habitaciones tiene unos 60 m².'),
                            'min' => 20, 'max' => 5000, 'step' => 5, 'default' => 80, 'unit_price' => $p(0.6), 'required' => true],
                        ['key' => 'tipo', 'label' => __('¿Qué tipo de limpieza necesitas?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('general', __('General'), '🧹', 0, 1, __('Pisos, baños, cocina y polvo')),
                            $opt('profunda', __('Profunda'), '🫧', 0, 1.6, __('Rincones, muebles y electrodomésticos')),
                            $opt('post_obra', __('Después de obra'), '🏗️', 0, 2, __('Polvo de construcción y restos de pintura')),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Agregamos algo?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('ventanas', __('Ventanas por fuera'), '🪟', $p(15)),
                            $opt('nevera', __('Nevera y horno'), '🧊', $p(12)),
                            $opt('desinfeccion', __('Desinfección'), '🧴', $p(20)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'pisos', 'icon' => '🪵', 'kind' => 'area', 'name' => __('Pisos'),
                'description' => __('Instalación por metro cuadrado según el material.'),
                'definition' => $def([
                    'intro' => __('Calcula la instalación de tu piso nuevo.'),
                    'base_price' => $p(20), 'minimum' => $p(150),
                    'note' => __('El precio es de la instalación con el material elegido. Se confirma al medir.'),
                    'fields' => [
                        ['key' => 'area', 'label' => __('¿Cuántos metros cuadrados de piso?'), 'type' => 'number', 'icon' => '📐', 'unit' => 'm²',
                            'help' => __('Largo por ancho de cada espacio, sumados.'),
                            'min' => 5, 'max' => 2000, 'step' => 1, 'default' => 30, 'unit_price' => $p(6), 'required' => true],
                        ['key' => 'material', 'label' => __('¿Qué material quieres?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('laminado', __('Laminado'), '🟫', 0, 1),
                            $opt('vinilo', __('Vinilo de clic'), '🧩', 0, 1.1),
                            $opt('porcelanato', __('Porcelanato'), '⬜', 0, 1.5),
                            $opt('madera', __('Madera'), '🌳', 0, 1.8),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Necesitas algo más?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('retiro', __('Retirar el piso anterior'), '🛠️', $p(40)),
                            $opt('nivelacion', __('Nivelar el piso'), '📏', $p(50)),
                            $opt('guardaescobas', __('Guardaescobas'), '📐', $p(25)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'jardineria', 'icon' => '🌿', 'kind' => 'area', 'name' => __('Jardinería'),
                'description' => __('Mantenimiento, diseño y poda por metro cuadrado.'),
                'definition' => $def([
                    'intro' => __('Mira cuánto cuesta dejar tu jardín como lo quieres.'),
                    'base_price' => $p(10), 'minimum' => $p(25),
                    'fields' => [
                        ['key' => 'area', 'label' => __('¿Cuántos metros cuadrados tiene el jardín?'), 'type' => 'number', 'icon' => '📐', 'unit' => 'm²',
                            'min' => 10, 'max' => 20000, 'step' => 10, 'default' => 100, 'unit_price' => $p(0.4), 'required' => true],
                        ['key' => 'servicio', 'label' => __('¿Qué necesitas?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('mantenimiento', __('Mantenimiento'), '✂️', 0, 1, __('Corte de césped, bordes y limpieza')),
                            $opt('poda', __('Poda de árboles'), '🌳', 0, 1.5),
                            $opt('diseno', __('Diseño y siembra'), '🌷', 0, 3, __('Plantas nuevas y diseño del jardín')),
                        ]],
                        ['key' => 'frecuencia', 'label' => __('¿Cada cuánto?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('una_vez', __('Una sola vez'), '1️⃣', 0, 1),
                            $opt('mensual', __('Cada mes'), '🗓️', 0, 0.9, __('Con descuento por plan mensual')),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Agregamos algo?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('fumigacion', __('Fumigar las plantas'), '🐛', $p(15)),
                            $opt('residuos', __('Retirar los residuos'), '♻️', $p(20)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'mudanzas', 'icon' => '🚚', 'kind' => 'service', 'name' => __('Mudanzas'),
                'description' => __('Por tamaño de la vivienda y distancia, con embalaje y armado.'),
                'definition' => $def([
                    'intro' => __('Cotiza tu mudanza en menos de un minuto.'),
                    'base_price' => $p(20), 'range_pct' => 15,
                    'note' => __('Incluye camión, conductor y ayudantes. Peajes aparte en viajes largos.'),
                    'fields' => [
                        ['key' => 'tamano', 'label' => __('¿Qué vas a trasladar?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('estudio', __('Apartaestudio'), '🛏️', $p(60)),
                            $opt('dos', __('Dos habitaciones'), '🏠', $p(120)),
                            $opt('tres', __('Tres habitaciones o más'), '🏡', $p(200)),
                            $opt('oficina', __('Oficina'), '🏢', $p(250)),
                        ]],
                        ['key' => 'distancia', 'label' => __('¿A cuántos kilómetros vas?'), 'type' => 'number', 'icon' => '🛣️', 'unit' => 'km',
                            'help' => __('Dentro de la misma ciudad suelen ser entre 5 y 20 km.'),
                            'min' => 0, 'max' => 2000, 'step' => 1, 'default' => 10, 'unit_price' => $p(1.2), 'required' => true],
                        ['key' => 'extras', 'label' => __('¿Te ayudamos con algo más?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('embalaje', __('Embalaje'), '📦', $p(40)),
                            $opt('armado', __('Desarmar y armar muebles'), '🪛', $p(30)),
                            $opt('escaleras', __('Piso alto sin ascensor'), '🪜', $p(25)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'fumigacion', 'icon' => '🪲', 'kind' => 'area', 'name' => __('Fumigación'),
                'description' => __('Control de plagas por metro cuadrado y tipo de plaga.'),
                'definition' => $def([
                    'intro' => __('Dinos qué plaga tienes y el tamaño del lugar.'),
                    'base_price' => $p(20), 'minimum' => $p(40),
                    'fields' => [
                        ['key' => 'area', 'label' => __('¿Cuántos metros cuadrados hay que fumigar?'), 'type' => 'number', 'icon' => '📐', 'unit' => 'm²',
                            'min' => 20, 'max' => 10000, 'step' => 10, 'default' => 80, 'unit_price' => $p(0.5), 'required' => true],
                        ['key' => 'plaga', 'label' => __('¿Qué plaga tienes?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('cucarachas', __('Cucarachas u hormigas'), '🪳', 0, 1),
                            $opt('roedores', __('Roedores'), '🐀', 0, 1.3),
                            $opt('chinches', __('Chinches o pulgas'), '🛏️', 0, 1.5),
                            $opt('termitas', __('Termitas o comején'), '🪵', 0, 1.8),
                        ]],
                        ['key' => 'lugar', 'label' => __('¿Dónde es?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('casa', __('Casa o apartamento'), '🏠', 0, 1),
                            $opt('negocio', __('Negocio de comida'), '🍽️', 0, 1.4, __('Restaurantes, panaderías y cocinas')),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Necesitas certificado?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('certificado', __('Certificado de fumigación'), '📄', $p(10)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'diseno_web', 'icon' => '💻', 'kind' => 'package', 'name' => __('Diseño web'),
                'description' => __('Paquetes de página, sitio corporativo o tienda en línea, con adicionales.'),
                'definition' => $def([
                    'intro' => __('Elige el tipo de sitio y mira cuánto cuesta.'),
                    'range_pct' => 10,
                    'packages' => [
                        ['key' => 'landing', 'label' => __('Página de ventas'), 'icon' => '🚀', 'description' => __('Una página para un producto o campaña'), 'price' => $p(350)],
                        ['key' => 'corporativo', 'label' => __('Sitio corporativo'), 'icon' => '🏢', 'description' => __('Hasta 5 páginas, blog y formulario'), 'price' => $p(800)],
                        ['key' => 'tienda', 'label' => __('Tienda en línea'), 'icon' => '🛒', 'description' => __('Catálogo, carrito y pagos en línea'), 'price' => $p(1500)],
                    ],
                    'fields' => [
                        ['key' => 'extras', 'label' => __('¿Quieres agregar algo?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('contenido', __('Textos y fotos'), '✍️', $p(150)),
                            $opt('dominio', __('Dominio y hosting por un año'), '🌐', $p(80)),
                            $opt('mantenimiento', __('Mantenimiento por tres meses'), '🛠️', $p(120)),
                            $opt('urgente', __('Entrega urgente'), '⚡', 0, 1.25, __('En la mitad del tiempo')),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'catering', 'icon' => '🍽️', 'kind' => 'service', 'name' => __('Eventos y catering'),
                'description' => __('Por número de invitados y menú, con meseros y montaje.'),
                'definition' => $def([
                    'intro' => __('Cuéntanos de tu evento y te damos un precio al instante.'),
                    'minimum' => $p(300),
                    'fields' => [
                        ['key' => 'invitados', 'label' => __('¿Cuántos invitados tendrás?'), 'type' => 'number', 'icon' => '👥', 'unit' => __('personas'),
                            'min' => 10, 'max' => 2000, 'step' => 5, 'default' => 50, 'unit_price' => $p(12), 'required' => true],
                        ['key' => 'menu', 'label' => __('¿Qué menú prefieres?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('pasabocas', __('Pasabocas'), '🥪', 0, 0.8),
                            $opt('basico', __('Menú básico'), '🍛', 0, 1, __('Entrada, plato fuerte y bebida')),
                            $opt('ejecutivo', __('Menú ejecutivo'), '🥗', 0, 1.4),
                            $opt('gourmet', __('Menú gourmet'), '🍷', 0, 2),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Qué más necesitas?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('meseros', __('Meseros'), '🤵', $p(80)),
                            $opt('montaje', __('Mesas, sillas y mantelería'), '🪑', $p(60)),
                            $opt('decoracion', __('Decoración'), '🎈', $p(150)),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'reparaciones', 'icon' => '🔧', 'kind' => 'service', 'name' => __('Reparaciones'),
                'description' => __('Plomería, electricidad, cerrajería y electrodomésticos por hora.'),
                'definition' => $def([
                    'intro' => __('Dinos qué se dañó y cuándo lo necesitas.'),
                    'base_price' => $p(10), 'minimum' => $p(25), 'range_pct' => 15,
                    'note' => __('Los repuestos se cobran aparte, según lo que haga falta.'),
                    'fields' => [
                        ['key' => 'tipo', 'label' => __('¿Qué necesitas reparar?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('plomeria', __('Plomería'), '🚿', 0, 1),
                            $opt('electricidad', __('Electricidad'), '⚡', 0, 1.1),
                            $opt('cerrajeria', __('Cerrajería'), '🔑', 0, 1),
                            $opt('electrodomesticos', __('Electrodomésticos'), '🧺', 0, 1.2),
                        ]],
                        ['key' => 'horas', 'label' => __('¿Cuántas horas crees que toma?'), 'type' => 'number', 'icon' => '⏱️', 'unit' => 'h',
                            'help' => __('Si no sabes, deja 2 horas: lo confirmamos en la visita.'),
                            'min' => 1, 'max' => 40, 'step' => 1, 'default' => 2, 'unit_price' => $p(15), 'required' => true],
                        ['key' => 'urgencia', 'label' => __('¿Para cuándo?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('normal', __('En los próximos días'), '📅', 0, 1),
                            $opt('hoy', __('Hoy mismo'), '🏃', 0, 1.3),
                            $opt('noche', __('De noche o en fin de semana'), '🌙', 0, 1.5),
                        ]],
                    ],
                ]),
            ],
            [
                'key' => 'clases', 'icon' => '📚', 'kind' => 'package', 'name' => __('Clases y tutorías'),
                'description' => __('Clases sueltas o paquetes, en línea o presenciales.'),
                'definition' => $def([
                    'intro' => __('Elige cuántas clases quieres y cómo.'),
                    'range_pct' => 0,
                    'packages' => [
                        ['key' => 'una', 'label' => __('Una clase'), 'icon' => '1️⃣', 'description' => __('Una hora para resolver dudas'), 'price' => $p(15)],
                        ['key' => 'cuatro', 'label' => __('Paquete de 4 clases'), 'icon' => '📘', 'description' => __('Una clase por semana durante un mes'), 'price' => $p(55)],
                        ['key' => 'ocho', 'label' => __('Paquete de 8 clases'), 'icon' => '🎓', 'description' => __('Dos clases por semana durante un mes'), 'price' => $p(100)],
                    ],
                    'fields' => [
                        ['key' => 'modalidad', 'label' => __('¿Cómo prefieres las clases?'), 'type' => 'select', 'required' => true, 'options' => [
                            $opt('en_linea', __('En línea'), '💻', 0, 1),
                            $opt('presencial', __('Presenciales'), '🏫', 0, 1.2),
                        ]],
                        ['key' => 'extras', 'label' => __('¿Agregamos algo?'), 'type' => 'checkbox', 'required' => false, 'options' => [
                            $opt('material', __('Material de estudio'), '📝', $p(10)),
                        ]],
                    ],
                ]),
            ],
        ];
    }

    /**
     * Una plantilla por su clave, o null.
     *
     * @return array{key: string, icon: string, kind: string, name: string, description: string, definition: array<string, mixed>}|null
     */
    public static function find(string $key, string $currency): ?array
    {
        foreach (self::all($currency) as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** El redondeo del total en esa moneda. */
    public static function roundTo(string $currency): float
    {
        return (float) (self::ROUND_TO[$currency] ?? 1);
    }

    /** Dólares a la moneda, redondeado a dos cifras (14.000, 63, 3,5). */
    public static function price(float $usd, string $currency): float
    {
        $value = $usd * (self::FACTOR[$currency] ?? 1);
        if ($value <= 0) {
            return 0.0;
        }
        $step = 10 ** (floor(log10($value)) - 1);

        return round(round($value / $step) * $step, 2);
    }
}
