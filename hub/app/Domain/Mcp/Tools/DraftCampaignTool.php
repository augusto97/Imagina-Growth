<?php

declare(strict_types=1);

namespace App\Domain\Mcp\Tools;

use App\Domain\Api\Http\V1\CampaignsController;
use App\Domain\Api\Http\V1\Presenters;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Mcp\Support\McpContext;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Crea un BORRADOR de campaña (correo, WhatsApp o SMS) para uno o más segmentos. No envía nada: alguien del equipo revisa el contenido, el cumplimiento y el horario, y la envía desde el panel en el enlace que devuelve.')]
final class DraftCampaignTool extends Tool
{
    protected string $name = 'draft_campaign';

    public function handle(Request $request, RecordAuditLog $audit): Response
    {
        if (($denied = McpContext::deny('campaigns:write')) !== null) {
            return $denied;
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', 'in:'.implode(',', Campaign::CHANNELS)],
            'segment_ids' => ['nullable', 'array', 'max:10'],
            'segment_ids.*' => ['string', 'max:40'],
        ]);
        $client = McpContext::client();
        $campaign = CampaignsController::createDraft(McpContext::workspace()->id, (string) $data['name'], (string) $data['channel'], (array) ($data['segment_ids'] ?? []), 'mcp:'.($client?->publicId() ?? '-'), $audit);
        if (is_string($campaign)) {
            return Response::error($campaign);
        }

        return Response::json([
            'campaign' => Presenters::campaign($campaign),
            'approval_url' => CampaignsController::panelUrl($campaign),
            'note' => __('Es un borrador: no se envió nada. Pide a la persona que lo revise y lo envíe desde el panel.'),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->max(160)->description('Nombre interno de la campaña.')->required(),
            'channel' => $schema->string()->enum(Campaign::CHANNELS)->description('Canal.')->required(),
            'segment_ids' => $schema->array()->items($schema->string())->max(10)->description('Segmentos de la audiencia (sg_…), de list_segments.'),
        ];
    }
}
