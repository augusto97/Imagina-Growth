<?php

declare(strict_types=1);

namespace App\Support\Demo;

/**
 * Datos de referencia para la tienda de prueba: ciudades reales con su
 * departamento (ISO 3166-2) y peso aproximado por población, nombres y un
 * catálogo de ferretería y pinturas.
 */
final class ColombiaData
{
    /** @var list<array{0: string, 1: string, 2: float}> ciudad, departamento, peso */
    public const CITIES = [
        ['Bogotá', 'CO-DC', 7.9], ['Medellín', 'CO-ANT', 2.6], ['Cali', 'CO-VAC', 2.3], ['Barranquilla', 'CO-ATL', 1.3],
        ['Cartagena', 'CO-BOL', 1.0], ['Cúcuta', 'CO-NSA', 0.8], ['Soacha', 'CO-CUN', 0.7], ['Bucaramanga', 'CO-SAN', 0.6],
        ['Soledad', 'CO-ATL', 0.6], ['Ibagué', 'CO-TOL', 0.55], ['Villavicencio', 'CO-MET', 0.55], ['Bello', 'CO-ANT', 0.55],
        ['Santa Marta', 'CO-MAG', 0.5], ['Valledupar', 'CO-CES', 0.5], ['Montería', 'CO-COR', 0.5], ['Pereira', 'CO-RIS', 0.48],
        ['Manizales', 'CO-CAL', 0.45], ['Pasto', 'CO-NAR', 0.4], ['Neiva', 'CO-HUI', 0.36], ['Armenia', 'CO-QUI', 0.3],
        ['Popayán', 'CO-CAU', 0.3], ['Sincelejo', 'CO-SUC', 0.28], ['Envigado', 'CO-ANT', 0.24], ['Tunja', 'CO-BOY', 0.2],
        ['Riohacha', 'CO-LAG', 0.2], ['Dosquebradas', 'CO-RIS', 0.2], ['Chía', 'CO-CUN', 0.15], ['Rionegro', 'CO-ANT', 0.13],
    ];

    /** @var list<string> */
    public const FIRST_NAMES = [
        'Ana', 'María', 'Laura', 'Valentina', 'Camila', 'Daniela', 'Sofía', 'Paula', 'Andrea', 'Carolina', 'Juliana', 'Natalia',
        'Diana', 'Alejandra', 'Luisa', 'Marcela', 'Gloria', 'Adriana', 'Claudia', 'Lina', 'Juan', 'Carlos', 'Andrés', 'Santiago',
        'Sebastián', 'Felipe', 'Daniel', 'David', 'Jorge', 'Luis', 'Alejandro', 'Camilo', 'Mateo', 'Nicolás', 'Diego', 'Óscar',
        'Ricardo', 'Esteban', 'Julián', 'Mauricio', 'Hernán', 'Fabio', 'Jhon', 'Wilson', 'Edwin',
    ];

    /** @var list<string> */
    public const LAST_NAMES = [
        'Rodríguez', 'Gómez', 'González', 'Martínez', 'García', 'López', 'Hernández', 'Sánchez', 'Ramírez', 'Pérez', 'Díaz',
        'Muñoz', 'Rojas', 'Moreno', 'Jiménez', 'Vargas', 'Castro', 'Gutiérrez', 'Ortiz', 'Ruiz', 'Restrepo', 'Osorio', 'Cardona',
        'Valencia', 'Quintero', 'Arango', 'Zapata', 'Ospina', 'Giraldo', 'Mejía', 'Salazar', 'Henao', 'Londoño', 'Betancur',
    ];

    /**
     * Categorías con marcas y rango de precio en COP.
     *
     * @var array<string, array{brands: list<string>, items: list<string>, min: int, max: int}>
     */
    public const CATALOG = [
        'Pinturas' => ['brands' => ['Pintuco', 'Sherwin-Williams', 'Corona'], 'items' => ['Vinilo tipo 1 galón', 'Esmalte sintético galón', 'Vinilo lavable cuarto', 'Pintura para fachadas galón', 'Laca transparente', 'Pintura epóxica', 'Anticorrosivo galón', 'Vinilo tipo 2 cuñete'], 'min' => 38000, 'max' => 420000],
        'Impermeabilizantes' => ['brands' => ['Sika', 'Pintuco'], 'items' => ['Impermeabilizante de techos galón', 'Manto asfáltico rollo', 'Sellador de grietas', 'Impermeabilizante acrílico cuñete'], 'min' => 45000, 'max' => 680000],
        'Herramientas' => ['brands' => ['Truper', 'Stanley', 'Bosch', 'DeWalt'], 'items' => ['Taladro percutor', 'Juego de destornilladores', 'Martillo de uña', 'Pulidora 4 1/2', 'Flexómetro 5 m', 'Nivel de burbuja', 'Llave inglesa', 'Sierra caladora'], 'min' => 12000, 'max' => 890000],
        'Accesorios de pintura' => ['brands' => ['Goya', 'Truper'], 'items' => ['Rodillo de felpa', 'Brocha 3 pulgadas', 'Bandeja para rodillo', 'Cinta de enmascarar', 'Lija de agua (pliego)', 'Espátula'], 'min' => 3500, 'max' => 45000],
        'Ferretería' => ['brands' => ['Corona', 'Grival', 'Truper'], 'items' => ['Silicona transparente', 'Tornillos drywall (caja)', 'Chazos plásticos', 'Candado de seguridad', 'Grifería de lavamanos', 'Cerradura de pomo'], 'min' => 4500, 'max' => 320000],
        'Exteriores y jardín' => ['brands' => ['Truper', 'Gardena'], 'items' => ['Manguera 15 m', 'Pala cuadrada', 'Carretilla', 'Tijeras de podar'], 'min' => 18000, 'max' => 420000],
    ];

    /** @var array<string, array{0: string, 1: float, 2: bool}> pasarela => título, peso, es offline */
    public const GATEWAYS = [
        'wompi' => ['Wompi - PSE', 0.38, false],
        'nequi' => ['Nequi', 0.18, false],
        'cod' => ['Contraentrega', 0.24, true],
        'bacs' => ['Transferencia bancaria', 0.10, true],
        'mercadopago' => ['Mercado Pago - Tarjeta', 0.10, false],
    ];
}
