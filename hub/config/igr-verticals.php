<?php

/*
 * Tipos de negocio y verticales (docs/diseno/rediseno-v2.md §11.1). La clave se guarda en `workspaces.vertical`; los
 * nombres y ejemplos están en lang/es/onboarding.php → `verticals.<clave>`. `business_type` es el tipo de negocio que
 * implica la vertical (una tienda online o un negocio de servicios); «tienda + servicios» se elige aparte.
 * `industries` son las industrias de `workspaces.industry` que sugieren esa vertical en el asistente.
 */
return [
    'verticals' => [
        'store' => ['business_type' => 'store', 'industries' => ['moda', 'belleza', 'ferreteria_pinturas', 'mascotas', 'alimentos', 'tecnologia']],
        'professional' => ['business_type' => 'services', 'industries' => ['servicios_profesionales']],
        'health' => ['business_type' => 'services', 'industries' => ['salud_bienestar']],
        'education' => ['business_type' => 'services', 'industries' => ['educacion']],
        'real_estate' => ['business_type' => 'services', 'industries' => []],
        'hospitality' => ['business_type' => 'services', 'industries' => []],
        'automotive' => ['business_type' => 'services', 'industries' => []],
        'b2b' => ['business_type' => 'services', 'industries' => []],
        'other' => ['business_type' => 'services', 'industries' => []],
    ],
];
