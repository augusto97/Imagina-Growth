<?php

declare(strict_types=1);

namespace App\Http\Controllers\Spa;

use App\Domain\Workspaces\Actions\CreateWorkspace;
use App\Domain\Workspaces\Actions\UpdateWorkspaceSettings;
use App\Domain\Workspaces\Data\WorkspaceData;
use App\Domain\Workspaces\Enums\WorkspaceRole;
use App\Domain\Workspaces\Models\Account;
use App\Domain\Workspaces\Models\User;
use App\Http\Controllers\Spa\Concerns\Presenters;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class WorkspaceController
{
    /** POST /api/workspaces */
    public function store(Request $request, CreateWorkspace $create): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = WorkspaceData::validateAndCreate($request->except('account_id'));
        $account = $request->filled('account_id') ? Account::findByPublicId((string) $request->input('account_id')) : null;
        if ($request->filled('account_id') && $account === null) {
            return response()->json(['message' => __('La cuenta no existe.')], 422);
        }
        $workspace = $create->handle($user, $data, $account);

        return response()->json(Presenters::workspace($workspace, WorkspaceRole::Owner), 201);
    }

    /** GET /api/w/{workspace} */
    public function show(CurrentWorkspace $current): JsonResponse
    {
        return response()->json(Presenters::workspace($current->get(), $current->role()));
    }

    /** PATCH /api/w/{workspace} */
    public function update(Request $request, CurrentWorkspace $current, UpdateWorkspaceSettings $update): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'country' => ['sometimes', 'string', 'size:2'],
            'timezone' => ['sometimes', 'timezone'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'locale' => ['sometimes', 'in:es-CO,es-MX,es-VE'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:40'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'privacy_policy_url' => ['sometimes', 'nullable', 'url', 'max:2048'],
        ]);
        $workspace = $update->handle($current->get(), $data);

        return response()->json(Presenters::workspace($workspace, $current->role()));
    }
}
