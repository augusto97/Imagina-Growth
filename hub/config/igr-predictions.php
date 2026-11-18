<?php

/*
 * Predicciones y puntajes (docs/inteligencia.md §4). El servicio Python (hub/ml)
 * ajusta BG/NBD y Gamma-Gamma; el resto se calcula en el hub cada noche.
 */
return [
    'ml' => [
        'url' => env('IGR_ML_URL', 'http://127.0.0.1:8095'),
        'token' => env('IGR_ML_TOKEN'),
        'timeout' => (int) env('IGR_ML_TIMEOUT', 300),
    ],

    // Periodo de reserva: se ajusta con la historia hasta hace 90 días y se compara con lo que pasó.
    'holdout_days' => 90,

    // «Se muestra una predicción solo si hay datos suficientes» (§4).
    'thresholds' => [
        'edno_repeat_customers' => (int) env('IGR_PRED_EDNO_MIN', 300),
        'clv_customers' => (int) env('IGR_PRED_CLV_MIN', 500),
        'clv_history_months' => 12,
        'heuristic_repeat_customers' => 50,
        'propensity_buyers' => 2000,
        'propensity_sessions_month' => 50000,
    ],

    // P(activo) → banda de abandono (churn_risk = 1 − P(activo)).
    'churn_bands' => ['high_below' => 0.3, 'medium_below' => 0.7],

    'affinity' => [
        'weights' => ['view' => 1, 'cart' => 3, 'purchase' => 5, 'review' => 2],
        'half_life_days' => 30,
        'window_days' => 180,
        'top' => 5,
    ],

    'transitions' => ['min_support' => 3, 'top' => 10],

    'best_hour' => ['min_interactions' => 5, 'half_life_days' => 60, 'window_days' => 180, 'channel_days' => 90, 'min_sent' => 3],

    'engagement' => [
        'weights' => ['visit' => 1, 'open' => 1, 'read' => 2, 'click' => 3, 'reply' => 4, 'purchase' => 6],
        'half_life_days' => 30,
        'window_days' => 180,
        'scale' => 15,
    ],
];
