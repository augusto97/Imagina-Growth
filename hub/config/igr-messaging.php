<?php

/*
 * Canales de mensajería (M07, hub/CLAUDE.md §8). Las credenciales de la
 * plataforma (SES, app de Meta) van en el .env; las de cada cliente, cifradas en
 * channel_accounts.config. Lo marcado [verificar] necesita una cuenta real.
 */
return [
    'ses' => [
        // Cuenta de SES de la plataforma: cada espacio de trabajo verifica su propio dominio.
        'region' => env('IGR_SES_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'configuration_set' => env('IGR_SES_CONFIGURATION_SET', 'igr-events'), // [verificar] con eventos hacia SNS
        'sns_topic_arn' => env('IGR_SES_SNS_TOPIC_ARN'),
        'rate_per_second' => (int) env('IGR_SES_RATE', 14),
    ],
    'meta' => [
        'graph_url' => env('IGR_META_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('IGR_META_GRAPH_VERSION', 'v27.0'),          // https://developers.facebook.com/docs/graph-api/changelog
        'app_id' => env('IGR_META_APP_ID'),                                    // app de Meta de tipo Business (proveedor de tecnología), docs/guia-apps-oauth.md
        'app_secret' => env('IGR_META_APP_SECRET'),
        'embedded_signup_config_id' => env('IGR_META_ES_CONFIG_ID'),           // config/igr-connections.php
        'webhook_verify_token' => env('IGR_META_WEBHOOK_VERIFY_TOKEN'),
        'rate_per_second' => (int) env('IGR_META_RATE', 50),
    ],
    'zender' => [
        // Tope fijo de la plataforma (cumplimiento.md §5.2): el cliente no lo puede subir.
        'hourly_cap' => 30,
        'daily_cap' => 200,
    ],
    'twilio' => [
        'api_url' => env('IGR_TWILIO_API_URL', 'https://api.twilio.com'),
        'rate_per_second' => 10,
    ],
    'smtp' => ['rate_per_second' => 5],
    // Calentamiento de un dominio nuevo: tope diario por día desde que se verificó (después, sin tope).
    'warmup' => [50, 100, 250, 500, 1000, 2500, 5000, 10000, 20000, 40000],
    // Dominio de los enlaces rastreados, la baja y las preferencias (go.growth.imagina.cloud o el del cliente).
    'links_url' => env('IGR_LINKS_URL', env('IGR_APP_URL', env('APP_URL', 'http://localhost'))),
    // Palabras de baja en WhatsApp y SMS (hub/CLAUDE.md §8), ya normalizadas (minúsculas, sin tildes ni signos).
    'unsubscribe_keywords' => [
        'baja', 'de baja', 'darme de baja', 'quiero darme de baja', 'dame de baja', 'denme de baja',
        'no mas', 'no mas mensajes', 'no quiero mas mensajes', 'no quiero recibir mas mensajes',
        'stop', 'para', 'parar', 'cancelar', 'cancelar suscripcion', 'desuscribir', 'unsubscribe',
        'no me escriban', 'no me escribas', 'no me envien mas', 'no me manden mas', 'eliminarme', 'borrarme',
    ],
    'preference_topics' => [
        'ofertas' => 'Ofertas y descuentos',
        'novedades' => 'Novedades y lanzamientos',
        'recordatorios' => 'Recordatorios de tu carrito y tus pedidos',
        'contenido' => 'Consejos y contenido',
    ],
    'costs' => ['email_usd' => 0.0001, 'sms_usd' => 0.05],
];
