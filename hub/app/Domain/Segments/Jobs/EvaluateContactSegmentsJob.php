<?php

declare(strict_types=1);

namespace App\Domain\Segments\Jobs;

use App\Domain\Segments\Actions\EvaluateContactSegments;
use App\Support\Tenancy\SetWorkspaceContext;
use App\Support\Tenancy\WorkspaceAware;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Segmentos en tiempo real de un contacto. Único hasta que empieza a
 * procesarse (debounce): los eventos que llegan mientras espera no encolan
 * otro; los que llegan mientras corre, sí.
 */
final class EvaluateContactSegmentsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Espera para que se asienten los contadores del contacto (RecalculateContactStats, 5 s). */
    public const DELAY_SECONDS = 15;

    public int $uniqueFor = 300;

    public function __construct(public string $workspaceIdValue, public string $contactId)
    {
        $this->onQueue('triggers'); // hub/CLAUDE.md §15: tiempo real
    }

    public function uniqueId(): string
    {
        return $this->workspaceIdValue.':'.$this->contactId;
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

    public function handle(EvaluateContactSegments $evaluate): void
    {
        $evaluate->handle($this->contactId);
    }
}
