<?php

declare(strict_types=1);

namespace App\Domain\Segments\Jobs;

use App\Domain\Segments\Actions\EvaluateContactSegments;
use App\Domain\Segments\Support\PendingSegmentEvaluation;
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
 * otro; los que llegan mientras corre, sí. Cada evento anota en Redis los
 * segmentos que dependen de él (PendingSegmentEvaluation) y el job los toma
 * todos: solo esos se reevalúan.
 */
final class EvaluateContactSegmentsJob implements ShouldBeUniqueUntilProcessing, ShouldQueue, WorkspaceAware
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Espera para que se asienten los contadores del contacto (RecalculateContactStats, 5 s). */
    public const DELAY_SECONDS = 15;

    public int $uniqueFor = 300;

    /**
     * true: los segmentos están en PendingSegmentEvaluation. Un job encolado antes de este cambio no
     * trae la propiedad (queda en false) y reevalúa todos, como antes.
     */
    public bool $usesPending = false;

    public function __construct(public string $workspaceIdValue, public string $contactId, bool $usesPending = false)
    {
        $this->usesPending = $usesPending;
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
        if (! $this->usesPending) {
            $evaluate->handle($this->contactId);

            return;
        }
        // Otro job del mismo contacto pudo llevarse ya estos segmentos: entonces no queda nada que hacer.
        $segmentIds = PendingSegmentEvaluation::take($this->workspaceIdValue, $this->contactId);
        if ($segmentIds !== []) {
            $evaluate->handle($this->contactId, $segmentIds);
        }
    }
}
