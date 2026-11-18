<?php

declare(strict_types=1);

namespace App\Domain\Segments\Jobs;

use App\Domain\Segments\Actions\RecomputeSegment;
use App\Domain\Segments\Models\Segment;
use App\Domain\Segments\Support\DependencyIndex;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Recálculo completo de un segmento (al guardarlo, a pedido o cada noche). */
final class RecomputeSegmentJob implements ShouldBeUnique, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public function __construct(public string $workspaceIdValue, public string $segmentId, public bool $emit = true)
    {
        $this->onQueue('analytics'); // hub/CLAUDE.md §15: recálculo completo, pesado
    }

    public function uniqueId(): string
    {
        return $this->segmentId;
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

    public function handle(RecomputeSegment $recompute): void
    {
        $segment = Segment::query()->find($this->segmentId);
        if ($segment === null) {
            return;
        }
        $wasComputed = $segment->last_computed_at !== null;
        $recompute->handle($segment, $this->emit);
        if (! $wasComputed) {
            DependencyIndex::forget($this->workspaceIdValue); // ya cuenta para el tiempo real
        }
    }
}
