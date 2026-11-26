<?php

declare(strict_types=1);

namespace App\Domain\Services\Verticals\Http;

use App\Domain\Services\Verticals\ApplyVerticalDefaults;
use App\Domain\Services\Verticals\VerticalCatalog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Perfiles de vertical (M18 «Verticales»): `GET verticals` los lista (solo lectura, para la SPA y el asistente) con
 * el tipo de negocio y el vertical del espacio de trabajo; `POST verticals/{key}/apply` deja sus valores por
 * defecto (ApplyVerticalDefaults, idempotente).
 */
final class VerticalController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET /api/w/{ws}/verticals */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $ws = $this->current->get();
        $applied = $ws->setting('vertical_defaults');

        return response()->json([
            'current' => VerticalCatalog::forWorkspace($ws) + ['applied' => is_array($applied) ? $applied : null],
            'business_types' => VerticalCatalog::BUSINESS_TYPES,
            'data' => VerticalCatalog::all(),
        ]);
    }

    /** POST /api/w/{ws}/verticals/{key}/apply */
    public function apply(string $key, ApplyVerticalDefaults $apply): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        if (! VerticalCatalog::exists($key)) {
            abort(404);
        }

        return response()->json($apply->handle($this->current->get(), $key));
    }
}
