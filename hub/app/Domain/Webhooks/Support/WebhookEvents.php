<?php

declare(strict_types=1);

namespace App\Domain\Webhooks\Support;

/**
 * Eventos a los que se puede suscribir un webhook (docs/api-publica.md §9), agrupados para el
 * selector del panel. Son los eventos del contrato (eventos.md §4) que llegan a la ingesta o
 * que genera el hub, más `contact.created` (contacto nuevo, lo detecta el hub) y
 * `webhook.ping` (prueba).
 */
final class WebhookEvents
{
    public const GROUPS = [
        'orders' => ['order.created', 'order.paid', 'order.payment_pending', 'order.completed', 'order.cancelled', 'order.refunded', 'order.failed', 'order.status_changed'],
        'carts' => ['checkout.started', 'checkout.contact_captured', 'cart.abandoned'],
        'contacts' => ['contact.created', 'customer.registered', 'customer.updated', 'form.submitted', 'widget.submitted', 'consent.granted', 'consent.revoked'],
        'leads' => ['lead.created', 'lead.stage_changed', 'lead.won', 'lead.lost'],
        'appointments' => ['appointment.booked', 'appointment.rescheduled', 'appointment.cancelled', 'appointment.completed', 'appointment.no_show', 'quote.requested'],
        'messages' => ['message.sent', 'message.delivered', 'message.read', 'message.clicked', 'message.replied', 'message.failed', 'message.unsubscribed', 'whatsapp.inbound'],
        'reviews' => ['review.submitted', 'review.collected', 'nps.answered', 'survey.answered'],
        'loyalty' => ['points.earned', 'points.redeemed', 'tier.changed', 'referral.converted', 'wallet.credited'],
        'subscriptions' => ['subscription.created', 'subscription.renewed', 'subscription.renewal_failed', 'subscription.cancelled'],
        'custom' => ['custom.*'],
    ];

    public const SYNTHETIC = ['contact.created', 'webhook.ping'];

    /** @return list<string> */
    public static function all(): array
    {
        return array_values(array_merge(...array_values(self::GROUPS)));
    }

    /** ¿Es un patrón válido para una suscripción? Exacto del catálogo, `grupo.*` o `*`. */
    public static function isValidPattern(string $pattern): bool
    {
        if ($pattern === '*' || in_array($pattern, self::all(), true)) {
            return true;
        }
        if (str_ends_with($pattern, '.*')) {
            $prefix = substr($pattern, 0, -1);
            foreach (self::all() as $event) {
                if (str_starts_with($event, $prefix)) {
                    return true;
                }
            }
        }

        return false;
    }
}
