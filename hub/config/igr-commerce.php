<?php

/*
 * Comercio en el sitio (modulos.md M16): recomendaciones, avisos de stock y
 * precio, y banners personalizados. Los umbrales se ajustan aquí sin desplegar
 * código; cada espacio de trabajo cambia lo suyo en commerce_settings.
 */
return [

    'recommendations' => [
        // Reglas de asociación (inteligencia.md §4.5): pedidos pagados de 12 meses.
        'associations' => [
            'window_days' => 365,
            'min_pair_orders' => 3,      // pedidos con los dos productos
            'min_confidence' => 0.05,    // P(B | A)
            'min_lift' => 1.0,           // más que el azar
            'max_per_product' => 20,
        ],
        // «Más vendidos» y popularidad: unidades de los pedidos pagados de 90 días.
        'best_sellers_days' => 90,
        // «Tendencia esta semana»: unidades de 7 días frente al promedio semanal de las 4 anteriores.
        'trending_min_units' => 2,
        // Afinidad por categoría (inteligencia.md §4.5): vista 1, carrito 3, compra 5, vida media de 30 días.
        'affinity' => ['view' => 1.0, 'cart' => 3.0, 'purchase' => 5.0, 'half_life_days' => 30, 'top_categories' => 5],
        'max_limit' => 12,
        // Caché del lado del hub para los tipos que no dependen de la persona (además de la del CDN).
        'cache_seconds' => 60,
        // Límite por IP (con hash) del endpoint público.
        'rate_per_minute' => 240,
    ],

    'watches' => [
        // Un mismo producto no se le avisa a la misma persona más de una vez en estos días.
        'frequency_cap_days' => 7,
        // Un aviso que nunca se pudo enviar vence a los N días.
        'expire_days' => 120,
        // Si no hubo canal o plantilla, se vuelve a intentar a las N horas.
        'retry_hours' => 6,
        'max_attempts' => 20,
        // Límites del endpoint público (429).
        'per_ip_per_minute' => 10,
        'per_visitor_per_minute' => 5,
        // Plantillas de la biblioteca (M08) por tipo y canal; WhatsApp necesita una plantilla aprobada elegida en el panel.
        'library_templates' => [
            'back_in_stock' => ['email' => 'back_in_stock', 'sms' => 'sms_back_in_stock'],
            'price_drop' => ['email' => 'price_drop'],
        ],
    ],

    'banners' => [
        'rate_per_minute' => 120,
        // Días en los que un carrito sin pagar todavía cuenta como «pendiente».
        'pending_cart_days' => 7,
        'at_risk_segments' => ['at_risk', 'cant_lose'],
    ],
];
