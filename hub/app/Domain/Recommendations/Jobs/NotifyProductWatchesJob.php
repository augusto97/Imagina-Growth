<?php

declare(strict_types=1);

namespace App\Domain\Recommendations\Jobs;

use App\Domain\Recommendations\Watches\NotifyProductWatches;
use App\Domain\Sites\Models\Site;
use App\Support\Tenancy\CurrentWorkspace;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Revisa los «avísame» de un producto (o de todo el sitio) y envía los que ya se cumplen. */
final class NotifyProductWatchesJob implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $uniqueFor = 120;

    public function __construct(public string $workspaceIdValue, public string $siteId, public ?string $productId = null)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return $this->workspaceIdValue.':'.$this->siteId.':'.($this->productId ?? '*');
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

    public function handle(NotifyProductWatches $notify, CurrentWorkspace $current): void
    {
        $site = Site::query()->find($this->siteId);
        if ($site === null) {
            return;
        }
        $notify->forProduct($current->get(), $site, $this->productId);
    }
}
