<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\Spa;

use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Segments\Http\SegmentPresenter;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Borradores de segmentos que propuso un agente por MCP: nadie los usa hasta que alguien con
 * permiso de marketing los aprueba aquí (M19). Las campañas borrador se revisan en Campañas.
 */
final class McpDraftController
{
    /** GET integrations/mcp-drafts */
    public function index(): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');

        return new JsonResponse(['data' => AiDraft::query()->where('kind', 'segment')->where('model', 'mcp')
            ->orderByDesc('created_at')->limit(50)->get()->map(fn (AiDraft $d) => self::present($d))->values()->all()]);
    }

    /** POST integrations/mcp-drafts/{draft}/approve — crea el segmento. */
    public function approve(Request $request, string $draft, SaveSegment $save, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($draft);
        if ($model->status !== AiDraft::DRAFT) {
            return new JsonResponse(['message' => __('Este borrador ya se revisó.')], 422);
        }
        /** @var User $user */
        $user = $request->user();
        $proposal = $model->proposal;
        $segment = $save->handle(null, [
            'name' => (string) ($proposal['name'] ?? __('Segmento propuesto')),
            'description' => $proposal['description'] ?? null,
            'definition' => (array) ($proposal['definition'] ?? []),
        ], $user);
        $model->forceFill(['status' => AiDraft::APPLIED, 'applied_type' => 'segment', 'applied_id' => $segment->id, 'user_id' => $user->id])->save();
        $audit->handle('segment.mcp_draft_approved', $model, ['segment' => $segment->publicId()], $model->workspace_id);

        return new JsonResponse(['data' => self::present($model), 'segment' => SegmentPresenter::make($segment)]);
    }

    /** POST integrations/mcp-drafts/{draft}/discard */
    public function discard(Request $request, string $draft): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($draft);
        if ($model->status === AiDraft::DRAFT) {
            $model->forceFill(['status' => AiDraft::DISCARDED, 'user_id' => $request->user()?->getAuthIdentifier()])->save();
        }

        return new JsonResponse(['data' => self::present($model)]);
    }

    /** @return array<string, mixed> */
    private static function present(AiDraft $d): array
    {
        return [
            'id' => $d->publicId(),
            'status' => $d->status,
            'name' => $d->proposal['name'] ?? null,
            'description' => $d->proposal['description'] ?? null,
            'definition' => $d->proposal['definition'] ?? null,
            'client_id' => $d->proposal['client_id'] ?? null,
            'applied_id' => $d->applied_id !== null ? 'sg_'.$d->applied_id : null,
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }

    private function find(string $publicId): AiDraft
    {
        $draft = AiDraft::findByPublicId($publicId);

        return $draft !== null && $draft->kind === 'segment' && $draft->model === 'mcp' ? $draft : abort(404);
    }
}
