<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Intelligence\Models\Insight;
use App\Domain\Mcp\Support\McpContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Hallazgos abiertos del negocio (problemas y oportunidades detectados automáticamente) con su gravedad, el impacto estimado en dinero, los hechos que lo sustentan y la acción sugerida. Ordenados por gravedad e impacto.')]
#[IsReadOnly]
final class ListInsightsTool extends Tool
{
    protected string $name = 'list_insights';

    public function handle(Request $request): Response
    {
        if (($denied = McpContext::deny('analytics:read')) !== null) {
            return $denied;
        }
        $limit = max(1, min(50, (int) ($request->get('limit') ?? 10)));
        $insights = Insight::query()->whereIn('status', ['new', 'seen'])->get()
            ->sortByDesc(fn (Insight $i) => [Insight::SEVERITY_ORDER[$i->severity] ?? 0, (float) $i->estimated_impact_ws])
            ->take($limit)
            ->map(fn (Insight $i) => [
                'id' => $i->publicId(),
                'severity' => $i->severity,
                'title' => $i->title,
                'detector' => $i->detector_key,
                'estimated_impact' => $i->estimated_impact_ws,
                'facts' => $i->facts,
                'recommended_action' => $i->recommended_action,
                'detected_at' => $i->created_at?->toIso8601String(),
                'panel_url' => McpContext::panelUrl('hallazgos/'.$i->publicId()),
            ])->values()->all();

        return Response::json(['currency' => McpContext::workspace()->currency, 'insights' => $insights]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['limit' => $schema->integer()->description('Máximo de hallazgos (1 a 50).')];
    }
}
