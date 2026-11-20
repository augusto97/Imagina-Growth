<?php

declare(strict_types=1);

namespace App\Domain\Billing\Http;

use App\Domain\Workspaces\Actions\CreateClientAccount;
use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Actions\InviteToWorkspace;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\AccountRole;
use App\Domain\Workspaces\Enums\AccountType;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\Invitation;
use App\Domain\Workspaces\Models\User;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Clientes en el panel de la plataforma (solo superadmin): dar de alta una empresa con su espacio, su plan y la
 * invitación al dueño; ver su detalle (espacios, personas, invitaciones); editar sus datos; agregar espacios e
 * invitar personas. Todo queda en audit_logs.
 */
final class PlatformClientsController
{
    private const COUNTRIES = ['CO', 'MX', 'VE', 'PE', 'CL', 'EC', 'AR', 'US', 'ES', 'PA', 'CR', 'GT', 'DO'];

    private const CURRENCIES = ['COP', 'MXN', 'USD', 'PEN', 'CLP', 'ARS', 'EUR', 'VES'];

    public function __construct(private readonly RecordAuditLog $audit) {}

    /** POST /api/platform/accounts */
    public function store(Request $request, CreateClientAccount $create): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'type' => ['required', Rule::in([AccountType::Direct->value, AccountType::Agency->value])],
            'country' => ['required', Rule::in(self::COUNTRIES)],
            'billing_email' => ['nullable', 'email', 'max:190'],
            'plan_id' => ['nullable', 'string', Rule::exists('plans', 'id')->whereNull('archived_at')],
            'owner_email' => ['required', 'email', 'max:190'],
        ] + self::workspaceRules('workspace.'));

        $result = $create->handle(self::actor($request), $data, self::workspaceData($data['workspace']));

        return response()->json(['account' => $this->detail($result['account'])], 201);
    }

    /** GET /api/platform/accounts/{account} */
    public function show(string $account): JsonResponse
    {
        return response()->json(['account' => $this->detail($this->account($account))]);
    }

    /** PATCH /api/platform/accounts/{account} */
    public function update(Request $request, string $account): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'billing_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'country' => ['sometimes', Rule::in(self::COUNTRIES)],
            'type' => ['sometimes', Rule::in([AccountType::Direct->value, AccountType::Agency->value])],
        ]);
        $model->fill($data)->save();
        $this->audit->handle('platform.client_updated', $model, ['fields' => array_keys($model->getChanges())], null, $model->id, self::actor($request)->id);

        return response()->json(['account' => $this->detail($model)]);
    }

    /** POST /api/platform/accounts/{account}/workspaces */
    public function addWorkspace(Request $request, string $account, CreateWorkspace $create, InviteToWorkspace $invite): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate(self::workspaceRules('') + ['owner_email' => ['nullable', 'email', 'max:190']]);
        $workspace = $create->createInAccount($model, self::workspaceData($data), null, self::actor($request));
        if (! empty($data['owner_email'])) {
            $invite->handle($workspace, self::actor($request), (string) $data['owner_email'], WorkspaceRole::Owner);
        }

        return response()->json(['account' => $this->detail($model)], 201);
    }

    /** POST /api/platform/accounts/{account}/invitations */
    public function invite(Request $request, string $account, InviteToWorkspace $invite): JsonResponse
    {
        $model = $this->account($account);
        $data = $request->validate([
            'workspace_id' => ['required', 'string'],
            'email' => ['required', 'email', 'max:190'],
            'role' => ['required', Rule::in(array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::cases()))],
            'account_owner' => ['sometimes', 'boolean'],
        ]);
        $workspace = Workspace::findByPublicId($data['workspace_id']);
        abort_if($workspace === null || $workspace->account_id !== $model->id, 404);
        $accountRole = ($data['account_owner'] ?? false) && $model->type === AccountType::Agency ? AccountRole::AgencyOwner : null;
        $invite->handle($workspace, self::actor($request), $data['email'], WorkspaceRole::from($data['role']), $accountRole);

        return response()->json(['account' => $this->detail($model)], 201);
    }

    /** @return array<string, mixed> */
    private function detail(Account $account): array
    {
        $workspaces = Workspace::query()->where('account_id', $account->id)->orderBy('name')->get();
        $wsIds = $workspaces->pluck('id');
        $sites = DB::table('sites')->whereIn('workspace_id', $wsIds)->selectRaw('workspace_id, COUNT(*) AS n')->groupBy('workspace_id')->pluck('n', 'workspace_id');
        $members = DB::table('workspace_user')->join('users', 'users.id', '=', 'workspace_user.user_id')
            ->whereIn('workspace_user.workspace_id', $wsIds)
            ->get(['workspace_user.workspace_id', 'workspace_user.role', 'users.name', 'users.email'])->groupBy('workspace_id');
        $accountUsers = DB::table('account_user')->join('users', 'users.id', '=', 'account_user.user_id')
            ->where('account_user.account_id', $account->id)->get(['account_user.role', 'users.name', 'users.email']);
        $invitations = Invitation::withoutWorkspaceScope()->whereIn('workspace_id', $wsIds)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->get()->groupBy('workspace_id');
        $billing = DB::table('accounts')->where('id', $account->id)->first(['billing_status', 'grace_ends_at']);

        return [
            'id' => $account->publicId(),
            'name' => $account->name,
            'type' => $account->type->value,
            'legal_name' => $account->legal_name,
            'country' => $account->country,
            'billing_email' => $account->billing_email,
            'plan_id' => $account->plan_id,
            'billing_status' => (string) ($billing->billing_status ?? 'active'),
            'created_at' => $account->created_at?->toIso8601String(),
            'account_users' => $accountUsers->map(fn ($u) => ['name' => $u->name, 'email' => $u->email, 'role' => $u->role])->values()->all(),
            'workspaces' => $workspaces->map(fn (Workspace $ws) => [
                'id' => $ws->publicId(),
                'name' => $ws->name,
                'country' => $ws->country,
                'currency' => $ws->currency,
                'sites' => (int) ($sites[$ws->id] ?? 0),
                'created_at' => $ws->created_at?->toIso8601String(),
                'members' => $members->get($ws->id, collect())->map(fn ($m) => ['name' => $m->name, 'email' => $m->email, 'role' => $m->role])->values()->all(),
                'invitations' => $invitations->get($ws->id, collect())->map(fn (Invitation $i) => [
                    'email' => $i->email, 'role' => $i->role->value, 'expires_at' => $i->expires_at?->toIso8601String(),
                ])->values()->all(),
            ])->all(),
        ];
    }

    /** @return array<string, list<mixed>> */
    private static function workspaceRules(string $prefix): array
    {
        return [
            $prefix.'name' => ['required', 'string', 'min:2', 'max:120'],
            $prefix.'country' => ['required', Rule::in(self::COUNTRIES)],
            $prefix.'currency' => ['required', Rule::in(self::CURRENCIES)],
            $prefix.'timezone' => ['required', 'timezone'],
            $prefix.'industry' => ['nullable', 'string', 'max:40'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private static function workspaceData(array $data): WorkspaceData
    {
        $country = (string) $data['country'];

        return new WorkspaceData(
            name: (string) $data['name'],
            country: $country,
            timezone: (string) $data['timezone'],
            currency: (string) $data['currency'],
            locale: in_array($country, ['MX', 'VE'], true) ? 'es-'.$country : 'es-CO',
            industry: isset($data['industry']) && $data['industry'] !== '' ? (string) $data['industry'] : null,
        );
    }

    private function account(string $publicId): Account
    {
        return Account::findByPublicId($publicId) ?? abort(404);
    }

    private static function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
