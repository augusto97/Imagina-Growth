<?php

/*
 * Conexiones con un clic (docs/auditoria-2026-11.md §6, docs/guia-apps-oauth.md): las apps de
 * desarrollador de la plataforma (Meta, Google, TikTok) van en Plataforma → Ajustes (F17; este archivo lee el .env de
 * respaldo y el panel lo pisa al arrancar); los tokens de cada
 * cliente, cifrados en oauth_connections. Sin el client_id de un proveedor, su botón explica qué falta y
 * los campos manuales siguen disponibles.
 *
 * Las URL de retorno son siempre {APP_URL}/oauth/{proveedor}/callback: regístralas tal cual en cada app.
 */
return [

    // Vida del `state` (un solo uso, ligado a la persona y al espacio de trabajo).
    'state_ttl_minutes' => 10,

    // A dónde vuelve la persona después de autorizar: claves fijas, nunca una URL que llegue de afuera.
    'return_paths' => [
        'conexiones' => 'conexiones',
        'canales' => 'canales',
        'medicion' => 'medicion',
        'agenda' => 'agenda?pestana=personas',
    ],

    'meta' => [
        // La misma app de Meta de WhatsApp (tipo Business, proveedor de tecnología).
        'app_id' => env('IGR_META_APP_ID'),
        'app_secret' => env('IGR_META_APP_SECRET'),
        'graph_url' => env('IGR_META_GRAPH_URL', 'https://graph.facebook.com'),
        // https://developers.facebook.com/docs/graph-api/changelog (v27.0, 4 nov 2026, es la vigente).
        'graph_version' => env('IGR_META_GRAPH_VERSION', 'v27.0'),
        'dialog_url' => env('IGR_META_DIALOG_URL', 'https://www.facebook.com'),
        // Facebook Login for Business: configuración con anuncios, conjuntos de datos (píxel), páginas e
        // Instagram (token de usuario del sistema). https://developers.facebook.com/docs/facebook-login/facebook-login-for-business
        'login_config_id' => env('IGR_META_LOGIN_CONFIG_ID'),
        // Embedded Signup de WhatsApp: su propia configuración de Login for Business.
        'whatsapp_config_id' => env('IGR_META_ES_CONFIG_ID'),
        // https://developers.facebook.com/documentation/business-messaging/whatsapp/embedded-signup/versions/
        // v5 (20 oct 2026, la recomendada): la coexistencia con la app de WhatsApp Business viene encendida.
        // v2 y v3 se retiraron el 15 oct 2026.
        'embedded_signup_version' => env('IGR_META_ES_VERSION', 'v5'),
    ],

    'google' => [
        'client_id' => env('IGR_GOOGLE_CLIENT_ID'),
        'client_secret' => env('IGR_GOOGLE_CLIENT_SECRET'),
        // https://developers.google.com/identity/protocols/oauth2/web-server
        'auth_url' => env('IGR_GOOGLE_OAUTH_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('IGR_GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'revoke_url' => env('IGR_GOOGLE_OAUTH_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'analytics_admin_url' => env('IGR_GOOGLE_ANALYTICS_ADMIN_URL', 'https://analyticsadmin.googleapis.com/v1beta'),
        // Alcances por uso. `adwords` para listar cuentas y acciones de conversión (Google Ads API) y
        // `datamanager` para enviar conversiones y audiencias (Data Manager API). `analytics.edit` para
        // listar las propiedades y crear el secreto del Measurement Protocol.
        'scopes' => [
            'identity' => ['openid', 'email'],
            'ads' => ['https://www.googleapis.com/auth/adwords', 'https://www.googleapis.com/auth/datamanager'],
            'analytics' => ['https://www.googleapis.com/auth/analytics.edit'],
            'calendar' => ['https://www.googleapis.com/auth/calendar.events', 'https://www.googleapis.com/auth/calendar.freebusy'],
        ],
    ],

    'tiktok' => [
        'app_id' => env('IGR_TIKTOK_APP_ID'),
        'app_secret' => env('IGR_TIKTOK_APP_SECRET'),
        'auth_url' => env('IGR_TIKTOK_AUTH_URL', 'https://business-api.tiktok.com/portal/auth'),
        'api_url' => env('IGR_TIKTOK_API_URL', 'https://business-api.tiktok.com/open_api/v1.3'),
    ],
];
