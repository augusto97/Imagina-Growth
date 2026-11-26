<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

/**
 * Los 35 flujos listos de modulos.md M09 en español (del 21 al 35, los de
 * negocios sin tienda y los nuevos de tienda: rediseño v2 §11.4). Los que hace su propio
 * módulo (pedir reseña, NPS y avisos de stock de la Fase 3; recordatorio de cita
 * de la Fase 5, que envía la agenda) llevan a la configuración de ese módulo,
 * para no escribirle dos veces a nadie por lo mismo.
 *
 * Las plantillas se nombran por `template_key` = `library_key`: las de correo
 * son las de la biblioteca de M08 (EMAIL_KEYS, se instalan al crear el flujo) y
 * las de WhatsApp (WHATSAPP_KEYS) las elige el cliente entre las aprobadas por
 * Meta. Sin WhatsApp conectado, LibraryAdapter quita los pasos de WhatsApp.
 */
final class FlowLibrary
{
    /**
     * Plantillas de WhatsApp que piden los flujos listos. Las de WhatsApp las
     * aprueba Meta en la cuenta de cada cliente, así que no vienen en la
     * biblioteca de M08: el flujo las pide por `library_key` y, si el espacio de
     * trabajo no tiene una, el constructor pide elegir la plantilla aprobada.
     */
    public const WHATSAPP_KEYS = [
        'wa_welcome_coupon', 'wa_abandoned_cart_1', 'wa_abandoned_cart_coupon', 'wa_abandoned_checkout',
        'wa_payment_pending_1', 'wa_payment_pending_2', 'wa_cod_confirm', 'wa_second_purchase',
        'wa_replenishment', 'wa_winback_coupon', 'wa_birthday', 'wa_vip_welcome', 'wa_points_expiring', 'wa_referral_invite',
        // Fase 5
        'wa_lead_reply', 'wa_renewal_reminder',
        // Seguimiento de leads fuera de la ventana de 24 h (opcional: sin ella sale por correo).
        'wa_lead_followup',
        // Flujos 21 a 35 (rediseño v2 §11.4): el texto propuesto está en la biblioteca de M08 (galería) y se envía
        // a revisión de Meta desde ahí; el flujo los usa cuando la plantilla está aprobada.
        'wa_quote_followup_1', 'wa_quote_followup_last', 'wa_appointment_confirm', 'wa_no_show_recovery', 'wa_post_service',
        'wa_periodic_service', 'wa_event_confirmation', 'wa_event_reminder', 'wa_enrollment_info', 'wa_enrollment_deadline',
        'wa_property_interest', 'wa_property_visit', 'wa_lost_lead_revival', 'wa_new_client_welcome', 'wa_services_reactivation',
        'wa_delivery_check', 'wa_first_purchase_anniversary',
    ];

    /** Plantillas de correo de la biblioteca de M08 (TemplateLibrary) que usan los flujos listos. */
    public const EMAIL_KEYS = [
        'welcome_1', 'welcome_2', 'welcome_3', 'abandoned_cart_1', 'abandoned_cart_2', 'abandoned_checkout',
        'browse_abandonment', 'payment_pending_1', 'payment_pending_2', 'cod_confirm_email', 'post_purchase',
        'second_purchase', 'replenishment', 'winback_1', 'winback_2', 'birthday', 'team_alert',
        'vip_welcome', 'points_expiring', 'referral_invite',
        // Fase 5
        'lead_followup_1', 'lead_followup_2', 'lead_followup_3', 'renewal_reminder',
        // Flujos 21 a 35
        'lead_nurture_1', 'lead_nurture_2', 'lead_nurture_3', 'lead_nurture_4', 'quote_followup_1', 'quote_followup_2', 'quote_followup_3',
        'appointment_confirm', 'no_show_recovery', 'post_service', 'periodic_service', 'event_confirmation', 'event_reminder', 'event_recording',
        'enrollment_info', 'enrollment_testimonials', 'enrollment_deadline', 'property_interest', 'property_similar', 'property_visit',
        'lost_lead_revival', 'new_client_welcome', 'services_reactivation', 'cross_sell', 'delivery_check', 'first_purchase_anniversary',
    ];

    /** Todas las claves de plantilla que usan los flujos listos. */
    public const TEMPLATE_KEYS = [...self::WHATSAPP_KEYS, ...self::EMAIL_KEYS];

    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::welcome(),
            self::abandonedCart(),
            self::abandonedCheckout(),
            self::browseAbandonment(),
            self::paymentPending(),
            self::codConfirm(),
            self::postPurchase(),
            self::module(8, 'review_request', 'Pedir reseña', 'Entrega + N días según la categoría: WhatsApp o correo con estrellas en 1 toque y recompensa por foto.', 'resenas?tab=ajustes'),
            self::secondPurchase(),
            self::replenishment(),
            self::winback(),
            self::vipWelcome(),
            self::birthday(),
            self::module(14, 'back_in_stock', 'Volvió el stock / bajó de precio', 'Aviso inmediato a quien lo pidió, con tope de frecuencia.', 'en-el-sitio/avisos'),
            self::pointsExpiring(),
            self::referralInvite(),
            self::module(17, 'nps', 'NPS y detractores', '14 días después de la entrega: encuesta; con nota de 6 o menos, alerta al dueño y una tarea.', 'resenas?tab=nps'),
            self::serviceLead(),
            self::module(19, 'appointment_reminder', 'Recordatorio de cita', '24 h y 2 h antes de la cita, con «Confirmo» y «Reprogramar» por WhatsApp. Lo envía la agenda: se configura en la página de reservas.', 'agenda?tab=pagina', 5),
            self::renewal(),
            // Rediseño v2 §11.4: negocios sin tienda (21 a 32) y tienda (33 a 35).
            self::leadNurture(),
            self::quoteFollowup(),
            self::appointmentConfirmation(),
            self::noShowRecovery(),
            self::postService(),
            self::periodicService(),
            self::eventRegistration(),
            self::enrollmentFollowup(),
            self::propertyInterest(),
            self::lostLeadRevival(),
            self::newClientWelcome(),
            self::servicesReactivation(),
            self::crossSell(),
            self::deliveryCheck(),
            self::firstPurchaseAnniversary(),
        ];
    }

    /**
     * Flujos listos que no están en la tabla de los 20 de M09 y que crean las estrategias
     * (docs/diseno/rediseno-v2.md §6): «Reactivar con IA» con el nodo `ai_reactivate` para
     * la base dormida (RFM «Hibernando»). No salen en la biblioteca de Flujos; se crean desde
     * Estrategias con CreateFlowFromLibrary, como cualquier otro.
     *
     * @return list<array<string, mixed>>
     */
    public static function extras(): array
    {
        return [self::aiReactivate()];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach ([...self::all(), ...self::extras()] as $flow) {
            if ($flow['key'] === $key) {
                return $flow;
            }
        }

        return null;
    }

    // ── Definiciones ────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function welcome(): array
    {
        $g = (new LibraryGraph)
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 7, 'prefix' => 'BIENVENIDA'])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_welcome_coupon'))
            ->node('em', 'send_email', self::send('welcome_1'))
            ->node('w1', 'wait', self::wait(2, 'days'))
            ->node('story', 'send_email', self::send('welcome_2'))
            ->node('w2', 'wait', self::wait(3, 'days'))
            ->node('best', 'send_email', self::send('welcome_3'))
            ->chain('trigger', 'cpn', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'w1')->edge('em', 'w1')
            ->chain('w1', 'story', 'w2', 'best');

        return self::flow(1, 'welcome', 'Bienvenida de suscriptor', 'Cupón inmediato, historia de la marca el día 2 y más vendidos el día 5.',
            ['type' => 'event', 'event' => 'consent.granted', 'filters' => [['prop' => 'source', 'operator' => 'starts_with', 'value' => 'widget:'], ['prop' => 'purpose', 'operator' => 'eq', 'value' => 'marketing']]],
            ['event' => 'order.paid'], ['mode' => 'once'], $g);
    }

    /** @return array<string, mixed> */
    private static function abandonedCart(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(1, 'hours'))
            ->node('wa1', 'send_whatsapp', self::send('wa_abandoned_cart_1'))
            ->node('w2', 'wait', self::wait(3, 'hours'))
            ->node('em1', 'send_email', self::send('abandoned_cart_1'))
            ->node('w3', 'wait', self::wait(20, 'hours'))
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 2, 'prefix' => 'VUELVE'])
            ->node('wa2', 'send_whatsapp', self::send('wa_abandoned_cart_coupon'))
            ->node('em2', 'send_email', self::send('abandoned_cart_2'))
            ->chain('trigger', 'w1', 'wa1')
            ->edge('wa1', 'w2')->edge('wa1', 'w2', 'failed')
            ->chain('w2', 'em1', 'w3', 'cpn', 'wa2')
            ->edge('wa2', 'em2', 'failed');

        return self::flow(2, 'abandoned_cart', 'Carrito abandonado', 'WhatsApp a la hora, correo a las 4 horas y WhatsApp a las 24 horas con un cupón opcional. Sale cuando compra.',
            ['type' => 'event', 'event' => 'cart.abandoned', 'filters' => []],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 7], $g);
    }

    /** @return array<string, mixed> */
    private static function abandonedCheckout(): array
    {
        $g = (new LibraryGraph)
            ->node('we', 'wait_event', ['event' => 'order.created', 'timeout' => ['value' => 30, 'unit' => 'minutes'], 'filters' => []])
            ->node('done', 'exit', [])
            ->node('wa1', 'send_whatsapp', self::send('wa_abandoned_checkout'))
            ->node('em1', 'send_email', self::send('abandoned_checkout'))
            ->node('w2', 'wait', self::wait(23, 'hours'))
            ->node('em2', 'send_email', self::send('abandoned_checkout'))
            ->chain('trigger', 'we')
            ->edge('we', 'done', 'event')->edge('we', 'wa1', 'timeout')
            ->edge('wa1', 'em1', 'failed')->edge('wa1', 'w2')
            ->chain('w2', 'em2');

        return self::flow(3, 'abandoned_checkout', 'Checkout abandonado', 'Si no hay pedido 30 minutos después de empezar el pago: WhatsApp (o correo si no se puede) y, al día siguiente, correo.',
            ['type' => 'event', 'event' => 'checkout.started', 'filters' => []],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 7], $g);
    }

    /** @return array<string, mixed> */
    private static function browseAbandonment(): array
    {
        $viewedTwice = ['op' => 'and', 'conditions' => [
            ['type' => 'event', 'event' => 'product.viewed', 'operator' => 'count_gte', 'value' => 2, 'window' => ['kind' => 'last_days', 'days' => 3]],
            ['type' => 'event', 'event' => 'cart.item_added', 'operator' => 'never', 'window' => ['kind' => 'last_days', 'days' => 3]],
        ]];
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(2, 'hours'))
            ->node('cond', 'condition', ['mode' => 'segment', 'definition' => $viewedTwice, 'exit_if_no' => true])
            ->node('em', 'send_email', self::send('browse_abandonment'))
            ->chain('trigger', 'w1', 'cond')->edge('cond', 'em', 'yes');

        return self::flow(4, 'browse_abandonment', 'Navegación abandonada', 'Vio productos 2 o más veces en 3 días sin agregar al carrito: correo con el producto y alternativas.',
            ['type' => 'event', 'event' => 'product.viewed', 'filters' => []],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 14], $g);
    }

    /** @return array<string, mixed> */
    private static function paymentPending(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(2, 'hours'))
            ->node('wa1', 'send_whatsapp', self::send('wa_payment_pending_1'))
            ->node('em1', 'send_email', self::send('payment_pending_1'))
            ->node('w2', 'wait', self::wait(22, 'hours'))
            ->node('wa2', 'send_whatsapp', self::send('wa_payment_pending_2'))
            ->node('em2', 'send_email', self::send('payment_pending_2'))
            ->chain('trigger', 'w1', 'wa1')
            ->edge('wa1', 'em1', 'failed')->edge('wa1', 'w2')->edge('em1', 'w2')
            ->chain('w2', 'wa2')->edge('wa2', 'em2', 'failed');

        return self::flow(5, 'payment_pending', 'Pago pendiente', 'Transferencia, PSE pendiente o Efecty: recordatorio con el enlace de pago a las 2 h y a las 24 h, antes de que se cancele.',
            ['type' => 'event', 'event' => 'order.payment_pending', 'filters' => []],
            ['event' => 'order.paid'], ['mode' => 'always'], $g);
    }

    /** @return array<string, mixed> */
    private static function codConfirm(): array
    {
        $g = (new LibraryGraph)
            ->node('ask', 'send_whatsapp', ['template_key' => 'wa_cod_confirm', 'kind' => 'utility', 'reclassify_reason' => 'Confirmar un pedido contraentrega que la persona acaba de hacer es transaccional (Ley 2300, art. 8).'])
            ->node('mail', 'send_email', ['template_key' => 'cod_confirm_email', 'kind' => 'utility', 'reclassify_reason' => 'Confirmar un pedido contraentrega que la persona acaba de hacer es transaccional (Ley 2300, art. 8).'])
            ->node('fail', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'No se pudo pedir por WhatsApp la confirmación de un pedido contraentrega; se envió por correo. Llama al cliente antes de despacharlo.', 'template_key' => 'team_alert'])
            ->node('wait', 'wait_event', ['event' => 'message.replied', 'timeout' => ['value' => 12, 'unit' => 'hours'], 'filters' => [['prop' => 'button_payload', 'operator' => 'in', 'value' => ['cod_confirm', 'cod_cancel']]]])
            ->node('answer', 'condition', ['mode' => 'context', 'path' => 'last_event.props.button_payload', 'operator' => 'eq', 'value' => 'cod_confirm'])
            ->node('ok', 'tag', ['add' => ['contraentrega_confirmada']])
            ->node('cancel', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'El cliente canceló su pedido contraentrega desde WhatsApp.', 'template_key' => 'team_alert'])
            ->node('late', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'Un pedido contraentrega lleva 12 horas sin confirmar. Llama al cliente antes de despacharlo.', 'template_key' => 'team_alert'])
            ->chain('trigger', 'ask')
            ->edge('ask', 'mail', 'failed')->edge('ask', 'wait')
            ->chain('mail', 'fail')
            ->edge('wait', 'answer', 'event')->edge('wait', 'late', 'timeout')
            ->edge('answer', 'ok', 'yes')->edge('answer', 'cancel', 'no');

        return self::flow(6, 'cod_confirm', 'Confirmar contraentrega', 'Plantilla de utilidad con «Confirmo» o «Cancelar»; si no responde en 12 h, alerta al dueño.',
            ['type' => 'event', 'event' => 'order.created', 'filters' => [['prop' => 'order.payment_method.id', 'operator' => 'in', 'value' => ['cod']]]],
            null, ['mode' => 'always'], $g);
    }

    /** @return array<string, mixed> */
    private static function postPurchase(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(1, 'days'))
            ->node('em', 'send_email', self::send('post_purchase'))
            ->chain('trigger', 'w1', 'em');

        return self::flow(7, 'post_purchase', 'Poscompra', 'Gracias y cómo usar el producto, un día después de completar el pedido.',
            ['type' => 'event', 'event' => 'order.completed', 'filters' => []],
            null, ['mode' => 'always'], $g);
    }

    /** @return array<string, mixed> */
    private static function secondPurchase(): array
    {
        $g = (new LibraryGraph)
            ->node('em', 'send_email', self::send('second_purchase'))
            ->node('w1', 'wait', self::wait(4, 'days'))
            ->node('wa', 'send_whatsapp', self::send('wa_second_purchase'))
            ->chain('trigger', 'em', 'w1', 'wa');

        return self::flow(9, 'second_purchase', 'Segunda compra', 'Clientes con un pedido que ya superaron el tiempo mediano hasta la segunda compra: recomendación del siguiente producto.',
            ['type' => 'date', 'attribute' => 'second_purchase_due', 'offset_days' => 0, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'once'], $g);
    }

    /** @return array<string, mixed> */
    private static function replenishment(): array
    {
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_replenishment'))
            ->node('em', 'send_email', self::send('replenishment'))
            ->chain('trigger', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(10, 'replenishment', 'Reposición', '5 días antes de la fecha esperada de su próxima compra: «se te está acabando».',
            ['type' => 'date', 'attribute' => 'expected_next_order_at', 'offset_days' => -5, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 20], $g);
    }

    /** @return array<string, mixed> */
    private static function winback(): array
    {
        $g = (new LibraryGraph)
            ->node('em1', 'send_email', self::send('winback_1'))
            ->node('w1', 'wait', self::wait(3, 'days'))
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '15', 'expires_in_days' => 7, 'prefix' => 'TEEXTRANAMOS'])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_winback_coupon'))
            ->node('em2', 'send_email', self::send('winback_2'))
            ->chain('trigger', 'em1', 'w1', 'cpn', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em2', 'email');

        return self::flow(11, 'winback', 'Recuperación', 'Entra en «En riesgo»: mensaje personal y, si no vuelve, un incentivo.',
            ['type' => 'segment', 'system_key' => 'rfm_at_risk', 'on' => 'entered'],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 90], $g);
    }

    /** @return array<string, mixed> */
    private static function birthday(): array
    {
        $g = (new LibraryGraph)
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 7, 'prefix' => 'CUMPLE'])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_birthday'))
            ->node('em', 'send_email', self::send('birthday'))
            ->chain('trigger', 'cpn', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(13, 'birthday', 'Cumpleaños', 'Regalo el día del cumpleaños (cupón), a una hora legal.',
            ['type' => 'date', 'attribute' => 'birthday', 'offset_days' => 0, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 300], $g);
    }

    /** @return array<string, mixed> */
    private static function vipWelcome(): array
    {
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_vip_welcome'))
            ->node('em', 'send_email', self::send('vip_welcome'))
            ->chain('trigger', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(12, 'vip_welcome', 'VIP', 'Sube de nivel: bienvenida al nivel, beneficios y acceso anticipado.',
            ['type' => 'event', 'event' => 'tier.changed', 'filters' => [['prop' => 'direction', 'operator' => 'eq', 'value' => 'up']]],
            null, ['mode' => 'always'], $g, 3);
    }

    /**
     * M13 deja en el contacto la fecha del próximo vencimiento de puntos
     * (`points_expiring_at`) y cuántos vencen ese día; el disparador por fecha avisa
     * 15 días antes. Canjear sale del flujo.
     *
     * @return array<string, mixed>
     */
    private static function pointsExpiring(): array
    {
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_points_expiring'))
            ->node('em', 'send_email', self::send('points_expiring'))
            ->chain('trigger', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(15, 'points_expiring', 'Puntos por vencer', 'Recordatorio 15 días antes con el saldo y sugerencias de canje.',
            ['type' => 'date', 'attribute' => 'points_expiring_at', 'offset_days' => -15, 'hour' => 10],
            ['event' => 'points.redeemed'], ['mode' => 'every_days', 'days' => 20], $g, 3);
    }

    /**
     * Promotores del NPS (M15): enlace de referido (M14) para compartir. El correo
     * lleva `enlaces.referido`, que solo existe con el programa de referidos activo.
     *
     * @return array<string, mixed>
     */
    private static function referralInvite(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(1, 'hours'))
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_referral_invite'))
            ->node('em', 'send_email', self::send('referral_invite'))
            ->chain('trigger', 'w1', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(16, 'referral_invite', 'Invitar a referir', 'NPS de 9 o 10: una hora después, el enlace de referido para compartir por WhatsApp.',
            ['type' => 'event', 'event' => 'nps.answered', 'filters' => [['prop' => 'category', 'operator' => 'eq', 'value' => 'promoter']]],
            ['event' => 'referral.shared'], ['mode' => 'every_days', 'days' => 90], $g, 3);
    }

    /**
     * M18: un lead nuevo de un formulario o de WhatsApp (los de reservas, cotizador, API
     * o manuales ya tienen su propio camino). Respuesta al instante por WhatsApp o correo
     * y **seguimiento con IA los días 2, 10, 20 y 40** (`lead_followup`: la IA escribe
     * cada toque con lo que pidió el lead; texto dentro de la ventana de 24 h, plantilla
     * fuera de ella, correo si WhatsApp no se puede). Cada espera es «esperar a que
     * escriba por WhatsApp»: si el cliente responde, el run sale y lo atiende el vendedor
     * («Hoy»); la meta es cualquier cambio de etapa (avanza, se gana o se pierde). El
     * aviso al vendedor no va aquí: lo envía el módulo de leads (AlertSeller), una vez por
     * lead. Todo es marketing: el seguimiento comercial es ambiguo y por defecto se trata
     * como marketing (cumplimiento.md §4.1), con consentimiento, ventana legal y frecuencia.
     *
     * @return array<string, mixed>
     */
    private static function serviceLead(): array
    {
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_lead_reply'))
            ->node('em1', 'send_email', self::send('lead_followup_1'))
            ->node('w1', 'wait_event', self::untilReply(2))
            ->node('f1', 'lead_followup', ['channels' => ['whatsapp', 'email'], 'step' => 1])
            ->node('w2', 'wait_event', self::untilReply(8))
            ->node('f2', 'lead_followup', ['channels' => ['whatsapp', 'email'], 'step' => 2])
            ->node('w3', 'wait_event', self::untilReply(10))
            ->node('f3', 'lead_followup', ['channels' => ['whatsapp', 'email'], 'step' => 3])
            ->node('w4', 'wait_event', self::untilReply(20))
            ->node('f4', 'lead_followup', ['channels' => ['whatsapp', 'email'], 'step' => 4])
            ->node('replied', 'exit', [])
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em1', 'email')
            ->edge('wa', 'w1')->edge('wa', 'em1', 'failed')->edge('em1', 'w1')
            ->edge('w1', 'f1', 'timeout')->edge('w1', 'replied', 'event')
            ->edge('f1', 'w2')->edge('w2', 'f2', 'timeout')->edge('w2', 'replied', 'event')
            ->edge('f2', 'w3')->edge('w3', 'f3', 'timeout')->edge('w3', 'replied', 'event')
            ->edge('f3', 'w4')->edge('w4', 'f4', 'timeout')->edge('w4', 'replied', 'event');

        return self::flow(18, 'service_lead', 'Lead de servicios', 'Formulario o WhatsApp entrante: respuesta al instante y seguimiento con IA los días 2, 10, 20 y 40 con lo que pidió el lead. Se detiene si el cliente responde por WhatsApp o si el lead cambia de etapa (avanza, se gana o se pierde).',
            ['type' => 'event', 'event' => 'lead.created', 'filters' => [['prop' => 'origin', 'operator' => 'in', 'value' => ['form', 'whatsapp']]]],
            ['event' => 'lead.stage_changed'],
            ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * Renovación: 15 días antes de `contacts.renewal_at` (llega por importación, por la API
     * o desde el perfil), recordatorio con la oferta y, si un día antes de la fecha todavía
     * no renovó, aviso al equipo. Renovar se registra como un lead ganado (`lead.won`); una
     * tienda con WooCommerce Subscriptions puede cambiar el objetivo a `subscription.renewed`.
     *
     * @return array<string, mixed>
     */
    private static function renewal(): array
    {
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_renewal_reminder'))
            ->node('em', 'send_email', self::send('renewal_reminder'))
            ->node('w1', 'wait', self::wait(14, 'days'))
            ->node('team', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'Un cliente no ha renovado; la renovación es mañana. Llámalo antes de que venza.', 'template_key' => 'team_alert'])
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'em', 'failed')->edge('wa', 'w1')->edge('em', 'w1')
            ->chain('w1', 'team');

        return self::flow(20, 'renewal', 'Renovación', '15 días antes de la fecha de renovación: recordatorio con la oferta y, si un día antes no ha renovado, aviso al equipo. Sale cuando renueva (lead ganado).',
            ['type' => 'date', 'attribute' => 'renewal_at', 'offset_days' => -15, 'hour' => 10],
            ['event' => 'lead.won'], ['mode' => 'every_days', 'days' => 300], $g, 5);
    }

    // ── Flujos 21 a 35 (rediseño v2 §11.4) ─────────────────────────────────

    /**
     * 21 Nutrición por correo: 4 correos de valor en 3 semanas (días 1, 6, 12 y 20) a los leads fríos o tibios de un
     * formulario o un chat. Antes de cada correo revisa que siga sin avanzar: sale si ya no tiene un lead abierto, si
     * su lead pasó de las primeras etapas, si tiene una cita próxima o si recibió una cotización en el último mes
     * (`stillNurturing`). La meta es agendar (`appointment.booked`). Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function leadNurture(): array
    {
        $g = (new LibraryGraph)->node('done', 'exit', []);
        $prev = 'trigger';
        foreach ([1, 5, 6, 8] as $i => $wait) {
            $n = $i + 1;
            $g->node("w{$n}", 'wait', self::wait($wait, 'days'))
                ->node("c{$n}", 'condition', ['mode' => 'segment', 'definition' => self::stillNurturing()])
                ->node("e{$n}", 'send_email', self::send("lead_nurture_{$n}"))
                ->edge($prev, "w{$n}")->edge("w{$n}", "c{$n}")->edge("c{$n}", "e{$n}", 'yes')->edge("c{$n}", 'done', 'no');
            $prev = "e{$n}";
        }

        return self::flow(21, 'lead_nurture', 'Nutrición de leads', 'Correos de valor en 3 semanas a los leads que todavía no están listos: cómo trabajas, preguntas frecuentes, casos reales y la invitación a agendar. Sale al agendar, cotizar o avanzar de etapa.',
            ['type' => 'event', 'event' => 'lead.created', 'filters' => [
                ['prop' => 'origin', 'operator' => 'not_in', 'value' => ['booking', 'quote']],
                ['prop' => 'temperature', 'operator' => 'in', 'value' => ['cold', 'warm']],
            ]],
            ['event' => 'appointment.booked'], ['mode' => 'every_days', 'days' => 60], $g, 5);
    }

    /**
     * 22 Cotización sin respuesta: dispara con `quote.sent` (v8: el cotizador mostró el precio o el lead entró a una
     * etapa de propuesta, cotizado u oferta). WhatsApp al día 2 (o correo si no se puede), correo al 5 y último aviso
     * al 10; después, una llamada en «Hoy» para el vendedor del lead. Cada espera es «hasta que escriba por
     * WhatsApp»: si responde, sale y lo atiende el vendedor. Antes de cada toque revisa que el lead siga abierto. La
     * meta es ganar el lead. Es marketing (seguimiento comercial, cumplimiento.md §4.1).
     *
     * @return array<string, mixed>
     */
    private static function quoteFollowup(): array
    {
        $open = ['mode' => 'segment', 'definition' => self::openLead()];
        $g = (new LibraryGraph)
            ->node('w1', 'wait_event', self::untilReply(2))
            ->node('c1', 'condition', $open)
            ->node('wa1', 'send_whatsapp', self::send('wa_quote_followup_1'))
            ->node('em1', 'send_email', self::send('quote_followup_1'))
            ->node('w2', 'wait_event', self::untilReply(3))
            ->node('c2', 'condition', $open)
            ->node('em2', 'send_email', self::send('quote_followup_2'))
            ->node('w3', 'wait_event', self::untilReply(5))
            ->node('c3', 'condition', $open)
            ->node('wa3', 'send_whatsapp', self::send('wa_quote_followup_last'))
            ->node('em3', 'send_email', self::send('quote_followup_3'))
            ->node('task', 'crm_task', ['title' => 'La cotización lleva 10 días sin respuesta: llama al cliente.', 'kind' => 'call', 'due_in' => ['value' => 1, 'unit' => 'days'], 'assign' => 'lead_owner'])
            ->node('replied', 'exit', [])
            ->node('closed', 'exit', [])
            ->chain('trigger', 'w1')
            ->edge('w1', 'replied', 'event')->edge('w1', 'c1', 'timeout')
            ->edge('c1', 'wa1', 'yes')->edge('c1', 'closed', 'no')
            ->edge('wa1', 'w2')->edge('wa1', 'em1', 'failed')->edge('em1', 'w2')
            ->edge('w2', 'replied', 'event')->edge('w2', 'c2', 'timeout')
            ->edge('c2', 'em2', 'yes')->edge('c2', 'closed', 'no')
            ->edge('em2', 'w3')
            ->edge('w3', 'replied', 'event')->edge('w3', 'c3', 'timeout')
            ->edge('c3', 'wa3', 'yes')->edge('c3', 'closed', 'no')
            ->edge('wa3', 'task')->edge('wa3', 'em3', 'failed')->edge('em3', 'task');

        return self::flow(22, 'quote_followup', 'Cotización sin respuesta', 'Cotización o propuesta enviada sin respuesta: WhatsApp al día 2, correo al 5 y último aviso al 10; después, una llamada para el vendedor en «Hoy». Sale si el cliente responde por WhatsApp o si el lead se gana o se pierde.',
            ['type' => 'event', 'event' => 'quote.sent', 'filters' => []],
            ['event' => 'lead.won'], ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * 23 Confirmación de cita: 48 horas antes de la cita (se mueve sola si la persona la reprograma; sale si se
     * cancela) pide confirmar con los botones «Confirmo» y «Reprogramar» (`appt_confirm` y `appt_reschedule`, los
     * que ya entiende la agenda) y, si no sale por WhatsApp, por correo. Si 24 horas después todavía no confirmó y
     * tiene una cita próxima, avisa al equipo. Las citas agendadas con menos de 49 horas no la piden (el
     * recordatorio de 24 h de la agenda ya lo hace). La meta es `appointment.confirmed` (v8). Es de utilidad: es la
     * cita que la persona agendó (cumplimiento.md §4.1).
     *
     * @return array<string, mixed>
     */
    private static function appointmentConfirmation(): array
    {
        $reason = 'Pide confirmar la cita que la persona agendó: es información de su propia cita, sin oferta (Ley 2300, art. 8).';
        $g = (new LibraryGraph)
            ->node('far', 'condition', ['mode' => 'date', 'anchor' => 'appointment', 'offset' => ['value' => 49, 'unit' => 'hours']])
            ->node('w1', 'wait', ['mode' => 'until_date', 'anchor' => 'appointment', 'offset' => ['value' => 48, 'unit' => 'hours'], 'direction' => 'before', 'if_past' => 'continue'])
            ->node('ask', 'send_whatsapp', ['template_key' => 'wa_appointment_confirm', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('mail', 'send_email', ['template_key' => 'appointment_confirm', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('w2', 'wait', self::wait(24, 'hours'))
            ->node('still', 'condition', ['mode' => 'segment', 'definition' => ['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'is_true']]]])
            ->node('team', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'Un cliente no ha confirmado su cita y falta menos de un día. Llámalo para confirmarla o liberar el horario.', 'template_key' => 'team_alert'])
            ->node('done', 'exit', [])
            ->chain('trigger', 'far')
            ->edge('far', 'w1', 'yes')->edge('far', 'done', 'no')
            ->chain('w1', 'ask')
            ->edge('ask', 'w2')->edge('ask', 'mail', 'failed')->edge('mail', 'w2')
            ->chain('w2', 'still')
            ->edge('still', 'team', 'yes')->edge('still', 'done', 'no');

        return self::flow(23, 'appointment_confirmation', 'Confirmación de cita', 'Pide confirmar la cita 48 horas antes con «Confirmo» y «Reprogramar»; si no confirma en un día, avisa al equipo. Sigue la hora nueva si la reprograma y sale si la cancela.',
            ['type' => 'event', 'event' => 'appointment.booked', 'filters' => []],
            ['event' => 'appointment.confirmed'], ['mode' => 'always'], $g, 5);
    }

    /**
     * 24 No asistió: el mismo día (2 horas después, dentro de la ventana legal) un mensaje amable con el enlace para
     * reagendar; si dos días después no reagendó, una llamada en «Hoy». La meta es volver a agendar. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function noShowRecovery(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(2, 'hours'))
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_no_show_recovery'))
            ->node('em', 'send_email', self::send('no_show_recovery'))
            ->node('w2', 'wait', self::wait(2, 'days'))
            ->node('task', 'crm_task', ['title' => 'No asistió a su cita y no ha reagendado: llámalo.', 'kind' => 'call', 'due_in' => ['value' => 1, 'unit' => 'days'], 'assign' => 'lead_owner'])
            ->chain('trigger', 'w1', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'w2')->edge('wa', 'em', 'failed')->edge('em', 'w2')
            ->chain('w2', 'task');

        return self::flow(24, 'no_show_recovery', 'No asistió', 'No llegó a la cita: el mismo día, un mensaje amable con el enlace para reagendar; si en dos días no reagenda, una llamada para el equipo en «Hoy».',
            ['type' => 'event', 'event' => 'appointment.no_show', 'filters' => []],
            ['event' => 'appointment.booked'], ['mode' => 'every_days', 'days' => 7], $g, 5);
    }

    /**
     * 25 Después del servicio: al día siguiente de la cita cumplida, cómo le fue, el enlace para la reseña en
     * Google (Reseñas → Ajustes) y, en el correo, el enlace de referido si el programa está activo. Es marketing
     * (reseñas y referidos, cumplimiento.md §4.1).
     *
     * @return array<string, mixed>
     */
    private static function postService(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(1, 'days'))
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_post_service'))
            ->node('em', 'send_email', self::send('post_service'))
            ->chain('trigger', 'w1', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(25, 'post_service', 'Después del servicio', 'Al día siguiente del servicio: cómo le fue, la reseña en Google y el enlace de referido.',
            ['type' => 'event', 'event' => 'appointment.completed', 'filters' => []],
            null, ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * 26 Control o mantenimiento periódico: 6 meses después de la cita cumplida (la espera se cambia en el
     * constructor), si no tiene otra cita próxima, el recordatorio con el enlace para agendar. Agendar es la meta y
     * termina el ciclo: la siguiente cita cumplida empieza otro. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function periodicService(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(180, 'days'))
            ->node('c1', 'condition', ['mode' => 'segment', 'definition' => ['op' => 'and', 'conditions' => [['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'is_false']]]])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_periodic_service'))
            ->node('em', 'send_email', self::send('periodic_service'))
            ->node('done', 'exit', [])
            ->chain('trigger', 'w1', 'c1')
            ->edge('c1', 'split', 'yes')->edge('c1', 'done', 'no')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(26, 'periodic_service', 'Control o mantenimiento periódico', 'Cada 6 meses después del servicio (lo cambias en la espera): recordatorio con el enlace para agendar el control o el mantenimiento.',
            ['type' => 'event', 'event' => 'appointment.completed', 'filters' => []],
            ['event' => 'appointment.booked'], ['mode' => 'every_days', 'days' => 60], $g, 5);
    }

    /**
     * 27 Inscripción a evento o webinar: un formulario con el campo `event_date` (fecha y hora del evento; también
     * `event_name`, `event_url` y `recording_url`) dispara la confirmación al instante, el recordatorio un día antes
     * y una hora antes (solo si todavía falta) y, 3 horas después, la grabación. La confirmación y los recordatorios
     * son de utilidad (la inscripción que hizo la persona); la grabación es marketing.
     *
     * @return array<string, mixed>
     */
    private static function eventRegistration(): array
    {
        $reason = 'Confirma y recuerda la inscripción que la persona acaba de hacer, sin oferta (Ley 2300, art. 8).';
        $date = ['anchor' => 'prop', 'path' => 'fields.event_date'];
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', ['template_key' => 'wa_event_confirmation', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('em', 'send_email', ['template_key' => 'event_confirmation', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('c1', 'condition', ['mode' => 'date'] + $date + ['offset' => ['value' => 26, 'unit' => 'hours']])
            ->node('w1', 'wait', ['mode' => 'until_date'] + $date + ['offset' => ['value' => 24, 'unit' => 'hours'], 'direction' => 'before', 'if_past' => 'continue'])
            ->node('r1', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('r1wa', 'send_whatsapp', ['template_key' => 'wa_event_reminder', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('r1em', 'send_email', ['template_key' => 'event_reminder', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('c2', 'condition', ['mode' => 'date'] + $date + ['offset' => ['value' => 90, 'unit' => 'minutes']])
            ->node('w2', 'wait', ['mode' => 'until_date'] + $date + ['offset' => ['value' => 1, 'unit' => 'hours'], 'direction' => 'before', 'if_past' => 'continue'])
            ->node('r2wa', 'send_whatsapp', ['template_key' => 'wa_event_reminder', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('r2em', 'send_email', ['template_key' => 'event_reminder', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('w3', 'wait', ['mode' => 'until_date'] + $date + ['offset' => ['value' => 3, 'unit' => 'hours'], 'direction' => 'after', 'if_past' => 'exit'])
            ->node('rec', 'send_email', self::send('event_recording'))
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'c1')->edge('wa', 'em', 'failed')->edge('em', 'c1')
            ->edge('c1', 'w1', 'yes')->edge('c1', 'c2', 'no')
            ->chain('w1', 'r1')
            ->edge('r1', 'r1wa', 'whatsapp')->edge('r1', 'r1em', 'email')
            ->edge('r1wa', 'c2')->edge('r1wa', 'r1em', 'failed')->edge('r1em', 'c2')
            ->edge('c2', 'w2', 'yes')->edge('c2', 'w3', 'no')
            ->chain('w2', 'r2wa')
            ->edge('r2wa', 'w3')->edge('r2wa', 'r2em', 'failed')->edge('r2em', 'w3')
            ->chain('w3', 'rec');

        return self::flow(27, 'event_registration', 'Inscripción a evento o webinar', 'Se inscribe con un formulario que trae la fecha del evento (campo event_date): confirmación al instante, recordatorio un día y una hora antes, y la grabación después.',
            ['type' => 'event', 'event' => 'form.submitted', 'filters' => [['prop' => 'fields.event_date', 'operator' => 'exists']]],
            null, ['mode' => 'always'], $g, 5);
    }

    /**
     * 28 Interesado en un curso: al instante la información (WhatsApp con «Agendar clase» o correo); al día 3, los
     * testimonios; al 7, la fecha límite de inscripción. Cada espera es «hasta que escriba por WhatsApp» y cada toque
     * revisa que el lead siga abierto. La meta es la inscripción (lead ganado). Se excluye con el 18: el contacto
     * recibe una sola secuencia. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function enrollmentFollowup(): array
    {
        $open = ['mode' => 'segment', 'definition' => self::openLead()];
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_enrollment_info'))
            ->node('em', 'send_email', self::send('enrollment_info'))
            ->node('w1', 'wait_event', self::untilReply(3))
            ->node('c1', 'condition', $open)
            ->node('em2', 'send_email', self::send('enrollment_testimonials'))
            ->node('w2', 'wait_event', self::untilReply(4))
            ->node('c2', 'condition', $open)
            ->node('wa3', 'send_whatsapp', self::send('wa_enrollment_deadline'))
            ->node('em3', 'send_email', self::send('enrollment_deadline'))
            ->node('replied', 'exit', [])
            ->node('closed', 'exit', [])
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'w1')->edge('wa', 'em', 'failed')->edge('em', 'w1')
            ->edge('w1', 'replied', 'event')->edge('w1', 'c1', 'timeout')
            ->edge('c1', 'em2', 'yes')->edge('c1', 'closed', 'no')
            ->edge('em2', 'w2')
            ->edge('w2', 'replied', 'event')->edge('w2', 'c2', 'timeout')
            ->edge('c2', 'wa3', 'yes')->edge('c2', 'closed', 'no')
            ->edge('wa3', 'em3', 'failed');

        return self::flow(28, 'enrollment_followup', 'Interesado en un curso', 'Pidió información de un curso: la información al instante, testimonios al día 3 y la fecha límite de inscripción al 7. Sale si responde por WhatsApp o se inscribe.',
            ['type' => 'event', 'event' => 'lead.created', 'filters' => [['prop' => 'origin', 'operator' => 'in', 'value' => ['form', 'whatsapp', 'messenger', 'instagram']]]],
            ['event' => 'lead.won'], ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * 29 Interés en una propiedad: al instante, gracias y «Agendar visita»; al día 2, opciones parecidas; al 7, la
     * invitación a la visita; una semana después, una llamada en «Hoy». Cada toque revisa que siga con el lead
     * abierto y sin visita agendada. La meta es la visita agendada. Se excluye con el 18. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function propertyInterest(): array
    {
        $waiting = ['mode' => 'segment', 'definition' => ['op' => 'and', 'conditions' => [...self::openLead()['conditions'], ['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'is_false']]]];
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_property_interest'))
            ->node('em', 'send_email', self::send('property_interest'))
            ->node('w1', 'wait_event', self::untilReply(2))
            ->node('c1', 'condition', $waiting)
            ->node('em2', 'send_email', self::send('property_similar'))
            ->node('w2', 'wait_event', self::untilReply(5))
            ->node('c2', 'condition', $waiting)
            ->node('wa3', 'send_whatsapp', self::send('wa_property_visit'))
            ->node('em3', 'send_email', self::send('property_visit'))
            ->node('w3', 'wait', self::wait(7, 'days'))
            ->node('task', 'crm_task', ['title' => 'Lleva dos semanas sin agendar la visita: llama al cliente.', 'kind' => 'call', 'due_in' => ['value' => 1, 'unit' => 'days'], 'assign' => 'lead_owner'])
            ->node('replied', 'exit', [])
            ->node('closed', 'exit', [])
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')
            ->edge('wa', 'w1')->edge('wa', 'em', 'failed')->edge('em', 'w1')
            ->edge('w1', 'replied', 'event')->edge('w1', 'c1', 'timeout')
            ->edge('c1', 'em2', 'yes')->edge('c1', 'closed', 'no')
            ->edge('em2', 'w2')
            ->edge('w2', 'replied', 'event')->edge('w2', 'c2', 'timeout')
            ->edge('c2', 'wa3', 'yes')->edge('c2', 'closed', 'no')
            ->edge('wa3', 'w3')->edge('wa3', 'em3', 'failed')->edge('em3', 'w3')
            ->chain('w3', 'task');

        return self::flow(29, 'property_interest', 'Interés en una propiedad', 'Preguntó por una propiedad: gracias y «Agendar visita» al instante, opciones parecidas al día 2, la invitación a la visita al 7 y una llamada para el asesor si no agenda.',
            ['type' => 'event', 'event' => 'lead.created', 'filters' => [['prop' => 'origin', 'operator' => 'in', 'value' => ['form', 'whatsapp', 'messenger', 'instagram']]]],
            ['event' => 'appointment.booked'], ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * 30 Lead perdido hace 90 días: volver a intentar con una novedad, si no volvió por su cuenta (sin lead abierto).
     * La meta es un lead nuevo (vuelve a escribir o a dejar sus datos). Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function lostLeadRevival(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(90, 'days'))
            ->node('c1', 'condition', ['mode' => 'segment', 'definition' => ['op' => 'and', 'conditions' => [['type' => 'lead', 'field' => 'has_open_lead', 'operator' => 'is_false']]]])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_lost_lead_revival'))
            ->node('em', 'send_email', self::send('lost_lead_revival'))
            ->node('done', 'exit', [])
            ->chain('trigger', 'w1', 'c1')
            ->edge('c1', 'split', 'yes')->edge('c1', 'done', 'no')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(30, 'lost_lead_revival', 'Lead perdido: volver a intentar', '90 días después de perder un lead: un mensaje con una novedad para volver a hablar.',
            ['type' => 'event', 'event' => 'lead.lost', 'filters' => []],
            ['event' => 'lead.created'], ['mode' => 'every_days', 'days' => 180], $g, 5);
    }

    /**
     * 31 Bienvenida a un cliente nuevo: al ganar el lead, qué esperar, cómo contactar y los próximos pasos. De
     * utilidad: es información del servicio que acaba de contratar, sin oferta.
     *
     * @return array<string, mixed>
     */
    private static function newClientWelcome(): array
    {
        $reason = 'Bienvenida a quien acaba de contratar: qué esperar y los próximos pasos de su servicio, sin oferta (Ley 2300, art. 8).';
        $g = (new LibraryGraph)
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', ['template_key' => 'wa_new_client_welcome', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('em', 'send_email', ['template_key' => 'new_client_welcome', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->chain('trigger', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(31, 'new_client_welcome', 'Bienvenida a un cliente nuevo', 'Al cerrar la venta: qué esperar, cómo contactarte y los próximos pasos.',
            ['type' => 'event', 'event' => 'lead.won', 'filters' => []],
            null, ['mode' => 'always'], $g, 5);
    }

    /**
     * 32 Cliente sin servicio en N meses: 6 meses después de ganar el lead, si no volvió (ningún lead abierto, ninguno
     * ganado ni cita en los últimos 150 días, ni cita próxima), un mensaje personal con una razón para volver. La meta
     * es un lead nuevo. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function servicesReactivation(): array
    {
        $active = ['op' => 'or', 'conditions' => [
            ['type' => 'lead', 'field' => 'has_open_lead', 'operator' => 'is_true'],
            ['type' => 'lead', 'field' => 'won_at', 'operator' => 'within_last_days', 'value' => 150],
            ['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'is_true'],
            ['type' => 'service', 'field' => 'starts_at', 'operator' => 'within_last_days', 'value' => 150],
        ]];
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(180, 'days'))
            ->node('c1', 'condition', ['mode' => 'segment', 'definition' => $active])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_services_reactivation'))
            ->node('em', 'send_email', self::send('services_reactivation'))
            ->node('done', 'exit', [])
            ->chain('trigger', 'w1', 'c1')
            ->edge('c1', 'done', 'yes')->edge('c1', 'split', 'no')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(32, 'services_reactivation', 'Cliente sin servicio', '6 meses sin volver (lo cambias en la espera): un mensaje personal con una razón para volver.',
            ['type' => 'event', 'event' => 'lead.won', 'filters' => []],
            ['event' => 'lead.created'], ['mode' => 'every_days', 'days' => 150], $g, 5);
    }

    /**
     * 33 Venta cruzada: 7 días después de un pedido pagado, un correo con lo que combina con lo que compró (las
     * recomendaciones «para ti»: afinidad por categoría, sin lo que ya tiene). Sale si vuelve a comprar.
     *
     * @return array<string, mixed>
     */
    private static function crossSell(): array
    {
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(7, 'days'))
            ->node('em', 'send_email', self::send('cross_sell'))
            ->chain('trigger', 'w1', 'em');

        return self::flow(33, 'cross_sell', 'Venta cruzada', '7 días después de la compra: complementos de lo que se llevó. Sale si vuelve a comprar.',
            ['type' => 'event', 'event' => 'order.paid', 'filters' => []],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 30], $g, 5);
    }

    /**
     * 34 ¿Llegó bien?: 2 días después de entregado (`order.completed`, la entrega en WooCommerce), pregunta si llegó
     * bien con «Todo bien» y «Tuve un problema»; un problema avisa al dueño. De utilidad: es el seguimiento de su
     * pedido, sin oferta.
     *
     * @return array<string, mixed>
     */
    private static function deliveryCheck(): array
    {
        $reason = 'Pregunta si llegó bien el pedido que la persona compró: seguimiento de su transacción, sin oferta (Ley 2300, art. 8).';
        $g = (new LibraryGraph)
            ->node('w1', 'wait', self::wait(2, 'days'))
            ->node('ask', 'send_whatsapp', ['template_key' => 'wa_delivery_check', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('mail', 'send_email', ['template_key' => 'delivery_check', 'kind' => 'utility', 'reclassify_reason' => $reason])
            ->node('wait', 'wait_event', ['event' => 'message.replied', 'timeout' => ['value' => 3, 'unit' => 'days'], 'filters' => [['prop' => 'button_payload', 'operator' => 'in', 'value' => ['delivery_ok', 'delivery_problem']]]])
            ->node('answer', 'condition', ['mode' => 'context', 'path' => 'last_event.props.button_payload', 'operator' => 'eq', 'value' => 'delivery_problem'])
            ->node('team', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'Un cliente dijo por WhatsApp que tuvo un problema con su pedido. Escríbele hoy para resolverlo.', 'template_key' => 'team_alert'])
            ->node('ok', 'tag', ['add' => ['entrega_ok']])
            ->node('done', 'exit', [])
            ->chain('trigger', 'w1', 'ask')
            ->edge('ask', 'wait')->edge('ask', 'mail', 'failed')
            ->edge('wait', 'answer', 'event')->edge('wait', 'done', 'timeout')
            ->edge('answer', 'team', 'yes')->edge('answer', 'ok', 'no');

        return self::flow(34, 'delivery_check', '¿Llegó bien?', '2 días después de entregado: pregunta si llegó bien y, si hubo un problema, avisa al dueño.',
            ['type' => 'event', 'event' => 'order.completed', 'filters' => []],
            null, ['mode' => 'always'], $g, 5);
    }

    /**
     * 35 Aniversario de la primera compra: el día que se cumple un año (o más) de la primera compra, un detalle (cupón
     * del 10 % por 7 días). La meta es la compra. Es marketing.
     *
     * @return array<string, mixed>
     */
    private static function firstPurchaseAnniversary(): array
    {
        $g = (new LibraryGraph)
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 7, 'prefix' => 'ANIVERSARIO'])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_first_purchase_anniversary'))
            ->node('em', 'send_email', self::send('first_purchase_anniversary'))
            ->chain('trigger', 'cpn', 'split')
            ->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email')->edge('wa', 'em', 'failed');

        return self::flow(35, 'first_purchase_anniversary', 'Aniversario de la primera compra', 'El día que cumple un año de su primera compra: un detalle con cupón, a una hora legal.',
            ['type' => 'date', 'attribute' => 'first_order_anniversary', 'offset_days' => 0, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 300], $g, 5);
    }

    /**
     * «Tiene un lead abierto» (condición de segmento evaluada para el contacto).
     *
     * @return array{op: string, conditions: list<array<string, mixed>>}
     */
    private static function openLead(): array
    {
        return ['op' => 'and', 'conditions' => [['type' => 'lead', 'field' => 'has_open_lead', 'operator' => 'is_true']]];
    }

    /**
     * El lead sigue para nutrir (21): abierto, en las primeras etapas, sin cita próxima y sin cotización en 30 días.
     *
     * @return array<string, mixed>
     */
    private static function stillNurturing(): array
    {
        return ['op' => 'and', 'conditions' => [
            ['type' => 'lead', 'field' => 'has_open_lead', 'operator' => 'is_true'],
            ['type' => 'lead', 'field' => 'stage', 'operator' => 'in', 'value' => ['new', 'contacted', 'info_sent', 'qualified']],
            ['type' => 'service', 'field' => 'has_upcoming', 'operator' => 'is_false'],
            ['type' => 'event', 'event' => 'quote.sent', 'filters' => [], 'operator' => 'never', 'window' => ['kind' => 'last_days', 'days' => 30]],
        ]];
    }

    /** @return array<string, mixed> */
    private static function aiReactivate(): array
    {
        $g = (new LibraryGraph)
            ->node('ai', 'ai_reactivate', ['channels' => ['whatsapp', 'email'], 'lookback_days' => 365, 'offer' => ''])
            ->chain('trigger', 'ai');

        return self::flow(21, 'ai_reactivate', 'Reactivar con IA', 'Entra en «Hibernando»: la IA escribe un mensaje personal con lo último que habló y lo que compró, y termina con una pregunta fácil. Sin oferta del dueño no ofrece descuentos.',
            ['type' => 'segment', 'system_key' => 'rfm_hibernating', 'on' => 'entered'],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 120], $g, 4);
    }

    // ── Ayudas ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function send(string $templateKey): array
    {
        return ['template_key' => $templateKey, 'kind' => 'marketing'];
    }

    /**
     * Espera a que el cliente escriba por WhatsApp, como máximo `days` días (el seguimiento de leads).
     *
     * @return array<string, mixed>
     */
    private static function untilReply(int $days): array
    {
        return ['event' => 'whatsapp.inbound', 'timeout' => ['value' => $days, 'unit' => 'days'], 'filters' => []];
    }

    /** @return array<string, mixed> */
    private static function wait(int $value, string $unit): array
    {
        return ['mode' => 'duration', 'duration' => ['value' => $value, 'unit' => $unit]];
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  array<string, mixed>|null  $goal
     * @param  array<string, mixed>  $reentry
     * @return array<string, mixed>
     */
    private static function flow(int $number, string $key, string $name, string $description, array $trigger, ?array $goal, array $reentry, LibraryGraph $graph, int $phase = 2): array
    {
        return [
            'number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => $phase,
            'trigger' => $trigger, 'goal' => $goal, 'reentry_policy' => $reentry, 'graph' => $graph->toArray(), 'module' => null,
        ];
    }

    /**
     * Automatización que hace su propio módulo: pedir reseñas y NPS (M15), los avisos de
     * stock y precio (M16) y los recordatorios de cita (M18, `igr:bookings:tick`) tienen
     * programador, plantilla y tope propios. La tarjeta lleva a su configuración para no
     * escribirle dos veces a nadie por lo mismo.
     *
     * @return array<string, mixed>
     */
    private static function module(int $number, string $key, string $name, string $description, string $route, int $phase = 3): array
    {
        return ['number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => $phase, 'trigger' => null, 'goal' => null, 'reentry_policy' => null, 'graph' => null, 'module' => $route];
    }
}
