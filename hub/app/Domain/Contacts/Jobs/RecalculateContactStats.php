<?php

declare(strict_types=1);

namespace App\Domain\Contacts\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Contacts\Models\Contact;
use App\Support\Money\Decimal;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los contadores de compra del contacto y el n.º de compra de cada
 * pedido (hub/CLAUDE.md §5: job con debounce de 5 s por contacto).
 *
 * Único hasta que empieza a procesarse: mientras espera, los pedidos nuevos del
 * mismo contacto no encolan otro; si llegan mientras corre, sí encolan uno
 * nuevo, así ningún pedido queda sin contar.
 */
final class RecalculateContactStats implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $uniqueFor = 60;

    public function __construct(public string $workspaceIdValue, public string $contactId)
    {
        $this->onQueue('projections');
    }

    public static function debounce(string $workspaceId, string $contactId): void
    {
        self::dispatch($workspaceId, $contactId)->delay(now()->addSeconds(5));
    }

    public function uniqueId(): string
    {
        return $this->contactId;
    }

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(): void
    {
        $contact = Contact::query()->find($this->contactId);
        if ($contact === null) {
            return;
        }

        $orders = Order::query()
            ->where('contact_id', $contact->id)
            ->where('is_paid', true)
            ->orderBy('created_at')
            ->get(['id', 'created_at', 'total_ws', 'refunded', 'coupon_codes']);

        DB::transaction(function () use ($contact, $orders): void {
            Order::query()->where('contact_id', $contact->id)->where('is_paid', false)
                ->update(['order_index' => null, 'is_first_order' => false]);

            $revenue = '0.00';
            $withCoupon = 0;
            $refunds = 0;
            $gaps = [];
            $previous = null;
            foreach ($orders as $i => $order) {
                Order::query()->whereKey($order->id)->update(['order_index' => $i + 1, 'is_first_order' => $i === 0]);
                $revenue = Decimal::add($revenue, (string) ($order->total_ws ?? '0'));
                $withCoupon += empty($order->coupon_codes) ? 0 : 1;
                $refunds += Decimal::equals((string) $order->getAttribute('refunded'), '0') ? 0 : 1;
                if ($previous !== null) {
                    $gaps[] = $previous->diffInDays($order->created_at);
                }
                $previous = $order->created_at;
            }

            $count = $orders->count();
            $contact->forceFill([
                'orders_count' => $count,
                'revenue_total_ws' => $revenue,
                'aov_ws' => $count > 0 ? Decimal::div($revenue, $count) : '0.00',
                'first_order_at' => $orders->first()?->created_at,
                'last_order_at' => $orders->last()?->created_at,
                'avg_days_between_orders' => $gaps === [] ? null : round(array_sum($gaps) / count($gaps), 2),
                'refunds_count' => $refunds,
                'coupon_orders_ratio' => $count > 0 ? round($withCoupon / $count, 4) : null,
                'lifecycle_stage' => match (true) {
                    $count >= 2 => 'repeat',
                    $count === 1 => 'customer',
                    default => $contact->lifecycle_stage === 'visitor' ? 'lead' : $contact->lifecycle_stage,
                },
            ])->save();
        });
    }
}
