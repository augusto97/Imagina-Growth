<?php

declare(strict_types=1);

namespace App\Domain\Referrals\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Referrals\Actions\SyncReferralOrder;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Un pedido de un referido o afiliado cambió de estado (completado, cancelado, reembolsado). */
final class SyncReferralOrderJob implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function __construct(public string $workspaceIdValue, public string $siteId, public string $externalOrderId) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(SyncReferralOrder $sync): void
    {
        $order = Order::query()->where('site_id', $this->siteId)->where('external_id', $this->externalOrderId)->first();
        if ($order !== null) {
            $sync->handle($order);
        }
    }
}
