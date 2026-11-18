<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Contacts\Models\Contact;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\HubEvents;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Entrada de un contacto a un flujo (hub/CLAUDE.md §7, «Entrada»):
 *
 * 1. El flujo está activo y tiene una versión publicada.
 * 2. Política de reentrada: una vez, cada N días o siempre; nunca dos runs vivos
 *    del mismo flujo para el mismo contacto.
 * 3. Exclusión mutua: no entra si está vivo en un flujo excluyente.
 * 4. Sorteo del grupo de control (`holdout_pct`).
 * 5. `trigger_key` única por flujo: el mismo evento nunca crea dos runs.
 *
 * Emite flow.entered (salvo en las pruebas). Devuelve null si no entra.
 */
final class EnterFlow
{
    public function __construct(private readonly HubEvents $events) {}

    /** @param array<string, mixed> $context */
    public function handle(Flow $flow, string $contactId, string $triggerKey, array $context, bool $test = false, ?FlowVersion $version = null): ?FlowRun
    {
        if (! $test && ! $flow->isActive()) {
            return null;
        }
        $version ??= $flow->currentVersion();
        $entry = $version?->parsed()->entry();
        if ($version === null || $entry === null) {
            return null;
        }
        $contact = Contact::query()->find($contactId);
        if ($contact === null) {
            return null;
        }
        if ($contact->getAttribute('merged_into_id') !== null) {
            $contactId = (string) $contact->getAttribute('merged_into_id');
        }

        $lock = Cache::lock('flowenter:'.$flow->id.':'.$contactId, 10);
        try {
            $lock->block(5);
        } catch (Throwable) {
            return null;
        }
        try {
            if (! $test && ! $this->allowed($flow, $contactId)) {
                return null;
            }
            $holdout = ! $test && $flow->holdout_pct > 0 && random_int(1, 100) <= $flow->holdout_pct;
            try {
                $run = FlowRun::query()->create([
                    'workspace_id' => $flow->workspace_id,
                    'flow_id' => $flow->id,
                    'flow_version_id' => $version->id,
                    'contact_id' => $contactId,
                    'status' => FlowRun::ACTIVE,
                    'current_node_id' => $entry,
                    'next_run_at' => now(),
                    'context' => $context + ['_attempt' => [$entry => 1]],
                    'trigger_key' => mb_substr($triggerKey, 0, 190),
                    'is_holdout' => $holdout,
                    'started_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                return null; // el mismo disparador ya creó su run
            }
        } finally {
            $lock->release();
        }

        if (! $test) {
            $workspace = Workspace::query()->find($flow->workspace_id);
            if ($workspace !== null) {
                try {
                    $this->events->emit($workspace, 'flow.entered', array_filter([
                        'flow_id' => $flow->publicId(),
                        'flow_name' => mb_substr($flow->name, 0, 200),
                        'version' => $version->version,
                        'run_id' => $run->publicId(),
                        'is_holdout' => $holdout,
                        'trigger' => isset($context['trigger']) ? mb_substr((string) $context['trigger'], 0, 64) : null,
                        'template_key' => $flow->template_key,
                    ], fn ($v) => $v !== null), $contactId);
                } catch (Throwable $e) {
                    report($e);
                }
            }
        }

        return $run;
    }

    private function allowed(Flow $flow, string $contactId): bool
    {
        // Las pruebas y las salidas por el filtro de entrada no cuentan para la reentrada.
        $previous = FlowRun::query()->where('flow_id', $flow->id)->where('contact_id', $contactId)
            ->where(fn ($q) => $q->whereNull('trigger_key')->orWhere('trigger_key', 'not like', 'test:%'))
            ->where(fn ($q) => $q->whereNull('exit_reason')->orWhere('exit_reason', '!=', 'filter'));
        if ((clone $previous)->whereIn('status', FlowRun::LIVE)->exists()) {
            return false;
        }
        $policy = (array) ($flow->reentry_policy ?? ['mode' => 'once']);
        $mode = $policy['mode'] ?? 'once';
        if ($mode === 'once' && (clone $previous)->exists()) {
            return false;
        }
        if ($mode === 'every_days' && (clone $previous)->where('started_at', '>=', now()->subDays(max(1, (int) ($policy['days'] ?? 30))))->exists()) {
            return false;
        }
        $exclusions = array_values(array_filter((array) ($flow->exclusions ?? []), 'is_string'));
        if ($exclusions !== [] && FlowRun::query()->whereIn('flow_id', $exclusions)->where('contact_id', $contactId)->whereIn('status', FlowRun::LIVE)->exists()) {
            return false;
        }

        return true;
    }
}
