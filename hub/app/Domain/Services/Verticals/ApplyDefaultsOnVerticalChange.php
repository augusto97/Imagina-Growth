<?php

declare(strict_types=1);

namespace App\Domain\Services\Verticals;

use App\Domain\Workspaces\Events\WorkspaceVerticalChanged;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Al elegir la vertical, sus valores por defecto (embudo de leads e industria de las plantillas). Idempotente y sin
 * romper el guardado: si algo falla, se reporta y el cambio de vertical queda igual.
 */
final class ApplyDefaultsOnVerticalChange
{
    public function __construct(private readonly ApplyVerticalDefaults $apply) {}

    public function handle(WorkspaceVerticalChanged $event): void
    {
        if (! VerticalCatalog::exists($event->vertical)) {
            return;
        }
        try {
            $this->apply->handle($event->workspace, (string) $event->vertical);
        } catch (Throwable $e) {
            report($e);
            Log::warning('igr.verticals.defaults_failed', ['workspace' => $event->workspace->id]);
        }
    }
}
