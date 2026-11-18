<?php

declare(strict_types=1);

namespace App\Domain\Messaging\Attribution;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Messaging\Models\Message;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Paso 2 de AttributeOrder (hub/CLAUDE.md §9): el pedido se atribuye al
 * **último mensaje con clic** del contacto dentro de `attribution.click_days`
 * (5 por defecto). Si no hay y `count_opens` está activo, a la última lectura
 * de WhatsApp o apertura de correo **sin MPP** dentro de `open_days` (1).
 */
final class MessageAttributor
{
    public function attribute(Workspace $workspace, Order $order): ?Message
    {
        if ($order->contact_id === null) {
            return null;
        }
        $contact = Contact::query()->find($order->contact_id);
        if ($contact === null) {
            return null;
        }
        $settings = (array) $workspace->setting('attribution', []);
        $at = CarbonImmutable::instance($order->created_at ?? now());
        $ids = ExportContactData::contactIds($contact);
        $base = fn () => Message::query()->whereIn('contact_id', $ids)->where('direction', 'out')->whereNotIn('status', ['failed', 'bounced']);

        $message = $base()->whereNotNull('clicked_at')
            ->whereBetween('clicked_at', [$at->subDays(max(1, (int) ($settings['click_days'] ?? 5))), $at])
            ->orderByDesc('clicked_at')->first();
        if ($message === null && ! empty($settings['count_opens'])) {
            $from = $at->subDays(max(1, (int) ($settings['open_days'] ?? 1)));
            $message = $base()->where(fn ($q) => $q->whereBetween('read_at', [$from, $at])->orWhereBetween('opened_at', [$from, $at]))
                ->get()->sortByDesc(fn (Message $m) => max($m->read_at?->getTimestamp() ?? 0, $m->opened_at?->getTimestamp() ?? 0))->first();
        }
        if ($message !== null) {
            $order->forceFill([
                'attributed_message_id' => $message->id,
                'attributed_flow_id' => $message->flow_id,
                'attributed_campaign_id' => $message->campaign_id,
            ])->save();
        }

        return $message;
    }
}
