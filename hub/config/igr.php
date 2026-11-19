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

    // Analítica (M05): caché de 5 min por consulta, que se invalida cuando llegan eventos nuevos.
    // Se apaga para medir el criterio de la Fase 1 sin caché.
    'analytics' => [
        'cache' => (bool) env('IGR_ANALYTICS_CACHE', true),
    ],

    'clickhouse' => [
        'url' => env('CLICKHOUSE_URL', 'http://127.0.0.1:8123'),
        'database' => env('CLICKHOUSE_DATABASE', 'igr'),
        'username' => env('CLICKHOUSE_USERNAME', 'default'),
        'password' => env('CLICKHOUSE_PASSWORD', ''),
        'timeout' => (int) env('CLICKHOUSE_TIMEOUT', 30),
    ],

    // API pública y servidor MCP (M19): peticiones por minuto si el plan no fija `api_requests_per_minute`.
    'api' => [
        'per_minute' => (int) env('IGR_API_PER_MINUTE', 120),
    ],

    // Webhooks salientes (M19): se pausan tras N fallos seguidos. Las URLs privadas (localhost,
    // redes internas) solo se aceptan en local y pruebas, salvo que se diga otra cosa.
    'webhooks' => [
        'pause_after_failures' => (int) env('IGR_WEBHOOKS_PAUSE_AFTER_FAILURES', 15),
        'allow_private_urls' => env('IGR_WEBHOOKS_ALLOW_PRIVATE_URLS') !== null
            ? (bool) env('IGR_WEBHOOKS_ALLOW_PRIVATE_URLS')
            : in_array(env('APP_ENV', 'production'), ['local', 'testing'], true),
    ],

    // Dominios propios de las agencias (M22): el CNAME al que deben apuntar el panel, los enlaces y la página de reservas.
    'custom_domains' => [
        // A dónde apuntan con CNAME los dominios propios de las agencias: por defecto, el dominio del panel.
        'cname_target' => env('IGR_CUSTOM_DOMAINS_CNAME', parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: 'growth.imagina.cloud'),
    ],

    'roles' => [
        'workspace' => ['owner', 'admin', 'marketing', 'agent', 'viewer'],
        'account' => ['agency_owner', 'agency_member'],
    ],
];
