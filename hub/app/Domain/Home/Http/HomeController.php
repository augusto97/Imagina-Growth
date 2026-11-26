<?php

declare(strict_types=1);

namespace App\Domain\Home\Http;

use App\Domain\Home\Queries\HomeOverview;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** GET /api/w/{workspace}/home: el Inicio del dueño (docs/diseno/rediseno-v2.md §5). Permiso `viewDashboards`. */
final class HomeController
{
    public function __invoke(Request $request, CurrentWorkspace $current, HomeOverview $home): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $user = $request->user();

        return response()->json($home->handle($current->get(), $user instanceof User ? $user : null));
    }
}
