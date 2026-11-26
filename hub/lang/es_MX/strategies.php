<?php

/* Solo lo que cambia frente a lang/es/strategies.php (RegionalFileLoader). */
return [
    'items' => [
        'payment_pending' => [
            'promise' => 'Recuerda a tus clientes pagar los pedidos por transferencia, SPEI u OXXO antes de que se cancelen.',
        ],
        'cod_confirm' => [
            'name' => 'Confirma los pedidos de pago contra entrega',
            'promise' => 'Antes de despachar, tu cliente confirma su pedido de pago contra entrega con un toque por WhatsApp.',
            // Solo los pasos que cambian (el del medio es igual): se mezclan por posición.
            'steps' => [
                0 => 'Entra un pedido para pagar contra entrega.',
                2 => 'Si en 12 horas no responde, te avisamos para que lo llames antes de enviar.',
            ],
        ],
    ],
    'potential' => [
        'cod_pending' => '{1} 1 pedido de pago contra entrega por :value espera confirmación.|[2,*] :count pedidos de pago contra entrega por :value esperan confirmación.',
    ],
];
