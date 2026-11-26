<?php

/*
 * Textos del Inicio (docs/diseno/rediseno-v2.md §5) que arma el servidor: las acciones de «Para hoy» y los nombres de
 * lo que genera dinero sin ser una estrategia. Español neutro, segunda persona, sin jerga.
 */
return [
    'money' => [
        'buckets' => [
            'campaigns' => 'Campañas',
            'affiliates' => 'Afiliados',
            'messages' => 'Otros mensajes',
            'other_flows' => 'Otras automatizaciones',
            'other_widgets' => 'Otros formularios de tu web',
            'referrals' => 'Referidos',
            'inbox' => 'Conversaciones',
            'ai_agent' => 'Agente de ventas con IA',
            'recovered_carts' => 'Carritos recuperados',
            'leads' => 'Contactos ganados',
            'bookings' => 'Citas agendadas',
            'quotes' => 'Cotizador',
        ],
    ],

    // Los KPI de «Tu negocio»: nombre y cómo se calcula (la ayuda «?»).
    'kpis' => [
        'revenue' => ['label' => 'Ventas', 'help' => 'Lo que suman los pedidos pagados del mes.'],
        'orders' => ['label' => 'Pedidos', 'help' => 'Pedidos pagados del mes.'],
        'new_customers' => ['label' => 'Clientes nuevos', 'help' => 'Personas cuya primera compra pagada fue este mes.'],
        'returning_customers' => ['label' => 'Volvieron a comprar', 'help' => 'Personas que ya te habían comprado y volvieron este mes.'],
        'leads' => ['label' => 'Contactos nuevos', 'help' => 'Personas que dejaron sus datos o te escribieron por primera vez este mes.'],
        'first_response_minutes' => ['label' => 'Primera respuesta', 'help' => 'Minutos que tardó la primera respuesta a los contactos de este mes (la mediana).'],
        'appointments_booked' => ['label' => 'Citas agendadas', 'help' => 'Citas que se agendaron este mes.'],
        'appointments_attended' => ['label' => 'Citas cumplidas', 'help' => 'Citas de este mes a las que la persona llegó.'],
        'close_rate' => ['label' => 'Tasa de cierre', 'help' => 'De los contactos que se cerraron este mes, cuántos se ganaron.'],
        'won_value' => ['label' => 'Valor ganado', 'help' => 'Lo que suman los contactos ganados este mes.'],
    ],

    // De dónde vienen los contactos (leads.origin).
    'lead_origins' => [
        'form' => 'Formularios de tu web',
        'whatsapp' => 'WhatsApp',
        'messenger' => 'Messenger',
        'instagram' => 'Instagram',
        'booking' => 'Página de reservas',
        'quote' => 'Cotizador',
        'api' => 'Integraciones',
        'manual' => 'Creados a mano',
        'other' => 'Otros',
    ],

    'today' => [
        'connect_site' => [
            'title' => 'Conecta tu web',
            'why' => 'Sin tu web conectada no sabemos quién te compra ni podemos vender por ti.',
            'action' => 'Conectar mi web',
        ],
        'import_running' => [
            'title' => 'Estamos trayendo tu historial (:progress %)',
            'why' => 'Cuando termine, sabremos quién te compra, quién se fue y a quién escribirle primero.',
            'action' => 'Ver el avance',
        ],
        'import_failed' => [
            'title' => 'La importación de tu historial se detuvo',
            'why' => 'Sin tu historial no sabemos quiénes son tus clientes. Revisa qué pasó y retómala.',
            'action' => 'Revisar la importación',
        ],
        'connect_channel' => [
            'title' => 'Conecta WhatsApp o tu correo',
            'why' => 'Las estrategias necesitan un canal para escribirles a tus clientes.',
            'action' => 'Conectar un canal',
        ],
        'strategy_attention' => [
            'title' => '«:name» necesita tu atención',
            'why' => 'Algo dejó de funcionar.',
            'action' => 'Revisar',
        ],
        'reply_conversations' => [
            'title' => '{1} 1 cliente espera tu respuesta|[2,*] :count clientes esperan tu respuesta',
            'why' => 'Responder rápido es la forma más fácil de no perder una venta.',
            'action' => 'Ir a las conversaciones',
        ],
        'contact_leads' => [
            'title' => '{1} 1 contacto por atender hoy|[2,*] :count contactos por atender hoy',
            'why' => 'Quien recibe respuesta en minutos tiene muchas más probabilidades de comprarte.',
            'action' => 'Ver mi lista de hoy',
        ],
        'activate_strategy' => [
            'title' => 'Prende «:name»',
            'why' => 'Es lo que más dinero puede traerte ahora.',
            'action' => 'Ver cómo funciona',
        ],
        'insight' => [
            'why' => 'Impacto estimado: :amount.',
            'why_plain' => 'Lo detectamos en tus datos de esta semana.',
            'action' => 'Ver la recomendación',
        ],
    ],
];
