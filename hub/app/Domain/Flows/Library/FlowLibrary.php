<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

/**
 * Los 20 flujos listos de modulos.md M09, en español. Los que dependen de
 * módulos de otras fases (puntos, niveles VIP, avisos de stock, NPS, referidos,
 * citas, leads, renovaciones) se listan con su fase y todavía no se crean.
 *
 * Las plantillas se nombran por `template_key` = `library_key` de la biblioteca
 * de M08 (TEMPLATE_KEYS); al crear el flujo se resuelven a las plantillas del
 * espacio de trabajo.
 */
final class FlowLibrary
{
    /** Plantillas que usan los flujos listos (para M08). */
    public const TEMPLATE_KEYS = [
        'wa_welcome_coupon', 'email_welcome_coupon', 'email_welcome_story', 'email_welcome_bestsellers',
        'wa_abandoned_cart_1', 'email_abandoned_cart_1', 'email_abandoned_cart_2', 'wa_abandoned_cart_coupon', 'email_abandoned_cart_coupon',
        'wa_abandoned_checkout', 'email_abandoned_checkout', 'email_abandoned_checkout_2',
        'email_browse_abandonment',
        'wa_payment_pending_1', 'email_payment_pending_1', 'wa_payment_pending_2', 'email_payment_pending_2',
        'wa_cod_confirm',
        'email_post_purchase',
        'email_second_purchase', 'wa_second_purchase',
        'wa_replenishment', 'email_replenishment',
        'email_winback_1', 'wa_winback_coupon', 'email_winback_coupon',
        'wa_birthday', 'email_birthday',
        'team_alert',
    ];

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
            self::later(8, 'review_request', 'Pedir reseña', 'Entrega + N días según la categoría: WhatsApp o correo con estrellas en 1 toque y recompensa por foto.', 3),
            self::secondPurchase(),
            self::replenishment(),
            self::winback(),
            self::later(12, 'vip_welcome', 'VIP', 'Sube de nivel: bienvenida al nivel, beneficios y acceso anticipado.', 3),
            self::birthday(),
            self::later(14, 'back_in_stock', 'Volvió el stock / bajó de precio', 'Aviso inmediato a quien lo pidió, con tope de frecuencia.', 3),
            self::later(15, 'points_expiring', 'Puntos por vencer', 'Recordatorio 15 días antes con el saldo y sugerencias de canje.', 3),
            self::later(16, 'referral_invite', 'Invitar a referir', 'NPS de 9 o más, o segunda compra: enlace de referido para compartir por WhatsApp.', 3),
            self::later(17, 'nps', 'NPS y detractores', '14 días después de la entrega: encuesta; con nota de 6 o menos, alerta al dueño y una tarea.', 3),
            self::later(18, 'service_lead', 'Lead de servicios', 'Formulario o WhatsApp entrante: respuesta inmediata, aviso al vendedor y seguimiento de 3 toques.', 5),
            self::later(19, 'appointment_reminder', 'Recordatorio de cita', '24 h y 2 h antes de la cita, con reprogramación por botón.', 5),
            self::later(20, 'renewal', 'Renovación', '15 días antes de la renovación: recordatorio, oferta y aviso al equipo si no renueva.', 5),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $flow) {
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
            ->node('em', 'send_email', self::send('email_welcome_coupon'))
            ->node('w1', 'wait', self::wait(2, 'days'))
            ->node('story', 'send_email', self::send('email_welcome_story'))
            ->node('w2', 'wait', self::wait(3, 'days'))
            ->node('best', 'send_email', self::send('email_welcome_bestsellers'))
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
            ->node('em1', 'send_email', self::send('email_abandoned_cart_1'))
            ->node('w2', 'wait', self::wait(3, 'hours'))
            ->node('em2', 'send_email', self::send('email_abandoned_cart_2'))
            ->node('w3', 'wait', self::wait(20, 'hours'))
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '10', 'expires_in_days' => 2, 'prefix' => 'VUELVE'])
            ->node('wa2', 'send_whatsapp', self::send('wa_abandoned_cart_coupon'))
            ->node('em3', 'send_email', self::send('email_abandoned_cart_coupon'))
            ->chain('trigger', 'w1', 'wa1')
            ->edge('wa1', 'em1', 'failed')->edge('wa1', 'w2')->edge('em1', 'w2')
            ->chain('w2', 'em2', 'w3', 'cpn', 'wa2')
            ->edge('wa2', 'em3', 'failed');

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
            ->node('em1', 'send_email', self::send('email_abandoned_checkout'))
            ->node('w2', 'wait', self::wait(23, 'hours'))
            ->node('em2', 'send_email', self::send('email_abandoned_checkout_2'))
            ->chain('trigger', 'we')
            ->edge('we', 'done', 'event')->edge('we', 'wa1', 'timeout')
            ->edge('wa1', 'em1', 'failed')->edge('wa1', 'w2')->edge('em1', 'w2')
            ->chain('w2', 'em2');

        return self::flow(3, 'abandoned_checkout', 'Checkout abandonado', 'Si no hay pedido 30 minutos después de empezar el pago: WhatsApp y, al día siguiente, correo.',
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
            ->node('em', 'send_email', self::send('email_browse_abandonment'))
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
            ->node('em1', 'send_email', self::send('email_payment_pending_1'))
            ->node('w2', 'wait', self::wait(22, 'hours'))
            ->node('wa2', 'send_whatsapp', self::send('wa_payment_pending_2'))
            ->node('em2', 'send_email', self::send('email_payment_pending_2'))
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
            ->node('fail', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'No se pudo pedir por WhatsApp la confirmación de un pedido contraentrega. Llama al cliente.', 'template_key' => 'team_alert'])
            ->node('wait', 'wait_event', ['event' => 'message.replied', 'timeout' => ['value' => 12, 'unit' => 'hours'], 'filters' => [['prop' => 'button_payload', 'operator' => 'in', 'value' => ['cod_confirm', 'cod_cancel']]]])
            ->node('answer', 'condition', ['mode' => 'context', 'path' => 'last_event.props.button_payload', 'operator' => 'eq', 'value' => 'cod_confirm'])
            ->node('ok', 'tag', ['add' => ['contraentrega_confirmada']])
            ->node('cancel', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'El cliente canceló su pedido contraentrega desde WhatsApp.', 'template_key' => 'team_alert'])
            ->node('late', 'notify_team', ['channel' => 'email', 'to_owners' => true, 'message' => 'Un pedido contraentrega lleva 12 horas sin confirmar. Llama al cliente antes de despacharlo.', 'template_key' => 'team_alert'])
            ->chain('trigger', 'ask')
            ->edge('ask', 'fail', 'failed')->edge('ask', 'wait')
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
            ->node('em', 'send_email', self::send('email_post_purchase'))
            ->chain('trigger', 'w1', 'em');

        return self::flow(7, 'post_purchase', 'Poscompra', 'Gracias y cómo usar el producto, un día después de completar el pedido.',
            ['type' => 'event', 'event' => 'order.completed', 'filters' => []],
            null, ['mode' => 'always'], $g);
    }

    /** @return array<string, mixed> */
    private static function secondPurchase(): array
    {
        $g = (new LibraryGraph)
            ->node('em', 'send_email', self::send('email_second_purchase'))
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
            ->node('em', 'send_email', self::send('email_replenishment'))
            ->chain('trigger', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(10, 'replenishment', 'Reposición', '5 días antes de la fecha esperada de su próxima compra: «se te está acabando».',
            ['type' => 'date', 'attribute' => 'expected_next_order_at', 'offset_days' => -5, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 20], $g);
    }

    /** @return array<string, mixed> */
    private static function winback(): array
    {
        $g = (new LibraryGraph)
            ->node('em1', 'send_email', self::send('email_winback_1'))
            ->node('w1', 'wait', self::wait(3, 'days'))
            ->node('cpn', 'coupon', ['kind' => 'percent', 'amount' => '15', 'expires_in_days' => 7, 'prefix' => 'TEEXTRANAMOS'])
            ->node('split', 'channel_split', ['channels' => ['whatsapp', 'email']])
            ->node('wa', 'send_whatsapp', self::send('wa_winback_coupon'))
            ->node('em2', 'send_email', self::send('email_winback_coupon'))
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
            ->node('em', 'send_email', self::send('email_birthday'))
            ->chain('trigger', 'cpn', 'split')->edge('split', 'wa', 'whatsapp')->edge('split', 'em', 'email');

        return self::flow(13, 'birthday', 'Cumpleaños', 'Regalo el día del cumpleaños (cupón), a una hora legal.',
            ['type' => 'date', 'attribute' => 'birthday', 'offset_days' => 0, 'hour' => 10],
            ['event' => 'order.paid'], ['mode' => 'every_days', 'days' => 300], $g);
    }

    // ── Ayudas ──────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function send(string $templateKey): array
    {
        return ['template_key' => $templateKey, 'kind' => 'marketing'];
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
    private static function flow(int $number, string $key, string $name, string $description, array $trigger, ?array $goal, array $reentry, LibraryGraph $graph): array
    {
        return [
            'number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => 2,
            'trigger' => $trigger, 'goal' => $goal, 'reentry_policy' => $reentry, 'graph' => $graph->toArray(),
        ];
    }

    /** @return array<string, mixed> */
    private static function later(int $number, string $key, string $name, string $description, int $phase): array
    {
        return ['number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => $phase, 'trigger' => null, 'goal' => null, 'reentry_policy' => null, 'graph' => null];
    }
}
