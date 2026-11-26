<?php

/*
 * Canales de mensajería (M07, hub/CLAUDE.md §8). Las credenciales de la
 * plataforma (SES, app de Meta) van en Plataforma → Ajustes (F17; el .env es el respaldo); las de cada cliente, cifradas en
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
    // Imagina Whats (WhatsApp Web por API REST v1 y webhooks firmados): la misma regla que Zender, porque también es una
    // conexión no oficial (cumplimiento.md §5.2). Cada cliente pega su clave de API; la URL es la de la plataforma.
    'imagina_whats' => [
        'url' => env('IGR_IMAGINA_WHATS_URL', 'https://whats.imagina.cloud'),
        'hourly_cap' => 30,
        'daily_cap' => 200,
        // Permisos mínimos de la clave: probar la conexión, ver y usar el número, leer estados y registrar el webhook.
        'abilities' => ['account.view', 'wa.send', 'wa.read', 'wa.accounts.view', 'webhooks.view', 'webhooks.manage'],
        // Eventos del webhook que escucha el hub.
        'events' => ['wa.message.received', 'wa.message.sent', 'wa.message.delivered', 'wa.message.read', 'wa.message.failed', 'wa.message.canceled', 'contact.unsubscribed'],
        'timeout' => 20,
    ],
    'twilio' => [
        'api_url' => env('IGR_TWILIO_API_URL', 'https://api.twilio.com'),
        'rate_per_second' => 10,
    ],
    'smtp' => ['rate_per_second' => 5],
    // Un mensaje con más de estos minutos en `sending` se da por trabado (F9, igr:messages:stuck cada 5 min).
    'stuck_sending_minutes' => (int) env('IGR_MESSAGES_STUCK_MINUTES', 15),
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
    // Costo estimado por mensaje en USD (F11; se muestra en la moneda del espacio de trabajo con las tasas del día).
    // WhatsApp usa `wa_rates` (WaRateCard). Correo: SES cobra USD 0,10 por 1.000 (https://aws.amazon.com/ses/pricing/,
    // 20 nov 2026). SMS: Twilio por segmento (se estima uno por mensaje; https://www.twilio.com/en-us/sms/pricing/co,
    // /mx y /ve, noviembre de 2026; `default` para los demás países).
    'costs' => [
        'email_usd' => 0.0001,
        'sms_usd' => ['CO' => 0.0592, 'MX' => 0.1819, 'VE' => 0.2257, 'default' => 0.0592],
    ],
];
