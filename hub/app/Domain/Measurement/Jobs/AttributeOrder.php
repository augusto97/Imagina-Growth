<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Jobs;

use App\Domain\Commerce\Models\Order;
use App\Domain\Measurement\Attribution\OrderAttributor;
use App\Domain\Messaging\Attribution\MessageAttributor;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** AttributeOrder (hub/CLAUDE.md §9) al llegar un order.paid en vivo: canales (paso 1) y mensaje (paso 2). */
final class AttributeOrder implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  array{first_touch?: array<string, mixed>|null, last_touch?: array<string, mixed>|null, anonymous_id?: string|null}  $hints
     */
    public function __construct(public string $workspaceIdValue, public string $siteId, public string $externalOrderId, public array $hints = []) {}

    public function workspaceId(): string
    {
        return $this->workspaceIdValue;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new SetWorkspaceContext];
    }

    public function handle(OrderAttributor $attributor, MessageAttributor $messages): void
    {
        $order = Order::query()->where('site_id', $this->siteId)->where('external_id', $this->externalOrderId)->first();
        if ($order === null) {
            return;
        }
        $workspace = Workspace::query()->findOrFail($this->workspaceIdValue);
        $attributor->attribute($workspace, $order, $this->hints);
        // Paso 2: el mensaje (flujo o campaña) que trajo el pedido.
        $messages->attribute($workspace, $order);
    }
}
