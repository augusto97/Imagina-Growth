<?php

/*
 * Audiencias hacia anuncios (modulos.md M06, Fase 3): segmento → Meta Custom
 * Audiences, Google Customer Match (Data Manager API) y TikTok, cada 12 h.
 * Las credenciales son las del destino de Medición (ad_destinations).
 */
return [
    'google' => [
        'data_manager_url' => env('IGR_GOOGLE_DATA_MANAGER_URL', 'https://datamanager.googleapis.com/v1'), // [verificar]
    ],
    'tiktok' => [
        'api_url' => env('IGR_TIKTOK_API_URL', 'https://business-api.tiktok.com/open_api/v1.3'), // [verificar]
    ],
    // Miembros por petición [verificar los límites vigentes de cada plataforma].
    'batch_size' => ['meta' => 10000, 'google' => 10000, 'tiktok' => 5000],
    // Una audiencia por debajo de este tamaño casi no sirve: las plataformas piden ~100 personas que coincidan.
    'min_recommended_size' => 100,
];
