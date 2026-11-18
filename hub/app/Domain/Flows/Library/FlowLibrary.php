<?php

declare(strict_types=1);

namespace App\Domain\Flows\Library;

/**
 * Los 20 flujos listos de modulos.md M09, en español. Los de la Fase 3 que
 * hace su propio módulo (pedir reseña, NPS, avisos de stock) llevan a la
 * configuración de ese módulo; los de la Fase 5 (citas, leads, renovaciones) se
 * listan con su fase y todavía no se crean.
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
    ];

    /** Plantillas de correo de la biblioteca de M08 (TemplateLibrary) que usan los flujos listos. */
    public const EMAIL_KEYS = [
        'welcome_1', 'welcome_2', 'welcome_3', 'abandoned_cart_1', 'abandoned_cart_2', 'abandoned_checkout',
        'browse_abandonment', 'payment_pending_1', 'payment_pending_2', 'cod_confirm_email', 'post_purchase',
        'second_purchase', 'replenishment', 'winback_1', 'winback_2', 'birthday', 'team_alert',
        'vip_welcome', 'points_expiring', 'referral_invite',
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
    private static function flow(int $number, string $key, string $name, string $description, array $trigger, ?array $goal, array $reentry, LibraryGraph $graph, int $phase = 2): array
    {
        return [
            'number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => $phase,
            'trigger' => $trigger, 'goal' => $goal, 'reentry_policy' => $reentry, 'graph' => $graph->toArray(), 'module' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function later(int $number, string $key, string $name, string $description, int $phase): array
    {
        return ['number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => $phase, 'trigger' => null, 'goal' => null, 'reentry_policy' => null, 'graph' => null, 'module' => null];
    }

    /**
     * Automatización que hace su propio módulo (Fase 3): pedir reseñas y NPS (M15)
     * y los avisos de stock y precio (M16) tienen programador, plantilla y tope
     * propios. La tarjeta lleva a su configuración para no escribirle dos veces a
     * nadie por lo mismo.
     *
     * @return array<string, mixed>
     */
    private static function module(int $number, string $key, string $name, string $description, string $route): array
    {
        return ['number' => $number, 'key' => $key, 'name' => $name, 'description' => $description, 'phase' => 3, 'trigger' => null, 'goal' => null, 'reentry_policy' => null, 'graph' => null, 'module' => $route];
    }
}
