<?php

declare(strict_types=1);

namespace App\Domain\Campaigns\Actions;

use App\Domain\Campaigns\Audience\AudienceQuery;
use App\Domain\Campaigns\Models\Campaign;
use App\Domain\Campaigns\Models\CampaignRecipient;
use App\Domain\Campaigns\Queries\VariantScores;
use App\Domain\Campaigns\Support\SendSchedule;
use App\Domain\Messaging\Contracts\MessageDispatcher;
use App\Domain\Messaging\Data\DispatchResult;
use App\Domain\Messaging\Data\OutboundMessage;
use App\Domain\Messaging\Enums\Channel;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Workspaces\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Motor de envío de campañas (modulos.md M10). Lo mueve `igr:campaigns:tick`
 * cada minuto:
 *
 * 1. `materialize`: al llegar su hora, la campaña congela la audiencia en
 *    `campaign_recipients` con el sorteo del grupo de control y de la variante
 *    (deterministas por campaña y contacto) y la hora de cada uno.
 * 2. `sendDue`: despacha por lotes a los que ya les toca. La clave de
 *    idempotencia `campaign:{id}:{contact}` impide reenviar si un lote se repite.
 *    Aplazado → se reprograma en `until`; negado → queda con su motivo.
 * 3. `pickWinner`: con A/B, pasadas `wait_hours` elige la variante con más clics
 *    (o ingresos) por enviado y la manda al resto.
 * 4. `finishIfDone`: sin pendientes, la campaña queda `sent`.
 */
final class CampaignSender
{
    public const BATCH = 500;

    public function __construct(
        private readonly AudienceQuery $audience,
        private readonly MessageDispatcher $dispatcher,
    ) {}

    public function materialize(Workspace $workspace, Campaign $campaign, ?CarbonImmutable $now = null): int
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $variants = $campaign->variantList();
        $ab = $campaign->hasAbTest();
        $samplePct = $ab ? max(1, min(100, (int) ($campaign->ab_config['sample_pct'] ?? 20))) : 100;
        $inserted = 0;
        $this->audience->query($workspace, $campaign->audience ?? [])
            ->select(['c.id', 'c.country', 'c.timezone', 'c.preferred_hour'])
            // Por clave (c.id > último), no por OFFSET: con 100.000 contactos el OFFSET recorría todo otra vez en cada página (R12).
            ->chunkById(1000, function ($contacts) use ($workspace, $campaign, $variants, $ab, $samplePct, $now, &$inserted): void {
                $rows = [];
                foreach ($contacts as $c) {
                    $id = (string) $c->id;
                    $holdout = self::bucket($campaign->id, $id, 'holdout') < $campaign->holdout_pct;
                    $inSample = ! $ab || self::bucket($campaign->id, $id, 'sample') < $samplePct;
                    $status = $holdout ? CampaignRecipient::SKIPPED : ($inSample ? CampaignRecipient::PENDING : CampaignRecipient::HELD);
                    $rows[] = [
                        'workspace_id' => $workspace->id,
                        'campaign_id' => $campaign->id,
                        'contact_id' => $id,
                        'variant' => $holdout || ! $inSample ? null : self::pickVariant($variants, $campaign->id, $id),
                        'is_holdout' => $holdout,
                        'status' => $status,
                        'reason' => $holdout ? 'holdout' : null,
                        'scheduled_for' => $status === CampaignRecipient::PENDING
                            ? SendSchedule::forContact($campaign, $workspace, $c->country !== null ? (string) $c->country : null, $c->timezone !== null ? (string) $c->timezone : null, $c->preferred_hour !== null ? (int) $c->preferred_hour : null, $now)
                            : null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
                // insertOrIgnore: materializar dos veces no duplica (único campaign_id + contact_id).
                $inserted += DB::table('campaign_recipients')->insertOrIgnore($rows);
            }, 'c.id', 'id');
        $campaign->forceFill(['status' => Campaign::SENDING, 'started_at' => $campaign->started_at ?? $now])->save();

        return $inserted;
    }

    /**
     * Despacha un lote de destinatarios con hora vencida. Devuelve cuántos procesó;
     * si devuelve BATCH, quedan más.
     */
    public function sendDue(Workspace $workspace, Campaign $campaign, ?CarbonImmutable $now = null): int
    {
        if ($campaign->status !== Campaign::SENDING) {
            return 0;
        }
        $now = ($now ?? CarbonImmutable::now())->utc();
        $due = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::DEFERRED])
            ->where('scheduled_for', '<=', $now)
            ->orderBy('scheduled_for')->orderBy('id')->limit(self::BATCH)->get();
        $channel = Channel::from($campaign->channel);
        foreach ($due as $recipient) {
            try {
                $result = $this->dispatcher->dispatch(new OutboundMessage(
                    workspaceId: $workspace->id,
                    contactId: $recipient->contact_id,
                    channel: $channel,
                    kind: MessageKind::Marketing,
                    sourceType: 'campaign',
                    sourceId: $campaign->id,
                    templateId: $campaign->templateFor($recipient->variant),
                    context: ['campana' => ['nombre' => $campaign->name, 'variante' => $recipient->variant]],
                    idempotencyKey: 'campaign:'.$campaign->id.':'.$recipient->contact_id,
                    campaignId: $campaign->id,
                ));
            } catch (Throwable $e) {
                report($e);
                Log::warning('igr.campaign.dispatch_failed', ['campaign' => $campaign->id, 'recipient' => $recipient->id]);
                $recipient->forceFill(['status' => CampaignRecipient::FAILED, 'reason' => 'dispatch_error'])->save();

                continue;
            }
            match ($result->status) {
                DispatchResult::QUEUED, DispatchResult::DUPLICATE => $recipient->forceFill(['status' => CampaignRecipient::SENT, 'message_id' => $result->messageId, 'reason' => null]),
                DispatchResult::DEFERRED => $recipient->forceFill(['status' => CampaignRecipient::DEFERRED, 'scheduled_for' => $result->until, 'reason' => $result->reason]),
                default => $recipient->forceFill(['status' => CampaignRecipient::DENIED, 'reason' => $result->reason]),
            };
            $recipient->save();
        }

        return $due->count();
    }

    /**
     * A/B: elige la variante ganadora (más clics o ingresos por mensaje enviado) y
     * libera al resto con esa variante. Devuelve la ganadora o null si aún no toca.
     */
    public function pickWinner(Workspace $workspace, Campaign $campaign, ?CarbonImmutable $now = null, bool $force = false): ?string
    {
        if (! $campaign->hasAbTest() || $campaign->winner_variant !== null || $campaign->started_at === null) {
            return $campaign->winner_variant;
        }
        $now = ($now ?? CarbonImmutable::now())->utc();
        $wait = max(1, (int) ($campaign->ab_config['wait_hours'] ?? 4));
        if (! $force && CarbonImmutable::instance($campaign->started_at)->addHours($wait)->greaterThan($now)) {
            return null;
        }
        $scores = VariantScores::for($campaign);
        $metric = ($campaign->ab_config['metric'] ?? 'clicks') === 'revenue' ? 'revenue_per_sent' : 'click_rate';
        $winner = $campaign->variantList()[0]['key'];
        $best = -1.0;
        foreach ($campaign->variantList() as $v) {
            $score = (float) ($scores[$v['key']][$metric] ?? 0);
            if ($score > $best) {
                [$best, $winner] = [$score, $v['key']];
            }
        }
        $campaign->forceFill(['winner_variant' => $winner, 'stats' => array_merge($campaign->stats ?? [], ['ab' => ['metric' => $metric, 'scores' => $scores, 'decided_at' => $now->toIso8601String()]])])->save();

        CampaignRecipient::query()->where('campaign_id', $campaign->id)->where('status', CampaignRecipient::HELD)
            ->chunkById(1000, function ($held) use ($workspace, $campaign, $winner, $now): void {
                $contacts = DB::table('contacts')->whereIn('id', $held->pluck('contact_id')->all())->get(['id', 'country', 'timezone', 'preferred_hour'])->keyBy('id');
                foreach ($held as $recipient) {
                    $c = $contacts->get($recipient->contact_id);
                    $recipient->forceFill([
                        'variant' => $winner,
                        'status' => CampaignRecipient::PENDING,
                        'scheduled_for' => SendSchedule::forContact($campaign, $workspace, $c?->country, $c?->timezone, $c?->preferred_hour !== null ? (int) $c->preferred_hour : null, $now),
                    ])->save();
                }
            });

        return $winner;
    }

    /** Sin pendientes, aplazados ni retenidos: la campaña terminó. */
    public function finishIfDone(Campaign $campaign, ?CarbonImmutable $now = null): bool
    {
        if ($campaign->status !== Campaign::SENDING) {
            return false;
        }
        $open = CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::DEFERRED, CampaignRecipient::HELD])->exists();
        if ($open) {
            return false;
        }
        $campaign->forceFill(['status' => Campaign::SENT, 'finished_at' => ($now ?? CarbonImmutable::now())->utc()])->save();

        return true;
    }

    public function cancel(Campaign $campaign): void
    {
        CampaignRecipient::query()->where('campaign_id', $campaign->id)
            ->whereIn('status', [CampaignRecipient::PENDING, CampaignRecipient::DEFERRED, CampaignRecipient::HELD])
            ->update(['status' => CampaignRecipient::SKIPPED, 'reason' => 'cancelled', 'updated_at' => now()]);
        $campaign->forceFill(['status' => Campaign::CANCELLED, 'finished_at' => now()])->save();
    }

    /** Valor determinista de 0 a 99 por campaña, contacto y sorteo. */
    public static function bucket(string $campaignId, string $contactId, string $salt): int
    {
        return (int) (hexdec(substr(hash('sha256', $campaignId.':'.$contactId.':'.$salt), 0, 8)) % 100);
    }

    /** @param list<array{key: string, template_id: string|null, weight: int}> $variants */
    private static function pickVariant(array $variants, string $campaignId, string $contactId): string
    {
        $total = array_sum(array_column($variants, 'weight'));
        $point = self::bucket($campaignId, $contactId, 'variant') * $total / 100;
        $acc = 0;
        foreach ($variants as $v) {
            $acc += $v['weight'];
            if ($point < $acc) {
                return $v['key'];
            }
        }

        $last = end($variants);

        return $last === false ? 'A' : $last['key'];
    }
}
