<?php

/*
 * Planes de Imagina Growth (M21). Se sincronizan con la tabla `plans` con
 * `php artisan igr:plans:sync`. Tiendas: por pedidos al mes; servicios: por
 * contactos activos al mes. Precios de referencia [verificar con el equipo comercial].
 *
 * Módulos (Entitlements, Pennant): onsite, loyalty, referrals, reviews, inbox, ai,
 * ai_agent, services, bookings, quotes, api, mcp, agency_reports, white_label.
 * Límites: emails (correos incluidos al mes), ai_budget_usd, users,
 * api_requests_per_minute. El volumen principal (pedidos o contactos) es `included`.
 */
return [
    'modules' => ['onsite', 'loyalty', 'referrals', 'reviews', 'inbox', 'ai', 'ai_agent', 'services', 'bookings', 'quotes', 'api', 'mcp', 'agency_reports', 'white_label'],

    // Umbrales de aviso de uso y días de gracia: sin cortes bruscos (M21).
    'alert_thresholds' => [80, 100],
    'grace_days' => 7,
    // Días que un espacio de trabajo nuevo prueba todo sin plan.
    'trial_days' => 14,

    // Facturas v1 (manuales): numeración IGR-AAAA-NNNN, vencimiento y enlace de pago.
    'invoices' => [
        'number_prefix' => env('IGR_INVOICE_PREFIX', 'IGR'),
        'due_days' => 15,
        // manual: el enlace es la URL fija de abajo (con {number} y {total}) o ninguno; fake: pruebas.
        'payment_link_provider' => env('IGR_PAYMENT_LINK_PROVIDER', 'manual'),
        'manual_payment_url' => env('IGR_PAYMENT_MANUAL_URL'),
        // Correo al que llegan los pedidos de cambio de plan (además de los superadmins).
        'platform_email' => env('IGR_PLATFORM_BILLING_EMAIL'),
    ],

    'plans' => [
        'tienda_inicio' => [
            'name' => 'Tienda · Inicio', 'metric' => 'orders', 'included' => 300,
            'modules' => ['onsite', 'reviews', 'ai'],
            'limits' => ['emails' => 5000, 'ai_budget_usd' => 5, 'users' => 3, 'api_requests_per_minute' => 60],
            'price_usd' => 49, 'price_cop' => 199000,
        ],
        'tienda_crece' => [
            'name' => 'Tienda · Crece', 'metric' => 'orders', 'included' => 1500,
            'modules' => ['onsite', 'loyalty', 'referrals', 'reviews', 'inbox', 'ai', 'ai_agent', 'api'],
            'limits' => ['emails' => 25000, 'ai_budget_usd' => 25, 'users' => 10, 'api_requests_per_minute' => 120],
            'price_usd' => 149, 'price_cop' => 599000,
        ],
        'tienda_pro' => [
            'name' => 'Tienda · Pro', 'metric' => 'orders', 'included' => 6000,
            'modules' => ['onsite', 'loyalty', 'referrals', 'reviews', 'inbox', 'ai', 'ai_agent', 'services', 'bookings', 'quotes', 'api', 'mcp'],
            'limits' => ['emails' => 100000, 'ai_budget_usd' => 80, 'users' => 25, 'api_requests_per_minute' => 300],
            'price_usd' => 349, 'price_cop' => 1399000,
        ],
        'servicios_inicio' => [
            'name' => 'Servicios · Inicio', 'metric' => 'contacts', 'included' => 1000,
            'modules' => ['onsite', 'reviews', 'services', 'bookings', 'quotes', 'ai'],
            'limits' => ['emails' => 5000, 'ai_budget_usd' => 5, 'users' => 3, 'api_requests_per_minute' => 60],
            'price_usd' => 39, 'price_cop' => 159000,
        ],
        'servicios_pro' => [
            'name' => 'Servicios · Pro', 'metric' => 'contacts', 'included' => 5000,
            'modules' => ['onsite', 'reviews', 'services', 'bookings', 'quotes', 'inbox', 'ai', 'ai_agent', 'api', 'mcp'],
            'limits' => ['emails' => 25000, 'ai_budget_usd' => 25, 'users' => 10, 'api_requests_per_minute' => 120],
            'price_usd' => 129, 'price_cop' => 519000,
        ],
        'agencia' => [
            'name' => 'Agencia', 'metric' => 'orders', 'included' => 6000,
            'modules' => ['onsite', 'loyalty', 'referrals', 'reviews', 'inbox', 'ai', 'ai_agent', 'services', 'bookings', 'quotes', 'api', 'mcp', 'agency_reports', 'white_label'],
            'limits' => ['emails' => 100000, 'ai_budget_usd' => 80, 'users' => 50, 'api_requests_per_minute' => 300],
            'price_usd' => 299, 'price_cop' => 1199000, 'public' => false,
        ],
    ],

    // Paquetes adicionales que se facturan a mano (v1).
    'packs' => [
        'orders_500' => ['name' => '500 pedidos adicionales', 'price_usd' => 25],
        'contacts_1000' => ['name' => '1.000 contactos activos adicionales', 'price_usd' => 20],
        'emails_10000' => ['name' => '10.000 correos adicionales', 'price_usd' => 10],
    ],
];
