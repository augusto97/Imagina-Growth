<?php

declare(strict_types=1);

namespace App\Domain\Home\Http;

use App\Domain\Home\Queries\SectionSummary;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * GET /api/w/{workspace}/sections/{section}: las cifras y lo primero que conviene hacer en la página de entrada de una
 * sección (docs/diseno/rediseno-v2.md §3). Cualquier miembro; cada cifra pide además el permiso de lo que cuenta.
 */
final class SectionController
{
    public function __invoke(string $section, CurrentWorkspace $current, SectionSummary $summary): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');

        return response()->json($summary->handle($current->get(), $section));
    }
}
