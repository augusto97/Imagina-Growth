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

    // Registro abierto a cualquiera (true) o solo por invitación (false, por defecto). La primera persona de una
    // instalación nueva siempre puede registrarse (App\Support\Registration).
    'registration_open' => (bool) env('IGR_OPEN_REGISTRATION', false),

    'ingest' => [
        'shards' => (int) env('IGR_INGEST_SHARDS', 4),
        'stream_prefix' => 'ingest:',
        'consumer_group' => 'ingest-workers',
        // Tope por shard por si los workers se detienen; lo normal es que el worker recorte lo confirmado.
        'stream_maxlen' => (int) env('IGR_INGEST_STREAM_MAXLEN', 250_000),
        'trim_every_seconds' => 5,
        'max_failures' => 5,             // un evento que falla solo 5 veces con la base disponible va a ingest_rejections
        'max_event_bytes' => 32 * 1024,
        'server_batch_max_events' => 500,
        'server_batch_max_bytes' => 1024 * 1024,          // comprimido
        'server_batch_max_inflated_bytes' => 16 * 1024 * 1024,
        'browser_batch_max_events' => 20,
        'browser_batch_max_bytes' => 64 * 1024,
        'dedupe_ttl_seconds' => 4 * 24 * 3600,      // cubre los reintentos del plugin (~3 días)
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

    // Versión del plugin que trae este paquete del hub en public/downloads (infra/release/build.sh). Una prueba la
    // compara con la cabecera de connector/imagina-growth-connector.php: suben juntas.
    'plugin' => [
        'latest_version' => env('IGR_PLUGIN_LATEST_VERSION', '0.1.3'),
        'download_path' => '/downloads/imagina-growth-connector.zip',
    ],

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

    // Tasas de cambio diarias (F11, igr:fx:update): unidades de cada moneda por 1 USD para mostrar el costo estimado
    // de los mensajes en la moneda del espacio de trabajo. La fuente por defecto es el acceso libre de ExchangeRate-API
    // (https://www.exchangerate-api.com/docs/free): sin llave, se actualiza una vez al día, se puede guardar y pide
    // mostrar «Rates By Exchange Rate API» con su enlace donde se usan las tasas. Si falla, rige la última tasa conocida;
    // el superadmin puede fijar una tasa a mano por moneda (Plataforma → Tasas de cambio), por ejemplo la oficial del
    // BCV para VES, que no tiene API.
    'fx' => [
        'url' => env('IGR_FX_URL', 'https://open.er-api.com/v6/latest/USD'),
        'source' => 'open.er-api.com',
        'attribution_url' => 'https://www.exchangerate-api.com',
        'currencies' => ['COP', 'MXN', 'VES', 'PEN', 'CLP', 'ARS', 'EUR'],
        // Más viejo que esto (sin tasa manual), Plataforma e igr:doctor avisan.
        'stale_after_days' => 3,
    ],

    // Purga por retención (R14, igr:retention:purge cada noche), en días. ingest_rejections usa
    // ingest.rejection_retention_days; webhook_deliveries, igr:webhooks:prune (30); conversion_deliveries, el TTL de
    // ClickHouse (90).
    'retention' => [
        'failed_jobs' => (int) env('IGR_RETENTION_FAILED_JOBS_DAYS', 30),
        'job_batches' => 7,
        'connect_codes' => 7,
        'invitations' => 30,
        'password_resets' => 1,
        'flow_run_steps' => (int) env('IGR_RETENTION_FLOW_STEPS_DAYS', 180),
        'ai_agent_turns' => (int) env('IGR_RETENTION_AGENT_TURNS_DAYS', 180),
        // Mensajes (cumplimiento.md §10: 24 meses y después archivo agregado en `message_archive_daily`). Nunca baja
        // de 90 días ni de la ventana de atribución más larga más 60 días (RetentionPurgeCommand).
        'messages' => (int) env('IGR_RETENTION_MESSAGES_DAYS', 730),
    ],

    // Eventos del hub hacia ClickHouse (R4): auto = por la cola dentro de una petición web y en el momento en consola
    // (con la cola de respaldo si ClickHouse falla); queue = siempre por la cola; direct = siempre en el momento.
    'hub_events' => [
        'write' => env('IGR_HUB_EVENTS_WRITE', 'auto'),
    ],

    // GET /health y /health/ready (S16): en público solo dicen ok o fallo; el detalle de dependencias sale desde la
    // propia máquina (127.0.0.1, sin pasar por Caddy) o con este token en `X-IGR-Health-Token` o `Authorization: Bearer`.
    'health' => [
        'token' => env('IGR_HEALTH_TOKEN'),
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
