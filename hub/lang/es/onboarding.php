<?php

/*
 * Textos del asistente de configuración que arma el servidor (docs/diseno/rediseno-v2.md §4): la línea de tiempo de
 * «Listo» según lo que quedó prendido. Las pantallas tienen sus propios textos en la SPA.
 */
return [
    // A qué se dedica el negocio (docs/diseno/rediseno-v2.md §11.1, config/igr-verticals.php).
    'verticals' => [
        'store' => ['name' => 'Tienda online', 'examples' => 'Moda, belleza, hogar, ferretería'],
        'professional' => ['name' => 'Servicios profesionales', 'examples' => 'Abogados, contadores, consultores, agencias'],
        'health' => ['name' => 'Salud y bienestar', 'examples' => 'Clínicas, odontología, estética, spa, gimnasios'],
        'education' => ['name' => 'Educación', 'examples' => 'Academias, cursos, colegios, idiomas'],
        'real_estate' => ['name' => 'Inmobiliaria', 'examples' => 'Venta y arriendo de propiedades'],
        'hospitality' => ['name' => 'Turismo, hoteles y restaurantes', 'examples' => 'Hoteles, tours, restaurantes'],
        'automotive' => ['name' => 'Automotriz', 'examples' => 'Talleres, concesionarios'],
        'b2b' => ['name' => 'Empresas B2B o industria', 'examples' => 'Distribuidores, manufactura, software'],
        'other' => ['name' => 'Otro', 'examples' => 'Cualquier otro negocio que recibe contactos'],
    ],

    'timeline' => [
        'today' => 'Hoy: tu web ya nos cuenta qué pasa y las estrategias que prendiste empiezan a trabajar.',
        'today_off' => 'Hoy: tu web ya nos cuenta qué pasa. Prende una estrategia cuando quieras.',
        'today_no_site' => 'Hoy: tus estrategias quedan listas y empiezan a trabajar con cada contacto que llegue.',
        'today_no_site_off' => 'Hoy: cuando conectes tu web o compartas tu página de contacto, empezamos a conocer a tus clientes.',
        'week' => 'Esta semana: verás los primeros mensajes enviados y las primeras ventas que recuperamos.',
        'week_services' => 'Esta semana: verás los primeros contactos respondidos al instante y las primeras citas.',
        'month' => 'Este mes: el Inicio te muestra cuánto dinero generó la plataforma, estrategia por estrategia.',
    ],
];
