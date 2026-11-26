<?php

declare(strict_types=1);

namespace App\Domain\Onboarding\Http;

use App\Domain\Onboarding\Actions\ActivateRecommendedStrategies;
use App\Domain\Onboarding\Actions\UpdateOnboarding;
use App\Domain\Onboarding\Models\WorkspaceOnboarding;
use App\Domain\Onboarding\Queries\OnboardingState;
use App\Domain\Strategies\StrategyRegistry;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * El asistente de configuración (docs/diseno/rediseno-v2.md §4). Ver: cualquier miembro (la bienvenida es de cada
 * persona). Cambiar el tipo de negocio, el paso, saltarlo o terminarlo: `manageWorkspace`. Prender las estrategias
 * recomendadas: `manageMarketing` (y el permiso propio de cada estrategia).
 */
final class OnboardingController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly OnboardingState $state) {}

    /** GET onboarding */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');

        return response()->json($this->state->handle($this->current->get(), $this->user($request)));
    }

    /** PUT onboarding {business_type?, current_step?, action?: skip|resume|complete, welcome_seen?} */
    public function update(Request $request, UpdateOnboarding $update): JsonResponse
    {
        Gate::authorize('ws.viewWorkspace');
        $data = $request->validate([
            'business_type' => ['sometimes', 'nullable', Rule::in(WorkspaceOnboarding::BUSINESS_TYPES)],
            'current_step' => ['sometimes', 'nullable', Rule::in(WorkspaceOnboarding::STEPS)],
            'action' => ['sometimes', 'nullable', Rule::in(UpdateOnboarding::ACTIONS)],
            'welcome_seen' => ['sometimes', 'boolean'],
        ]);
        $user = $this->user($request);
        $workspaceChanges = array_intersect_key($data, array_flip(['business_type', 'current_step', 'action']));
        if (array_filter($workspaceChanges, fn ($v) => $v !== null) !== []) {
            Gate::authorize('ws.manageWorkspace');
            $update->handle($this->current->get(), $workspaceChanges, $user?->id);
        }
        if (array_key_exists('welcome_seen', $data) && $user !== null) {
            $update->welcome($this->current->get(), $user->id, (bool) $data['welcome_seen']);
        }

        return response()->json($this->state->handle($this->current->get(), $user));
    }

    /** POST onboarding/strategies {keys?: string[]} */
    public function strategies(Request $request, ActivateRecommendedStrategies $activate): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'keys' => ['sometimes', 'nullable', 'array', 'max:10'],
            'keys.*' => ['string', Rule::in(StrategyRegistry::keys())],
        ]);
        $user = $this->user($request);
        $results = $activate->handle($this->current->get(), isset($data['keys']) ? array_values($data['keys']) : null, $user?->id);

        return response()->json(['results' => $results, 'onboarding' => $this->state->handle($this->current->get(), $user)]);
    }

    private function user(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
