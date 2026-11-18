<?php

declare(strict_types=1);

namespace App\Domain\Api\Http\V1;

use Dedoc\Scramble\Attributes\Group;
use App\Domain\Api\Support\ApiContext;
use App\Domain\Api\Support\ApiError;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Campañas (M10): la API solo crea borradores. Enviar o programar se aprueba en el panel,
 * donde pasan el chequeo de cumplimiento (consentimiento, horario legal y frecuencia).
 */
#[Group('Campañas', weight: 5)]
final class CampaignsController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * Crear un borrador de campaña
     *
     * `name`, `channel` (email, whatsapp o sms) y, si quieres, `segment_ids` para la audiencia.
     * Queda en borrador: alguien del equipo la revisa y la envía desde el panel (`panel_url`).
     * Requiere `campaigns:write`. Acepta `Idempotency-Key`.
     */
    public function store(Request $request, RecordAuditLog $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'channel' => ['required', Rule::in(Campaign::CHANNELS)],
            'segment_ids' => ['sometimes', 'array', 'max:10'],
            'segment_ids.*' => ['string', 'max:40'],
        ]);
        $campaign = self::createDraft($this->current->id(), (string) $data['name'], (string) $data['channel'], (array) ($data['segment_ids'] ?? []), 'api:'.ApiContext::client($request)->publicId(), $audit);
        if (is_string($campaign)) {
            return ApiError::make(422, 'validation_failed', ApiError::defaultMessage(422), ['segment_ids' => [$campaign]]);
        }

        return new JsonResponse(['data' => Presenters::campaign($campaign) + ['panel_url' => self::panelUrl($campaign)]], 201);
    }

    /**
     * Borrador compartido por la API y el servidor MCP. Devuelve el mensaje de error si un
     * segmento no existe.
     *
     * @param  array<mixed>  $segmentIds
     */
    public static function createDraft(string $workspaceId, string $name, string $channel, array $segmentIds, string $source, RecordAuditLog $audit): Campaign|string
    {
        $include = [];
        foreach ($segmentIds as $publicId) {
            $segment = Segment::findByPublicId((string) $publicId);
            if ($segment === null) {
                return __('No existe el segmento :id.', ['id' => (string) $publicId]);
            }
            $include[] = $segment->id;
        }
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspaceId,
            'name' => mb_substr($name, 0, 160),
            'channel' => $channel,
            'audience' => ['segments_include' => $include, 'segments_exclude' => [], 'all_contacts' => false, 'rules_include' => [], 'rules_exclude' => []],
            'created_by' => null,
        ]);
        $audit->handle('campaign.created', $campaign, ['name' => $campaign->name, 'channel' => $campaign->channel, 'source' => $source], $workspaceId);

        return $campaign->refresh();
    }

    public static function panelUrl(Campaign $campaign): string
    {
        return rtrim((string) config('app.url'), '/').'/w/'.\App\Support\Ids\PrefixedId::encode('ws', $campaign->workspace_id).'/campanas/'.$campaign->publicId();
    }
}
