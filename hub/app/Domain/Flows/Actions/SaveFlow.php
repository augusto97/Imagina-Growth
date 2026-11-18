<?php

declare(strict_types=1);

namespace App\Domain\Flows\Actions;

use App\Domain\Flows\Graph\Graph;
use App\Domain\Flows\Graph\GraphValidator;
use App\Domain\Flows\Models\Flow;
use App\Domain\Flows\Models\FlowVersion;
use App\Domain\Flows\Support\FlowRules;
use App\Domain\Flows\Triggers\TriggerIndex;
use App\Support\Tenancy\CurrentWorkspace;

/**
 * Crea o actualiza un flujo y su borrador. El grafo del borrador se guarda
 * aunque tenga errores (se puede seguir editando); los errores se devuelven y
 * bloquean solo al publicar.
 */
final class SaveFlow
{
    public function __construct(
        private readonly FlowRules $rules,
        private readonly GraphValidator $validator,
        private readonly TriggerIndex $index,
        private readonly CurrentWorkspace $current,
    ) {}

    /**
     * @param  array<string, mixed>  $data  name, description, trigger, goal, reentry_policy, exclusions, holdout_pct, graph
     * @return array{flow: Flow, issues: array<string, list<string>>}
     */
    public function handle(?Flow $flow, array $data, ?string $userId = null): array
    {
        $settings = $this->rules->normalize($data, $flow);
        foreach (['name', 'description', 'template_key'] as $field) {
            if (array_key_exists($field, $data)) {
                $settings[$field] = $data[$field] === null ? null : mb_substr(trim((string) $data[$field]), 0, $field === 'description' ? 500 : 160);
            }
        }
        if ($flow === null) {
            $flow = Flow::query()->create($settings + [
                'workspace_id' => $this->current->id(),
                'name' => __('Flujo sin nombre'),
                'status' => Flow::DRAFT,
                'trigger' => ['type' => 'manual'],
                'reentry_policy' => ['mode' => 'once'],
                'holdout_pct' => 0,
                'created_by' => $userId,
            ]);
        } elseif ($settings !== []) {
            $flow->forceFill($settings)->save();
        }
        if (array_key_exists('graph', $data) || $flow->draftVersion() === null && $flow->current_version_id === null) {
            $this->saveDraft($flow, (array) ($data['graph'] ?? self::emptyGraph()));
        }
        if ($flow->isActive() && array_intersect(array_keys($settings), ['trigger', 'goal']) !== []) {
            $this->index->forget($flow->workspace_id);
        }

        return ['flow' => $flow, 'issues' => $this->issues($flow)];
    }

    /** @param array<mixed> $graph */
    public function saveDraft(Flow $flow, array $graph): FlowVersion
    {
        $clean = Graph::fromArray($graph)->toArray();
        $draft = $flow->draftVersion();
        if ($draft !== null) {
            $draft->forceFill(['graph' => $clean])->save();

            return $draft;
        }
        $next = (int) FlowVersion::query()->where('flow_id', $flow->id)->max('version') + 1;

        return FlowVersion::query()->create(['workspace_id' => $flow->workspace_id, 'flow_id' => $flow->id, 'version' => $next, 'graph' => $clean]);
    }

    /** @return array<string, list<string>> errores del borrador (o de la versión vigente si no hay borrador) */
    public function issues(Flow $flow): array
    {
        $version = $flow->draftVersion() ?? $flow->currentVersion();

        return $version === null ? [] : $this->validator->validate($version->parsed(), $flow->workspace_id);
    }

    /** @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>} */
    public static function emptyGraph(): array
    {
        return ['nodes' => [['id' => 'trigger', 'type' => 'trigger', 'config' => [], 'position' => ['x' => 0, 'y' => 0]]], 'edges' => []];
    }
}
