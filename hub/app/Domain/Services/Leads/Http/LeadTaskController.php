<?php

declare(strict_types=1);

namespace App\Domain\Services\Leads\Http;

use App\Domain\Services\Leads\Models\Lead;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tareas y llamadas de un lead (M18, salen en «Hoy»). Crear, completar o cambiar la
 * fecha; el rol agente solo en sus leads y sin asignarlas a otra persona.
 */
final class LeadTaskController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** POST leads/{lead}/tasks {title, kind, due_at, note?, owner_id?} */
    public function store(Request $request, string $lead): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $l = $this->lead($request, $lead);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'kind' => ['sometimes', Rule::in(LeadTask::KINDS)],
            'due_at' => ['required', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        $me = (string) $request->user()?->getAuthIdentifier();
        $owner = $l->owner_id ?? $me;
        if (isset($data['owner_id'])) {
            if (! Gate::allows('ws.viewAllContacts')) {
                abort(403, __('Solo un dueño, administrador o mercadeo puede asignar tareas a otra persona.'));
            }
            $owner = $this->member((string) $data['owner_id']);
        }
        $task = LeadTask::query()->create([
            'workspace_id' => $this->current->id(), 'lead_id' => $l->id, 'contact_id' => $l->contact_id, 'owner_id' => $owner,
            'kind' => $data['kind'] ?? 'task', 'title' => trim($data['title']), 'note' => isset($data['note']) && trim((string) $data['note']) !== '' ? trim((string) $data['note']) : null,
            'due_at' => now()->parse((string) $data['due_at']), 'status' => 'open', 'source' => 'user', 'created_by' => $me,
        ]);
        LeadActivity::log($l, 'task', $me, ['task' => $task->publicId(), 'kind' => $task->kind]);

        return response()->json(self::present($task), 201);
    }

    /** PATCH leads/tasks/{task} {status?, due_at?} */
    public function update(Request $request, string $task): JsonResponse
    {
        Gate::authorize('ws.useLeads');
        $t = LeadTask::findByPublicId($task) ?? abort(404);
        $me = (string) $request->user()?->getAuthIdentifier();
        if (! Gate::allows('ws.viewAllContacts') && $t->owner_id !== $me) {
            abort(404);
        }
        $data = $request->validate(['status' => ['sometimes', Rule::in(['open', 'done'])], 'due_at' => ['sometimes', 'date']]);
        if (isset($data['status'])) {
            $t->status = $data['status'];
            $t->done_at = $data['status'] === 'done' ? now() : null;
            $t->done_by = $data['status'] === 'done' ? $me : null;
        }
        if (isset($data['due_at'])) {
            $t->due_at = now()->parse((string) $data['due_at']);
        }
        $t->save();

        return response()->json(self::present($t));
    }

    /** @return array<string, mixed> */
    public static function present(LeadTask $t): array
    {
        return [
            'id' => $t->publicId(), 'kind' => $t->kind, 'title' => $t->title, 'note' => $t->note, 'status' => $t->status, 'source' => $t->source,
            'due_at' => $t->due_at->toIso8601String(), 'done_at' => $t->done_at?->toIso8601String(),
            'owner_id' => $t->owner_id !== null ? PrefixedId::encode(User::ID_PREFIX, $t->owner_id) : null,
        ];
    }

    private function lead(Request $request, string $publicId): Lead
    {
        $lead = Lead::findByPublicId($publicId) ?? abort(404);
        if (! LeadController::canOpen($lead, (string) $request->user()?->getAuthIdentifier())) {
            abort(404);
        }

        return $lead;
    }

    private function member(string $publicId): string
    {
        $id = PrefixedId::decode(User::ID_PREFIX, $publicId);
        if ($id === null || ! $this->current->get()->users()->where('users.id', $id)->exists()) {
            throw ValidationException::withMessages(['owner_id' => __('Esa persona no es del equipo.')]);
        }

        return $id;
    }
}
