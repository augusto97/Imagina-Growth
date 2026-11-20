<?php

/*
 * Vigilancia de producción (docs/despliegue.md §6): `growth:monitor --ping` cada minuto. Las URLs de los
 * heartbeats de Better Stack van en el .env del servidor, nunca en el repositorio. Sin URL, ese aviso no se
 * envía.
 */
return [
    'heartbeats' => [
        // MariaDB, ClickHouse y Redis responden.
        'health' => env('BETTERSTACK_HEARTBEAT_HEALTH'),
        // Los streams de ingesta no se atrasan (eventos sin procesar y antigüedad del más viejo).
        'streams' => env('BETTERSTACK_HEARTBEAT_STREAMS'),
        // Horizon corre y las colas no se acumulan.
        'queues' => env('BETTERSTACK_HEARTBEAT_QUEUES'),
    ],

    // Eventos sin procesar (pendientes + sin entregar) en todos los shards.
    'max_stream_lag' => (int) env('GROWTH_MONITOR_MAX_STREAM_LAG', 5000),
    // Antigüedad máxima, en segundos, del evento más viejo sin procesar.
    'max_stream_age_seconds' => (int) env('GROWTH_MONITOR_MAX_STREAM_AGE', 120),
    // Trabajos pendientes sumando todas las colas de Horizon.
    'max_queue_backlog' => (int) env('GROWTH_MONITOR_MAX_QUEUE_BACKLOG', 10000),
    // Porcentaje de `maxmemory` del Redis de colas: lleno, deja de aceptar eventos y trabajos (aviso de «streams»).
    'max_redis_memory_pct' => (int) env('GROWTH_MONITOR_MAX_REDIS_MEMORY_PCT', 85),
];
