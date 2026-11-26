<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Support;

/**
 * Valores por defecto de leads (modulos.md M18, inteligencia.md §4.9). Cada
 * espacio de trabajo los cambia en Leads → Ajustes (`lead_settings`).
 */
final class LeadDefaults
{
    /** Pipeline simple: nuevo → contactado → calificado → propuesta → ganado o perdido. */
    public const STAGES = [
        ['key' => 'new', 'label' => 'Nuevo', 'kind' => 'open'],
        ['key' => 'contacted', 'label' => 'Contactado', 'kind' => 'open'],
        ['key' => 'qualified', 'label' => 'Calificado', 'kind' => 'open'],
        ['key' => 'proposal', 'label' => 'Propuesta', 'kind' => 'open'],
        ['key' => 'won', 'label' => 'Ganado', 'kind' => 'won'],
        ['key' => 'lost', 'label' => 'Perdido', 'kind' => 'lost'],
    ];

    /**
     * Etapas en las que el negocio ya envió una cotización, una propuesta o una oferta (las de los embudos de cada
     * vertical, VerticalCatalog). Entrar a una de ellas desde otra emite `quote.sent` (eventos.md v8).
     */
    public const QUOTE_STAGES = ['proposal', 'quoted', 'offer'];

    /** Señales del puntaje (§4.9) y sus puntos. Puntos en 0 apagan la señal. */
    public const SIGNALS = ['pricing_page', 'recurring_visit', 'form', 'whatsapp_click', 'corporate_email', 'service_area', 'decay_weekly'];

    public const SCORING = [
        'points' => [
            'pricing_page' => 10,
            'recurring_visit' => 5,
            'form' => 30,
            'whatsapp_click' => 15,
            'corporate_email' => 10,
            'service_area' => 5,
            'decay_weekly' => 5,
        ],
        // Rutas que cuentan como «página de precios o servicios» (prefijo, sin dominio).
        'pricing_paths' => ['/precios', '/tarifas', '/planes', '/servicios', '/cotiza', '/pricing'],
        // Ciudades del área de servicio (vacío: la señal no aplica).
        'service_cities' => [],
        // Dominios de correo personal además de la lista fija (no suman «correo corporativo»).
        'personal_domains' => [],
        'thresholds' => ['warm' => 30, 'hot' => 60],
        'ai_max_points' => 20,
    ];

    /** Correo personal: no cuenta como dominio corporativo. */
    public const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.es', 'hotmail.co', 'outlook.com', 'outlook.es', 'live.com', 'live.com.mx',
        'msn.com', 'yahoo.com', 'yahoo.es', 'yahoo.com.mx', 'yahoo.com.co', 'ymail.com', 'icloud.com', 'me.com', 'mac.com', 'aol.com',
        'protonmail.com', 'proton.me', 'gmx.com', 'mail.com', 'zoho.com', 'yandex.com', 'prodigy.net.mx', 'une.net.co', 'cantv.net',
    ];

    public const ALERTS = [
        'on_hot' => true,          // aviso al instante cuando el lead llega a «caliente»
        'on_new' => false,         // aviso con cada lead nuevo
        'channel' => 'whatsapp',   // whatsapp (con respaldo por correo) | email
        'whatsapp_template_id' => null, // plantilla de WhatsApp aprobada (si no, la de library_key team_alert)
    ];

    public const ASSIGNMENT = [
        'mode' => 'round_robin',   // round_robin | rules | none
        'reassign_unanswered_minutes' => null,
    ];

    /**
     * «Hoy» del vendedor: días sin moverse de etapa para que un lead salga como «quieto», la
     * plantilla de WhatsApp aprobada (marketing) para escribirle fuera de la ventana de 24 h
     * (si no, la de `library_key` wa_lead_followup) y el resumen matutino al vendedor.
     */
    public const ASSISTANT = [
        'stale_days' => 7,
        'whatsapp_template_id' => null,
        'digest' => false,
    ];

    /** Días en los que un lead perdido se reabre si la persona vuelve a escribir. */
    public const REOPEN_LOST_DAYS = 30;
}
