<?php

declare(strict_types=1);

namespace App\Domain\Onsite\Attribution;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Onsite\Models\WidgetCapture;
use App\Domain\Privacy\Actions\ExportContactData;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;

/**
 * Paso 4 de AttributeOrder (hub/CLAUDE.md §9, atribución de captación): el widget
 * que capturó al contacto en los `attribution.widget_days` (7) días antes del
 * pedido. Gana la captura más reciente; se guarda en `orders.attributed_widget_id`
 * y `attributed_widget_variant`, y lo leen las estadísticas del widget. Debe correr
 * dentro del contexto del espacio de trabajo.
 */
final class WidgetAttributor
{
    public function attribute(Workspace $workspace, Order $order): ?WidgetCapture
    {
        if ($order->contact_id === null) {
            return null;
        }
        $contact = Contact::query()->find($order->contact_id);
        if ($contact === null) {
            return null;
        }
        $days = max(1, (int) ($workspace->setting('attribution', [])['widget_days'] ?? 7));
        $at = CarbonImmutable::instance($order->created_at ?? now())->endOfSecond();
        $capture = WidgetCapture::query()->whereIn('contact_id', ExportContactData::contactIds($contact))
            ->whereBetween('captured_at', [$at->subDays($days), $at])
            ->orderByDesc('captured_at')->orderByDesc('id')->first();
        $order->forceFill([
            'attributed_widget_id' => $capture?->widget_id,
            'attributed_widget_variant' => $capture?->variant,
        ])->save();

        return $capture;
    }
}
