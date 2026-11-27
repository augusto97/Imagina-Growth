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
     * Modelos que se pueden elegir en Plataforma → Ajustes → IA (familia Claude 5). Cada uno con los esfuerzos que
     * admite (`output_config.effort`), el que usa si no se dice (`default_effort`) y si tiene el respaldo del lado del
     * servidor cuando se niega por política (`fallbacks`: Claude Haiku 5.5 no lo tiene). Todos deben estar en `pricing`.
     */
    'catalog' => [
        'claude-fable-5-2' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'default_effort' => 'high', 'fallbacks' => true],
        'claude-opus-5-5' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'default_effort' => 'medium', 'fallbacks' => true],
        'claude-sonnet-5-5' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'default_effort' => 'high', 'fallbacks' => true],
        'claude-haiku-5-5' => ['efforts' => ['low', 'medium', 'high', 'xhigh', 'max'], 'default_effort' => 'medium', 'fallbacks' => false],
    ],

    /*
     * Los dos grupos de funciones (inteligencia.md §6.1): el modelo «principal» escribe y razona (análisis, Pregúntale,
     * asistente, copiloto, contenido, agente de ventas, reporte mensual) y el «rápido» clasifica y resume (bandeja,
     * reseñas, resúmenes, leads). Vacíos, cada función usa lo suyo de `features`. Desde el panel (Ajustes → IA), en
     * caliente; `overrides` (solo desde el panel) cambia una función concreta y manda sobre su grupo.
     */
    'tiers' => [
        'main' => ['model' => env('IGR_AI_MODEL_MAIN'), 'effort' => env('IGR_AI_EFFORT_MAIN')],
        'fast' => ['model' => env('IGR_AI_MODEL_FAST'), 'effort' => env('IGR_AI_EFFORT_FAST')],
    ],
    // Se llena al final del archivo con una entrada por función (`IGR_AI_MODEL_<FUNCIÓN>`, `IGR_AI_EFFORT_<FUNCIÓN>`).
    'overrides' => [],

    /*
     * Modelo y esfuerzo por función (inteligencia.md §6.1): análisis, copiloto y agente
     * con Claude Sonnet 5.5; clasificación, moderación y resúmenes cortos con Claude Haiku 5.5.
     * `tier` dice a qué grupo pertenece (el modelo y el esfuerzo del grupo, si se eligieron, mandan).
     */
    'features' => [
        'weekly_analyst' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 4000],
        'ask_data' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 4000],
        // «Pregúntale a la IA» en toda la app: conversación con herramienta y salida JSON; esfuerzo bajo (es chat) y margen
        // de tokens para el pensamiento adaptativo (con 4.000 se cortaba y la respuesta quedaba vacía).
        'assistant' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 12000],
        'copilot' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'medium', 'max_tokens' => 8000],
        'content' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 4000],
        'sales_agent' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 2000],
        'inbound_classifier' => ['tier' => 'fast', 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512],
        'review_moderator' => ['tier' => 'fast', 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512],
        'profile_summary' => ['tier' => 'fast', 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 800],
        'review_summary' => ['tier' => 'fast', 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 800],
        'monthly_report' => ['tier' => 'main', 'model' => 'claude-sonnet-5-5', 'effort' => 'low', 'max_tokens' => 2000], // M22
        'lead_qualifier' => ['tier' => 'fast', 'model' => 'claude-haiku-5-5', 'effort' => 'low', 'max_tokens' => 512], // M18, ±20 puntos con motivo
    ],

    // USD por millón de tokens (cache_write = 1,25 × entrada, el TTL de 5 minutos).
    'pricing' => [
        'claude-fable-5-2' => ['input' => 8.00, 'output' => 40.00, 'cache_read' => 0.20, 'cache_write' => 10.00],
        'claude-opus-5-5' => ['input' => 4.00, 'output' => 20.00, 'cache_read' => 0.20, 'cache_write' => 5.00],
        'claude-sonnet-5-5' => ['input' => 2.00, 'output' => 10.00, 'cache_read' => 0.10, 'cache_write' => 2.50],
        'claude-haiku-5-5' => ['input' => 0.10, 'output' => 0.50, 'cache_read' => 0.01, 'cache_write' => 0.125],
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
    'lead_brief' => ['tier' => 'fast', 'model' => $economy, 'effort' => 'low', 'max_tokens' => 900],     // qué necesita, cantidad, ciudad, cuándo, presupuesto y temperatura
    'lead_followup' => ['tier' => 'fast', 'model' => $economy, 'effort' => 'low', 'max_tokens' => 700],  // seguimiento con IA y mensaje sugerido de «Hoy»
    'lead_funnel' => ['tier' => 'fast', 'model' => $economy, 'effort' => 'low', 'max_tokens' => 600],    // conclusión del embudo de leads del lunes
    'reactivation' => ['tier' => 'fast', 'model' => $economy, 'effort' => 'low', 'max_tokens' => 700],   // nodo «Reactivar con IA»: retomar según la última conversación y las compras
];

foreach (array_keys($config['features']) as $feature) {
    $env = strtoupper($feature);
    $config['overrides'][$feature] = ['model' => env("IGR_AI_MODEL_{$env}"), 'effort' => env("IGR_AI_EFFORT_{$env}")];
}

return $config;
