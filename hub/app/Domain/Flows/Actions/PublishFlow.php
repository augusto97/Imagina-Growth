<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Graph\NodeTypes;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowRun;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Flows\Support\InvalidFlow;
use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * Publica el borrador como versión nueva (hub/CLAUDE.md §7, «Versionado»): se
 * valida, se fijan las plantillas resueltas y los runs nuevos entran con ella.
 * Los runs en curso siguen en su versión; con `migrate` se mueven a la nueva si
 * los nodos donde están existen con el mismo tipo.
 */
final class PublishFlow
{
    public function __construct(
        private readonly GraphValidator $validator,
        private readonly TriggerIndex $index,
        private readonly RecordAuditLog $audit,
    ) {}

    /**
     * @return array{version: FlowVersion, migrated: int}
     *
     * @throws InvalidFlow
     */
    public function handle(Flow $flow, ?string $userId = null, bool $migrate = false): array
    {
        $draft = $flow->draftVersion() ?? throw new InvalidFlow(['graph' => [__('No hay cambios para publicar.')]]);
        $graph = $draft->parsed();
        $errors = $this->validator->validate($graph, $flow->workspace_id);
        if ($errors !== []) {
            $out = [];
            foreach ($errors as $node => $messages) {
                $out[$node === '' ? 'graph' : 'graph.nodes.'.$node] = $messages;
            }
            throw new InvalidFlow($out);
        }
        $draft->forceFill(['graph' => $this->pinTemplates($graph, $flow->workspace_id), 'published_at' => now(), 'published_by' => $userId])->save();
        $flow->forceFill(['current_version_id' => $draft->id])->save();

        $migrated = $migrate ? $this->migrate($flow, $draft) : 0;
        $this->index->forget($flow->workspace_id);
        $this->audit->handle('flow.published', $flow, ['version' => $draft->version, 'migrated' => $migrated], $flow->workspace_id);

        return ['version' => $draft, 'migrated' => $migrated];
    }

    /**
     * Guarda en cada envío el `template_id` resuelto (la `template_key` queda como referencia).
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    private function pinTemplates(Graph $graph, string $workspaceId): array
    {
        $data = $graph->toArray();
        foreach ($data['nodes'] as $i => $node) {
            $channel = NodeTypes::channelOf((string) $node['type']);
            if ($channel === null) {
                continue;
            }
            $id = TemplateRef::resolve($workspaceId, $channel, $node['config']['template_id'] ?? null, $node['config']['template_key'] ?? null);
            if ($id !== null) {
                $data['nodes'][$i]['config']['template_id'] = TemplateRef::encode($id);
            }
        }

        return $data;
    }

    /** Mueve a la versión nueva los runs vivos de versiones anteriores cuyo nodo actual sigue igual. */
    private function migrate(Flow $flow, FlowVersion $to): int
    {
        $new = $to->parsed();
        $graphs = [];
        $count = 0;
        FlowRun::query()->where('flow_id', $flow->id)->where('flow_version_id', '!=', $to->id)->whereIn('status', FlowRun::LIVE)->chunkById(500, function ($runs) use ($new, $to, &$graphs, &$count): void {
            foreach ($runs as $run) {
                $graphs[$run->flow_version_id] ??= FlowVersion::query()->find($run->flow_version_id)?->parsed();
                $old = $graphs[$run->flow_version_id];
                $node = (string) $run->current_node_id;
                if ($old !== null && $node !== '' && $new->compatibleWith($old, [$node])) {
                    $run->forceFill(['flow_version_id' => $to->id])->save();
                    $count++;
                }
            }
        });

        return $count;
    }
}
