<?php

/*
 * Despliegue nativo (docs/despliegue.md §5): de dónde lee el panel las versiones publicadas y dónde
 * deja el pedido de actualización. El panel solo pide; instala growth-update (root) con la llave de
 * /etc/growth. Estos valores sirven para **mostrar** la versión nueva.
 */
return [
    // Carpeta compartida con growth-update: pedido, estado, historial y la última versión vista.
    'state_path' => env('GROWTH_STATE_PATH', '/opt/growth/shared/state'),

    'update' => [
        'repo' => env('GROWTH_UPDATE_REPO', 'augusto97/Imagina-Growth'),
        'api' => env('GROWTH_UPDATE_API', 'https://api.github.com'),
        'timeout' => (int) env('GROWTH_UPDATE_HTTP_TIMEOUT', 10),
        // Una actualización «en curso» sin señales de vida por más de esto se considera abandonada.
        'stale_after_minutes' => (int) env('GROWTH_UPDATE_STALE_MINUTES', 120),
    ],
];
