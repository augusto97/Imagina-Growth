<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Queries\GettingStarted;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** GET /api/w/{workspace}/getting-started: el camino del Inicio hasta la primera venta recuperada (F16). */
final class GettingStartedController
{
    public function __invoke(CurrentWorkspace $current, GettingStarted $query): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json($query->handle($current->get()));
    }
}
