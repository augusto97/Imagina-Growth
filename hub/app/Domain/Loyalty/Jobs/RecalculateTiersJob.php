<?php

declare(strict_types=1);

namespace App\Domain\Loyalty\Jobs;

use App\Domain\Loyalty\Actions\RecalculateAllTiers;
use App\Domain\Loyalty\Models\LoyaltyProgram;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Después de cambiar los niveles en el panel: recalcula el de todos (hub/CLAUDE.md §15, cola analytics). */
final class RecalculateTiersJob implements ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;

    public function __construct(public string $workspaceIdValue)
    {
        $this->onQueue('analytics');
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

    public function handle(RecalculateAllTiers $recalculate): void
    {
        $program = LoyaltyProgram::query()->first();
        if ($program !== null && $program->isActive()) {
            $recalculate->handle($program);
        }
    }
}
