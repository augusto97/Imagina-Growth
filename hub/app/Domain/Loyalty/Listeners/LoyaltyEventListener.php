<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Listeners;

use App\Domain\Loyalty\Jobs\ProcessLoyaltyEvent;
use App\Ingest\Events\EventIngested;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Escucha la ingesta (hub/CLAUDE.md §5, paso 10) y encola lo que mueve el libro:
 * pedidos (puntos, saldo, cashback y nivel), registro y perfil completo. Copia
 * solo lo necesario del sobre (sin datos personales). Nunca rompe la ingesta.
 */
final class LoyaltyEventListener
{
    public const ORDER_EVENTS = ['order.paid', 'order.completed', 'order.refunded', 'order.cancelled', 'order.failed', 'order.status_changed'];

    /** La captura temprana del checkout no suma puntos: ese dato solo sirve para recuperar el carrito (cumplimiento.md §3.2). */
    public const PROFILE_EVENTS = ['customer.registered', 'customer.updated'];

    public function handle(EventIngested $event): void
    {
        $isOrder = in_array($event->event, self::ORDER_EVENTS, true);
        if (! $isOrder && ! in_array($event->event, self::PROFILE_EVENTS, true)) {
            return;
        }
        try {
            $props = is_array($event->envelope['properties'] ?? null) ? $event->envelope['properties'] : [];
            $data = ['occurred_at' => is_string($event->envelope['occurred_at'] ?? null) ? $event->envelope['occurred_at'] : null];
            if ($isOrder) {
                $orderId = $props['order']['order_id'] ?? $props['order_id'] ?? null;
                if ((! is_string($orderId) && ! is_int($orderId)) || $event->siteId === '') {
                    return;
                }
                if ($event->event === 'order.status_changed') {
                    $to = (string) ($props['to'] ?? '');
                    if (! in_array($to, ['completed', 'cancelled', 'failed', 'refunded'], true)) {
                        return;
                    }
                    $data['to'] = $to;
                }
                $data += [
                    'order_id' => (string) $orderId,
                    'refund_id' => isset($props['refund_id']) ? (string) $props['refund_id'] : null,
                    'amount' => isset($props['amount']) && is_numeric($props['amount']) ? (string) $props['amount'] : null,
                    'is_partial' => (bool) ($props['is_partial'] ?? false),
                ];
            } elseif ($event->contactId === null || $event->contactId === '') {
                return;
            }
            ProcessLoyaltyEvent::dispatch($event->workspaceId, $event->siteId, $event->event, $event->contactId, $data);
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.loyalty.listener_failed', ['event' => $event->event, 'workspace' => $event->workspaceId]);
        }
    }
}
