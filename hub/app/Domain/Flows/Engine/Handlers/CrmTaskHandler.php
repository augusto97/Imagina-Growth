<?php

declare(strict_types=1);

namespace App\Domain\Flows\Engine\Handlers;

use App\Domain\Flows\Engine\NodeHandler;
use App\Domain\Flows\Engine\Outcome;
use App\Domain\Flows\Engine\RunState;
use App\Domain\Flows\Graph\Durations;
use App\Domain\Services\Leads\Brief\UpdateLeadBrief;
use App\Domain\Services\Leads\Models\LeadActivity;
use App\Domain\Services\Leads\Models\LeadTask;
use App\Domain\Workspaces\Models\User;
use App\Support\Ids\PrefixedId;

/**
 * «Crear tarea» (M09): una tarea o llamada para el vendedor que aparece en «Hoy»,
 * ligada al lead abierto del contacto (si hay). Va al vendedor del lead o a la
 * persona elegida en el nodo; sin vendedor, la ven quienes ven todo. Vence al
 * pasar la espera del nodo (hoy si no tiene). La clave del paso evita duplicarla en
 * un reintento. El grupo de control y las pruebas no crean tareas.
 */
final class CrmTaskHandler implements NodeHandler
{
    public function handle(RunState $state, array $node): Outcome
    {
        if ($state->isHoldout()) {
            return Outcome::next(record: 'skipped_holdout');
        }
        if ($state->isTest()) {
            return Outcome::next(details: ['test' => true]);
        }
        $c = $node['config'];
        $lead = UpdateLeadBrief::openLead($state->run->contact_id);
        $owner = ($c['assign'] ?? 'lead_owner') === 'user' ? PrefixedId::decode(User::ID_PREFIX, (string) ($c['user_id'] ?? '')) : $lead?->owner_id;
        $due = Durations::after($state->now, $c['due_in'] ?? null) ?? $state->now;
        $ref = $state->idempotencyKey($node['id']);
        $task = LeadTask::query()->where('source_ref', $ref)->first() ?? LeadTask::query()->create([
            'workspace_id' => $state->workspace->id,
            'lead_id' => $lead?->id,
            'contact_id' => $state->run->contact_id,
            'owner_id' => $owner,
            'kind' => in_array($c['kind'] ?? 'task', LeadTask::KINDS, true) ? (string) $c['kind'] : 'task',
            'title' => mb_substr(trim((string) ($c['title'] ?? '')), 0, 160),
            'due_at' => $due,
            'status' => 'open',
            'source' => 'flow',
            'source_ref' => $ref,
        ]);
        if ($lead !== null && $task->wasRecentlyCreated) {
            LeadActivity::log($lead, 'task', 'system', ['task' => $task->publicId(), 'kind' => $task->kind, 'flow' => $state->flow->publicId()]);
        }

        return Outcome::next(details: ['task_id' => $task->publicId(), 'lead_id' => $lead?->publicId()]);
    }
}
