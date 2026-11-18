<?php

declare(strict_types=1);

namespace App\Domain\Measurement\Audiences\Jobs;

use App\Domain\Measurement\Audiences\Models\AudienceSync;
use App\Domain\Measurement\Audiences\SyncAudience;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sincroniza una audiencia (job largo: cola analytics). */
final class SyncAudienceJob implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1500;

    public int $tries = 1;

    public int $uniqueFor = 1800;

    public function __construct(public string $workspaceIdValue, public string $audienceSyncId)
    {
        $this->onQueue('analytics');
    }

    public function uniqueId(): string
    {
        return $this->audienceSyncId;
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

    public function handle(SyncAudience $sync): void
    {
        $audience = AudienceSync::query()->find($this->audienceSyncId);
        if ($audience === null || $audience->status === 'paused') {
            return;
        }
        $sync->handle($audience);
    }
}
