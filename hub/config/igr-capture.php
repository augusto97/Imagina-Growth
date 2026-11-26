<?php

/*
 * Captación sin tienda (rediseño v2 §11.3, modulos.md M12 y M18): páginas de captura alojadas, QR, chats de
 * WhatsApp con atribución y leads de anuncios (Meta Lead Ads y formularios de Google Ads).
 */
return [

    // Imágenes de las páginas (logo e imagen principal): se vuelven a codificar con GD (sin metadatos).
    'disk' => env('IGR_CAPTURE_DISK', 'local'),
    'images' => [
        'max_kb' => 5120,
        'max_pixels' => 40_000_000,
        'logo_max_side' => 480,
        'hero_max_side' => 1600,
        'jpeg_quality' => 82,
    ],

    // Límites por IP (con hash: nunca se guarda la IP) y por página.
    'limits' => [
        'views_per_minute' => 120,
        'submits_per_minute' => 10,
        'submits_per_hour_per_page' => 600,
        'redirects_per_minute' => 120,
    ],

    // Token firmado del formulario (en lugar de la sesión y el CSRF): un solo uso, vence a las `max_hours` y un envío
    // hecho antes de `min_seconds` desde que se pintó la página se toma por un robot (se responde como si nada).
    'token' => [
        'min_seconds' => 2,
        'max_hours' => 6,
    ],

    'max_fields' => 12,

    // Slugs que no se pueden usar (rutas del hub bajo /p/).
    'reserved_slugs' => ['api', 'app', 'admin', 'www', 'reservar', 'cita', 'gracias', 'confirmar', 'login', 'p', 'w'],

    // Formularios de clientes potenciales de Google Ads: tamaño máximo del aviso.
    'google_max_body_kb' => 64,

    // Meta Lead Ads: permisos que necesita la conexión de Meta para suscribir la página y leer los clientes potenciales.
    // https://developers.facebook.com/docs/marketing-api/guides/lead-ads/retrieving [verificar con la app real]
    'meta_lead_scopes' => ['leads_retrieval', 'pages_manage_metadata', 'pages_show_list', 'pages_read_engagement', 'pages_manage_ads'],
];
