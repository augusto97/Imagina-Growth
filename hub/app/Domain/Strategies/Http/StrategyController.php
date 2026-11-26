<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Http;

use App\Domain\Strategies\Actions\ActivateStrategy;
use App\Domain\Strategies\Actions\PauseStrategy;
use App\Domain\Strategies\Queries\StrategyCatalog;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Strategies\Support\PlanRequired;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyNotReady;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Estrategias (docs/diseno/rediseno-v2.md §6). Ver el catálogo y el detalle: `viewDashboards`. Activar y pausar:
 * `manageMarketing` más el permiso propio de la estrategia (la bandeja y el agente, `manageInbox`; la agenda, el
 * cotizador y la calificación, `manageServices`; los destinos de anuncios, `manageWorkspace`).
 */
final class StrategyController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly StrategyCatalog $catalog) {}

    /** GET strategies */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json($this->catalog->catalog($this->current->get()));
    }

    /** GET strategies/{key} */
    public function show(string $key): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json($this->catalog->detail($this->current->get(), $key) ?? abort(404));
    }

    /** POST strategies/{key}/activate {google_review_url?, wa_number?} */
    public function activate(Request $request, string $key, ActivateStrategy $activate): JsonResponse
    {
        $this->authorizeChange($key);
        $options = $request->validate([
            'google_review_url' => ['sometimes', 'nullable', 'url:https', 'max:500'],
            'wa_number' => ['sometimes', 'nullable', 'string', 'max:20'],
        ]);
        try {
            $result = $activate->handle($this->current->get(), $key, $options, $this->userId($request));
        } catch (PlanRequired $e) {
            return response()->json(['error' => 'plan_required', 'module' => $e->modules[0] ?? null, 'modules' => $e->modules, 'plans' => $e->plans, 'message' => $e->getMessage()], 402);
        } catch (StrategyNotReady $e) {
            return self::notReady($e);
        }

        return response()->json($result['detail'] + ['activated' => $result['activated'], 'skipped' => $result['skipped']]);
    }

    /** POST strategies/{key}/pause */
    public function pause(Request $request, string $key, PauseStrategy $pause): JsonResponse
    {
        $this->authorizeChange($key);
        $result = $pause->handle($this->current->get(), $key, $this->userId($request));

        return response()->json($result['detail'] + ['paused' => $result['paused']]);
    }

    public static function notReady(StrategyNotReady $e): JsonResponse
    {
        return response()->json([
            'error' => 'needs_setup',
            'message' => $e->getMessage(),
            'requirements' => array_map(fn (Requirement $r) => $r->toArray(), $e->requirements),
            'issues' => $e->issues,
            'to' => $e->to,
        ], 422);
    }

    private function authorizeChange(string $key): void
    {
        $definition = StrategyRegistry::find($key) ?? abort(404);
        foreach (['manageMarketing', ...$definition->abilities] as $ability) {
            Gate::authorize('ws.'.$ability);
        }
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : null;
    }
}
