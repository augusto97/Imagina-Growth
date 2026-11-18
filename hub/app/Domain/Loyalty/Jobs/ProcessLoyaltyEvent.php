<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Loyalty\Actions\OrderLifecycle;
use App\Domain\Loyalty\Actions\ProfileRewards;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Aplica al libro un evento de la ingesta (LoyaltyEventListener). Todo es idempotente. */
final class ProcessLoyaltyEvent implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /**
     * @param  array{occurred_at?: string|null, to?: string, order_id?: string, refund_id?: string|null, amount?: string|null, is_partial?: bool}  $data
     */
    public function __construct(public string $workspaceIdValue, public string $siteId, public string $event, public ?string $contactId, public array $data)
    {
        $this->onQueue('projections');
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

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(OrderLifecycle $orders, ProfileRewards $profile): void
    {
        // Sin programa no hay nada que hacer, salvo el saldo (cupones de saldo y devoluciones).
        $hasProgram = LoyaltyProgram::query()->exists();
        if (! isset($this->data['order_id'])) {
            if ($hasProgram && $this->contactId !== null) {
                $profile->handle($this->contactId, $this->event);
            }

            return;
        }
        $order = Order::query()->where('site_id', $this->siteId)->where('external_id', $this->data['order_id'])->first();
        if ($order === null) {
            return;
        }
        $at = isset($this->data['occurred_at']) ? CarbonImmutable::parse($this->data['occurred_at'])->utc() : CarbonImmutable::now();
        $event = $this->event === 'order.status_changed' ? 'order.'.($this->data['to'] ?? '') : $this->event;
        match ($event) {
            'order.paid' => $orders->paid($order),
            'order.completed' => $orders->completed($order, $at),
            'order.refunded' => $orders->refunded($order, (string) ($this->data['refund_id'] ?? $at->getTimestamp()), (string) ($this->data['amount'] ?? $order->refunded), (bool) ($this->data['is_partial'] ?? false)),
            'order.cancelled', 'order.failed' => $orders->cancelled($order),
            default => null,
        };
    }
}
