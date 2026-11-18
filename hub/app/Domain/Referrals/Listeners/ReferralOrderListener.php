<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Listeners;

use App\Domain\Referrals\Actions\FlagReferralSignals;
use App\Domain\Referrals\Jobs\SyncReferralOrderJob;
use App\Domain\Referrals\Models\Referral;
use App\Domain\Referrals\Support\Fingerprints;
use App\Domain\Referrals\Support\ReferralSwitch;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Events\EventIngested;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pedidos en vivo para M14 (la atribución en sí es el paso 3 de AttributeOrder):
 *
 * - Con el programa de referidos activo, guarda las huellas (hash) de la IP y del
 *   destinatario del pedido, para comparar después a quien refiere con su amigo.
 * - Los cambios de estado (completado, cancelado, reembolsado) se encolan para
 *   seguir al referido o la comisión de ese pedido.
 *
 * Nunca rompe la ingesta.
 */
final class ReferralOrderListener
{
    private const STATUS_EVENTS = ['order.status_changed', 'order.completed', 'order.cancelled', 'order.failed', 'order.refunded'];

    public function __construct(private readonly Fingerprints $fingerprints, private readonly FlagReferralSignals $signals, private readonly CurrentWorkspace $current) {}

    public function handle(EventIngested $event): void
    {
        if (! str_starts_with($event->event, 'order.') || $event->siteId === '') {
            return;
        }
        try {
            $state = ReferralSwitch::state($event->workspaceId);
            if (! $state['referrals'] && ! $state['affiliates']) {
                return;
            }
            $props = (array) ($event->envelope['properties'] ?? []);
            $order = is_array($props['order'] ?? null) ? $props['order'] : null;
            $contactId = $event->contactId;
            if ($state['referrals'] && $contactId !== null && in_array($event->event, ['order.created', 'order.paid'], true)) {
                $this->fingerprints->record($event->workspaceId, $contactId, 'ip', $event->ip);
                foreach (['shipping', 'billing'] as $kind) {
                    $this->fingerprints->record($event->workspaceId, $contactId, 'address', Fingerprints::addressValue(is_array($order[$kind] ?? null) ? $order[$kind] : null));
                }
                // Si es el amigo de un referido abierto, sus huellas nuevas se comparan con las de quien lo refirió.
                $workspace = Workspace::query()->find($event->workspaceId);
                if ($workspace !== null) {
                    $this->current->run($workspace, function () use ($contactId): void {
                        $referral = Referral::query()->where('friend_contact_id', $contactId)->first();
                        if ($referral !== null) {
                            $this->signals->forFriend($referral);
                        }
                    });
                }
            }
            $orderId = $order['order_id'] ?? ($props['order_id'] ?? null);
            if (in_array($event->event, self::STATUS_EVENTS, true) && is_scalar($orderId)) {
                SyncReferralOrderJob::dispatch($event->workspaceId, $event->siteId, (string) $orderId);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.referrals.listener_failed', ['event' => $event->event, 'workspace' => $event->workspaceId]);
        }
    }
}
