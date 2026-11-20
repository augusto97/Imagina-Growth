<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Billing\Models\Plan;
use App\Domain\Billing\Plans\PlanCatalog;
use App\Domain\Billing\Plans\SavePlan;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Planes en el panel de la plataforma (solo superadmin): crear, editar, archivar y restaurar. No se borran: hay
 * cuentas y facturas que los nombran. Un plan archivado no se asigna a cuentas nuevas ni se muestra al cliente.
 */
final class PlatformPlansController
{
    public function __construct(private readonly SavePlan $save, private readonly RecordAuditLog $audit) {}

    /** GET /api/platform/plans */
    public function index(): JsonResponse
    {
        $counts = Account::query()->whereNotNull('plan_id')->selectRaw('plan_id, COUNT(*) AS n')->groupBy('plan_id')->pluck('n', 'plan_id');

        return response()->json([
            'plans' => Plan::query()->orderBy('metric')->orderBy('price_usd')->get()->map(fn (Plan $p) => $p->toPublicArray() + [
                'public' => $p->public, 'accounts' => (int) ($counts[$p->id] ?? 0),
            ])->all(),
            'modules' => PlanCatalog::modules(),
            'limits' => ['emails', 'ai_budget_usd', 'users', 'api_requests_per_minute'],
        ]);
    }

    /** POST /api/platform/plans */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_]+$/', Rule::unique('plans', 'id')]] + self::rules());
        $plan = $this->save->create($data['id'], self::payload($data), self::actor($request));

        return response()->json(['plan' => $plan->toPublicArray() + ['public' => $plan->public, 'accounts' => 0]], 201);
    }

    /** PATCH /api/platform/plans/{plan} */
    public function update(Request $request, string $plan): JsonResponse
    {
        $model = Plan::query()->findOrFail($plan);
        $data = $request->validate(self::rules());
        $model = $this->save->update($model, self::payload($data), self::actor($request));

        return response()->json(['plan' => $model->toPublicArray() + ['public' => $model->public]]);
    }

    /**
     * POST /api/platform/plans/sync: crea los planes incluidos que falten, sin pisar los editados (lo de
     * `igr:plans:sync --missing`, sin consola).
     */
    public function syncMissing(Request $request, PlanCatalog $catalog): JsonResponse
    {
        $created = $catalog->sync(false);
        if ($created > 0) {
            $this->audit->handle('platform.plans_synced', null, ['created' => $created], null, null, self::actor($request)?->id);
        }

        return response()->json(['created' => $created] + $this->index()->getData(true));
    }

    /** POST /api/platform/plans/{plan}/archive | restore */
    public function archive(Request $request, string $plan, string $action): JsonResponse
    {
        $model = Plan::query()->findOrFail($plan);
        $model->forceFill(['archived_at' => $action === 'archive' ? now() : null])->save();
        $this->audit->handle('platform.plan_'.($action === 'archive' ? 'archived' : 'restored'), $model, ['id' => $model->id], null, null, self::actor($request)?->id);

        return response()->json(['plan' => $model->toPublicArray() + ['public' => $model->public]]);
    }

    /** @return array<string, mixed> */
    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'metric' => ['required', Rule::in(['orders', 'contacts'])],
            'included' => ['required', 'integer', 'min:0', 'max:100000000'],
            'modules' => ['present', 'array'],
            'modules.*' => ['string', Rule::in(PlanCatalog::modules())],
            'limits' => ['present', 'array'],
            'limits.emails' => ['nullable', 'integer', 'min:0'],
            'limits.ai_budget_usd' => ['nullable', 'numeric', 'min:0'],
            'limits.users' => ['nullable', 'integer', 'min:1'],
            'limits.api_requests_per_minute' => ['nullable', 'integer', 'min:1'],
            'price_usd' => ['required', 'numeric', 'min:0'],
            'price_cop' => ['nullable', 'numeric', 'min:0'],
            'public' => ['required', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, metric: string, included: int, modules: list<string>, limits: array<string, int|float|null>, price_usd: float|int|string, price_cop: float|int|string|null, public: bool}
     */
    private static function payload(array $data): array
    {
        $limits = [];
        foreach (['emails', 'ai_budget_usd', 'users', 'api_requests_per_minute'] as $key) {
            $value = $data['limits'][$key] ?? null;
            $limits[$key] = $value === null || $value === '' ? null : ($key === 'ai_budget_usd' ? (float) $value : (int) $value);
        }

        return [
            'name' => (string) $data['name'],
            'metric' => (string) $data['metric'],
            'included' => (int) $data['included'],
            'modules' => array_values(array_map('strval', (array) $data['modules'])),
            'limits' => $limits,
            'price_usd' => (string) $data['price_usd'],
            'price_cop' => isset($data['price_cop']) ? (string) $data['price_cop'] : null,
            'public' => (bool) $data['public'],
        ];
    }

    private static function actor(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }
}
