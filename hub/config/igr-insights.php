<?php

/*
 * Motor de hallazgos (docs/inteligencia.md §5). Los supuestos del impacto son
 * conservadores, visibles y editables por espacio de trabajo
 * (`insight_assumptions`); estos son los valores por defecto. Los umbrales
 * deciden cuándo algo es un hallazgo.
 */
return [
    'assumptions' => [
        // #8 clientes valiosos en riesgo: cuántos vuelven con una recuperación.
        'reactivation_rate' => ['default' => 0.18, 'unit' => 'rate', 'min' => 0.01, 'max' => 0.9],
        // #2 método de pago: pedidos sin pagar que se recuperan al arreglarlo.
        'payment_recovery_rate' => ['default' => 0.30, 'unit' => 'rate', 'min' => 0.01, 'max' => 1.0],
        // #15 flujo esencial inactivo: tasa de recuperación de referencia de carritos abandonados.
        'cart_recovery_rate' => ['default' => 0.05, 'unit' => 'rate', 'min' => 0.005, 'max' => 0.5],
        // #9 primera compra estancada: cuántos hacen la segunda con un flujo.
        'second_purchase_rate' => ['default' => 0.10, 'unit' => 'rate', 'min' => 0.01, 'max' => 0.6],
        // #3 productos muy vistos: parte de la brecha de conversión que se cierra al mejorarlos.
        'product_fix_share' => ['default' => 0.25, 'unit' => 'rate', 'min' => 0.05, 'max' => 1.0],
        // #4 abandono en el carrito: parte del abandono de más que se recupera.
        'cart_fix_share' => ['default' => 0.20, 'unit' => 'rate', 'min' => 0.05, 'max' => 1.0],
        // #5 búsquedas sin resultados: parte que compraría si encontrara el producto.
        'search_capture_rate' => ['default' => 0.25, 'unit' => 'rate', 'min' => 0.01, 'max' => 1.0],
        // #13 ciudad con potencial: parte de la brecha de conversión que se cierra.
        'city_fix_share' => ['default' => 0.25, 'unit' => 'rate', 'min' => 0.05, 'max' => 1.0],
    ],

    'thresholds' => [
        'concentration_share' => 0.5,      // #10: el 10 % de los clientes genera al menos el 50 %
        'ltv_ratio' => 1.5,                // #11 y #12: el mejor vale al menos 1,5× el peor (o el promedio)
        'discount_share_points' => 0.10,   // #14: el % de pedidos con cupón sube 10 puntos o más
        'bounce_rate' => 0.02,             // #17
        'complaint_rate' => 0.001,         // #17
        'fatigue_per_week' => 3,           // #18: más de N mensajes de marketing por semana
        'conversion_failure_rate' => 0.10, // #19
        'match_keys_min' => 2.0,           // #19: claves coincidentes promedio por conversión
    ],
];
