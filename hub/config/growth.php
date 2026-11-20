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
        // Un pedido que el servidor no tomó en este tiempo (lo toma en segundos) ya no bloquea volver a pedir.
        'request_stale_minutes' => (int) env('GROWTH_UPDATE_REQUEST_STALE_MINUTES', 15),
    ],

    // Copias de seguridad desde el panel (docs/despliegue.md §6): el pedido lo atiende growth-backup-dispatch (root).
    'backups' => [
        // Los horarios de los timers de systemd, en UTC (infra/systemd/growth-backup-*.timer): solo para mostrarlos.
        'schedule' => [
            'mariadb' => '03:15',
            'clickhouse' => '03:45',
            'verify' => 'sun 05:00',
        ],
        // Un pedido que root no tomó en este tiempo (el servidor no tiene el despachador) ya no bloquea uno nuevo.
        'stale_after_minutes' => (int) env('GROWTH_BACKUP_STALE_MINUTES', 10),
    ],
];
