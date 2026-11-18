<?php

/*
 * Medición: conversiones desde el servidor y gasto en anuncios (modulos.md M06,
 * hub/CLAUDE.md §11). Las versiones de API se cambian aquí sin desplegar código.
 */
return [

    // Reintentos de una entrega (segundos) y tope: las plataformas descartan conversiones viejas.
    'retry_delays' => [60, 300, 1800, 7200],
    'give_up_after_hours' => 24,

    // Catálogo de Imagina Growth → evento estándar de cada plataforma.
    // page.viewed solo se envía si el destino lo activa.
    'events' => [
        'page.viewed' => ['meta' => 'PageView', 'google' => 'page_view', 'tiktok' => 'Pageview', 'default' => false],
        'product.viewed' => ['meta' => 'ViewContent', 'google' => 'view_item', 'tiktok' => 'ViewContent', 'default' => true],
        'cart.item_added' => ['meta' => 'AddToCart', 'google' => 'add_to_cart', 'tiktok' => 'AddToCart', 'default' => true],
        'checkout.started' => ['meta' => 'InitiateCheckout', 'google' => 'begin_checkout', 'tiktok' => 'InitiateCheckout', 'default' => true],
        'order.paid' => ['meta' => 'Purchase', 'google' => 'purchase', 'tiktok' => 'CompletePayment', 'default' => true],
        'form.submitted' => ['meta' => 'Lead', 'google' => 'generate_lead', 'tiktok' => 'SubmitForm', 'default' => true],
        'customer.registered' => ['meta' => 'CompleteRegistration', 'google' => 'sign_up', 'tiktok' => 'CompleteRegistration', 'default' => true],
        // Conversión offline de un lead ganado (M18): la envía SendLeadConversion, no el
        // ConversionRouter (es un evento del hub). Meta con action_source system_generated y
        // Google Ads por Data Manager (acción «lead ganado», eventSource OTHER). [verificar] nombres.
        'lead.won' => ['meta' => 'Purchase', 'google' => 'close_convert_lead', 'default' => true, 'offline' => true, 'meta_action_source' => 'system_generated', 'google_event_source' => 'OTHER'],
    ],

    // Conversions API for Business Messaging solo acepta estos nombres [verificar con la lista vigente de Meta].
    'meta_business_messaging_events' => ['Purchase', 'LeadSubmitted', 'InitiateCheckout', 'AddToCart', 'ViewContent', 'QualifiedLead'],

    'meta' => [
        'graph_url' => env('IGR_META_GRAPH_URL', 'https://graph.facebook.com'),
        'graph_version' => env('IGR_META_GRAPH_VERSION', 'v24.0'), // [verificar] versión vigente
    ],

    'google' => [
        'ga4_url' => 'https://www.google-analytics.com/mp/collect',
        'ga4_debug_url' => 'https://www.google-analytics.com/debug/mp/collect',
        // Se cambian solo en pruebas de punta a punta (simuladores locales).
        'data_manager_url' => env('IGR_GOOGLE_DATA_MANAGER_EVENTS_URL', 'https://datamanager.googleapis.com/v1/events:ingest'),
        'oauth_token_url' => env('IGR_GOOGLE_OAUTH_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'ads_url' => 'https://googleads.googleapis.com',
        'ads_version' => env('IGR_GOOGLE_ADS_VERSION', 'v21'), // [verificar] versión vigente
    ],

    'tiktok' => [
        'events_url' => 'https://business-api.tiktok.com/open_api/v1.3/event/track/',
        'report_url' => 'https://business-api.tiktok.com/open_api/v1.3/report/integrated/get/',
    ],
];
