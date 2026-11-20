<?php

/*
 * IA de Imagina Growth (docs/inteligencia.md §6, hub/CLAUDE.md §12).
 *
 * Se llama a Claude con el SDK oficial de Anthropic para PHP (anthropic-ai/sdk).
 * La clave y la URL base son propias del hub (IGR_ANTHROPIC_*): nunca se toman
 * las variables ANTHROPIC_* del sistema, que pueden apuntar a otra cuenta o a un
 * proxy ajeno. Sin clave, la IA queda apagada y cada función usa su plantilla
 * sin IA.
 */
$config = [
    'enabled' => (bool) env('IGR_AI_ENABLED', true),
    'api_key' => env('IGR_ANTHROPIC_API_KEY'),
    'base_url' => env('IGR_ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
    'timeout' => (float) env('IGR_AI_TIMEOUT', 60),
    'max_retries' => (int) env('IGR_AI_MAX_RETRIES', 2),

    // Reintento del lado del servidor en otro modelo si el pedido se rechaza por política.
    'server_fallbacks' => (bool) env('IGR_AI_SERVER_FALLBACKS', true),

    /*
     * Modelo y esfuerzo por función (inteligencia.md §6.1): análisis, copiloto y agente
     * con Claude Sonnet 5.5; clasificación, moderación y resúmenes cortos con Claude Haiku 5.5.
     */
    'features' => [
        'weekly_analyst' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 4000],
        'ask_data' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 4000],
        'copilot' => ['model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 8000],
        'content' => ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 4000],
        'sales_agent' => ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 2000],
        'inbound_classifier' => ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512],
        'review_moderator' => ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512],
        'profile_summary' => ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 800],
        'review_summary' => ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 800],
        'monthly_report' => ['model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 2000], // M22
        'lead_qualifier' => ['model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512], // M18, ±20 puntos con motivo
    ],

    // USD por millón de tokens (cache_write = 1,25 × entrada, el TTL de 5 minutos).
    'pricing' => [
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
    ],

    // Tope mensual de gasto en IA por espacio de trabajo (USD). Los planes (M21) lo cambian en la Fase 5.
    'monthly_budget_usd' => (float) env('IGR_AI_MONTHLY_BUDGET_USD', 25),

    // Validación numérica (inteligencia.md §6.1): enteros sueltos que se permiten sin estar en los hechos.
    'validator' => [
        'free_integers' => [1, 2, 3],
    ],
];

/*
 * Bloque «ARM» de leads (M18): la ficha que se llena desde el chat, los mensajes de
 * seguimiento y sugeridos de «Hoy», y la conclusión del embudo semanal. Funciones
 * económicas: usan el mismo modelo que la calificación de leads.
 */
$economy = $config['features']['lead_qualifier']['model'];
$config['features'] += [
    'lead_brief' => ['model' => $economy, 'effort' => 'low', 'max_tokens' => 900],     // qué necesita, cantidad, ciudad, cuándo, presupuesto y temperatura
    'lead_followup' => ['model' => $economy, 'effort' => 'low', 'max_tokens' => 700],  // seguimiento con IA y mensaje sugerido de «Hoy»
    'lead_funnel' => ['model' => $economy, 'effort' => 'low', 'max_tokens' => 600],    // conclusión del embudo de leads del lunes
    'reactivation' => ['model' => $economy, 'effort' => 'low', 'max_tokens' => 700],   // nodo «Reactivar con IA»: retomar según la última conversación y las compras
];

return $config;
