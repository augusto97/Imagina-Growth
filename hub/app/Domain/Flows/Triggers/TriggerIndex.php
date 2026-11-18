<?php

declare(strict_types=1);

namespace App\Domain\Flows\Triggers;

use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * Índice en Redis de los flujos activos por evento (hub/CLAUDE.md §7):
 *
 * - `ws:{id}:triggers:{evento}` → JSON [{flow, filters}] de los flujos que entran con ese evento.
 * - `ws:{id}:triggers:@wake` → conjunto de eventos que importan a runs vivos (metas y
 *   «esperar hasta un evento»): solo con ellos se consulta flow_runs.
 * - `ws:{id}:triggers:@events` → eventos indexados (para borrar los viejos al reconstruir).
 * - `ws:{id}:triggers:@built` → marca de índice construido; sin ella se reconstruye.
 *
 * Se reconstruye al activar, pausar, archivar, publicar o editar el disparador o
 * la meta de un flujo. Así cada evento ingerido cuesta una o dos lecturas de Redis.
 */
final class TriggerIndex
{
    private static function key(string $workspaceId, string $suffix): string
    {
        return 'ws:'.$workspaceId.':triggers:'.$suffix;
    }

    /**
     * @return array{triggers: list<array{flow: string, filters: list<mixed>}>, wake: bool}
     */
    public function lookup(string $workspaceId, string $event): array
    {
        $redis = Redis::connection();
        if (! $redis->exists(self::key($workspaceId, '@built'))) {
            $this->rebuild($workspaceId);
        }
        $raw = $redis->get(self::key($workspaceId, $event));
        $triggers = is_string($raw) ? json_decode($raw, true) : [];

        return [
            'triggers' => is_array($triggers) ? array_values($triggers) : [],
            'wake' => (bool) $redis->sismember(self::key($workspaceId, '@wake'), $event),
        ];
    }

    public function rebuild(string $workspaceId): void
    {
        $flows = Flow::withoutWorkspaceScope()->where('workspace_id', $workspaceId)->where('status', Flow::ACTIVE)->get();
        $byEvent = [];
        $wake = [];
        foreach ($flows as $flow) {
            $event = $flow->triggerEvent();
            if ($event !== null) {
                $filters = array_values((array) ($flow->trigger['filters'] ?? []));
                if (($flow->trigger['type'] ?? null) === 'segment') {
                    $filters[] = ['prop' => 'segment_id', 'operator' => 'eq', 'value' => (string) ($flow->trigger['segment_id'] ?? '')];
                }
                $byEvent[$event][] = ['flow' => $flow->id, 'filters' => $filters];
            }
            if ($flow->goalEvent() !== null) {
                $wake[] = $flow->goalEvent();
            }
            if ($flow->current_version_id !== null) {
                $graph = FlowVersion::withoutWorkspaceScope()->find($flow->current_version_id)?->parsed();
                foreach ($graph?->nodesOfType(NodeTypes::WAIT_EVENT) ?? [] as $node) {
                    $wake[] = (string) ($node['config']['event'] ?? '');
                }
            }
        }
        // Runs que esperan un evento en versiones viejas o de flujos pausados.
        $waiting = DB::table('flow_runs')->where('workspace_id', $workspaceId)->where('status', FlowRun::WAITING)
            ->whereNotNull('waiting_for')->distinct()->pluck('waiting_for')->all();
        $wake = array_values(array_unique(array_filter([...$wake, ...array_map('strval', $waiting)])));

        $redis = Redis::connection();
        $old = (array) $redis->smembers(self::key($workspaceId, '@events'));
        foreach ($old as $event) {
            $redis->del(self::key($workspaceId, (string) $event));
        }
        $redis->del(self::key($workspaceId, '@events'), self::key($workspaceId, '@wake'));
        foreach ($byEvent as $event => $entries) {
            $redis->set(self::key($workspaceId, (string) $event), (string) json_encode($entries));
            $redis->sadd(self::key($workspaceId, '@events'), (string) $event);
        }
        if ($wake !== []) {
            $redis->sadd(self::key($workspaceId, '@wake'), ...$wake);
        }
        $redis->set(self::key($workspaceId, '@built'), (string) now()->getTimestamp());
    }

    /** Un run empezó a esperar `event`: desde ya importa. */
    public function addWake(string $workspaceId, string $event): void
    {
        Redis::connection()->sadd(self::key($workspaceId, '@wake'), $event);
    }

    /** Fuerza la reconstrucción en el siguiente evento. */
    public function forget(string $workspaceId): void
    {
        Redis::connection()->del(self::key($workspaceId, '@built'));
    }
}
