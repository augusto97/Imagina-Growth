<?php

/*
 * Configuración propia de Imagina Growth. Ver hub/CLAUDE.md y docs/api-connector.md.
 */
return [

    // URL pública del panel y de la ingesta (docs/api-connector.md §3.3).
    'urls' => [
        'app' => env('IGR_APP_URL', env('APP_URL', 'http://localhost:8000')),
        'ingest' => env('IGR_INGEST_URL', env('APP_URL', 'http://localhost:8000')),
    ],

    // Clave maestra del índice ciego (HMAC). Cada espacio de trabajo deriva la suya con HKDF.
    // Nunca se rota sin reindexar: cambiarla rompe las búsquedas por correo y teléfono.
    'blind_index_key' => env('IGR_BLIND_INDEX_KEY'),

    // 2FA obligatoria para owner, admin y agency_owner (modulos.md M01).
    'require_two_factor' => (bool) env('IGR_REQUIRE_2FA', true),

    'connect' => [
        'code_ttl_minutes' => 10,
        'allow_insecure_site_urls' => (bool) env('IGR_ALLOW_HTTP_SITES', env('APP_ENV') !== 'production'),
    ],

    'signature' => [
        'tolerance_seconds' => 300,
        'previous_secret_grace_hours' => 24,
    ],

    'ingest' => [
        'shards' => (int) env('IGR_INGEST_SHARDS', 4),
        'stream_prefix' => 'ingest:',
        'consumer_group' => 'ingest-workers',
        'stream_maxlen' => 1_000_000,
        'max_event_bytes' => 32 * 1024,
        'server_batch_max_events' => 500,
        'server_batch_max_bytes' => 1024 * 1024,          // comprimido
        'server_batch_max_inflated_bytes' => 16 * 1024 * 1024,
        'browser_batch_max_events' => 20,
        'browser_batch_max_bytes' => 64 * 1024,
        'dedupe_ttl_seconds' => 7 * 24 * 3600,
        'clickhouse_flush_rows' => 5000,
        'clickhouse_flush_ms' => 1000,
        'worker_read_count' => 500,
        'worker_block_ms' => 1000,
        'rejection_retention_days' => 7,
        'schemas_path' => resource_path('schemas/events'),
    ],

    // Valores por defecto del sitio (docs/api-connector.md §5.1, eventos.md §5).
    'site_defaults' => [
        'paid_statuses' => ['processing', 'completed'],
        'cod_gateways' => ['cod'],
        'cod_paid_statuses' => ['completed'],
        'offline_gateways' => ['bacs', 'cheque', 'cod'],
        'excluded_roles' => ['administrator', 'shop_manager'],
        'backfill_months' => 24,
        'early_capture' => false,
    ],

    'min_plugin_version' => '0.1.0',

    'clickhouse' => [
        'url' => env('CLICKHOUSE_URL', 'http://127.0.0.1:8123'),
        'database' => env('CLICKHOUSE_DATABASE', 'igr'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 30),
    ],

    'roles' => [
        'workspace' => ['owner', 'admin', 'marketing', 'agent', 'viewer'],
        'account' => ['agency_owner', 'agency_member'],
    ],
];
