<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Analytics\Ch;
use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Resumen de Inicio de la Fase 0: lo que ya llegó. Los tableros completos
 * (M05) llegan en la Fase 1.
 */
final class OverviewController
{
    public function __invoke(CurrentWorkspace $current): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $current->get();

        $paid = Order::query()->where('is_paid', true);
        $events24h = null;
        try {
            $events24h = (int) Ch::forWorkspace($ws)->value('SELECT count() FROM events WHERE workspace_id = {workspace_id:UInt32} AND received_at >= now() - INTERVAL 1 DAY');
        } catch (Throwable) {
        }

        return response()->json([
            'currency' => $ws->currency,
            'sites' => ['total' => Site::query()->count(), 'connected' => Site::query()->whereIn('status', [Site::STATUS_CONNECTED, Site::STATUS_DEGRADED])->count()],
            'orders' => ['total' => Order::query()->count(), 'paid' => (clone $paid)->count(), 'cod_pending' => Order::query()->where('is_cod', true)->where('is_paid', false)->whereNotIn('status', ['cancelled', 'failed', 'refunded'])->count()],
            'revenue_ws' => (string) ((clone $paid)->sum('total_ws') ?: '0'),
            'contacts' => ['total' => Contact::query()->count(), 'customers' => Contact::query()->where('orders_count', '>', 0)->count()],
            'events_24h' => $events24h,
        ]);
    }
}
