<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Insights\Actions;

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Flows\Actions\CreateFlowFromLibrary;
use App\Domain\Flows\Models\Flow;
use App\Domain\Intelligence\Models\Insight;
use App\Domain\Segments\Models\Segment;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * «Hacer ahora» con 1 clic (inteligencia.md §5): activa el flujo de la biblioteca
 * o crea la campaña con el segmento ya elegido, marca el hallazgo como atendido
 * y devuelve a dónde llevar a la persona. Los enlaces simples solo marcan.
 */
final class RunInsightAction
{
    public function __construct(
        private readonly CreateFlowFromLibrary $flows,
        private readonly RecordAuditLog $audit,
    ) {}

    /** @return array{to: string, result: string} */
    public function handle(Insight $insight, User $user): array
    {
        $action = (array) ($insight->recommended_action ?? []);
        $payload = (array) ($action['payload'] ?? []);
        $kind = (string) ($action['kind'] ?? 'link');
        [$to, $result] = match ($kind) {
            'activate_flow' => $this->activateFlow((string) ($payload['library_key'] ?? ''), $user),
            'create_campaign' => $this->createCampaign($payload, $user, (string) $insight->workspace_id),
            default => [(string) ($action['to'] ?? 'hallazgos'), 'link'],
        };
        $insight->forceFill(['status' => 'actioned', 'dismissed_at' => now()])->save();
        $this->audit->handle('insight.actioned', $insight, ['detector' => $insight->detector_key, 'action' => $kind, 'result' => $result], $insight->workspace_id);

        return ['to' => $to, 'result' => $result];
    }

    /** @return array{0: string, 1: string} */
    private function activateFlow(string $key, User $user): array
    {
        $existing = Flow::query()->where('template_key', $key)->where('status', '!=', Flow::ARCHIVED)->orderByRaw("status = 'active' DESC")->first();
        if ($existing !== null) {
            return ['flujos/'.$existing->publicId(), $existing->status === Flow::ACTIVE ? 'already_active' : 'exists'];
        }
        $created = $this->flows->handle($key, true, $user->id);

        return ['flujos/'.$created['flow']->publicId(), $created['activated'] ? 'flow_activated' : 'flow_draft'];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: string}
     */
    private function createCampaign(array $payload, User $user, string $workspaceId): array
    {
        $segment = Segment::query()->where('system_key', (string) ($payload['system_key'] ?? ''))->first();
        if ($segment === null) {
            throw ValidationException::withMessages(['action' => __('No encontramos el segmento de esta acción.')]);
        }
        $channel = in_array($payload['channel'] ?? null, Campaign::CHANNELS, true) ? (string) $payload['channel'] : 'email';
        $campaign = Campaign::query()->create([
            'workspace_id' => $workspaceId,
            'name' => mb_substr((string) ($payload['name'] ?? $segment->name), 0, 160),
            'channel' => $channel,
            'audience' => AudienceQuery::normalize(['segments_include' => [$segment->publicId()]]),
            'created_by' => $user->id,
        ]);
        $this->audit->handle('campaign.created', $campaign, ['name' => $campaign->name, 'channel' => $campaign->channel, 'source' => 'insight'], $workspaceId);

        return ['campanas/'.$campaign->publicId().'/editar?paso=audience', 'campaign_created'];
    }
}
