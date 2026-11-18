<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Actions;

use App\Domain\Commerce\Models\Order;
use App\Domain\Messaging\Enums\MessageKind;
use App\Domain\Reviews\Support\ChannelCascade;
use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Envía las encuestas NPS y CSAT programadas (scheduler cada 5 minutos) con
 * canal de respaldo (ChannelCascade, clave `survey_request:{id}:{canal}`). Son
 * mensajes de marketing (lo ambiguo es marketing, cumplimiento.md §4.1). La
 * plantilla de correo por defecto del NPS es `nps_survey` de la biblioteca; el
 * CSAT necesita una plantilla elegida. `enlaces.nps` termina en `?nota=`.
 */
final class SendSurveyRequests
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly ChannelCascade $cascade) {}

    /** @return array{sent: int, deferred: int, skipped: int, expired: int} */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $totals = ['sent' => 0, 'deferred' => 0, 'skipped' => 0, 'expired' => 0];
        $workspaceIds = SurveyRequest::withoutWorkspaceScope()->where('status', 'scheduled')->where('scheduled_for', '<=', $now)
            ->distinct()->pluck('workspace_id');
        foreach (Workspace::query()->whereIn('id', $workspaceIds)->get() as $workspace) {
            try {
                $this->current->run($workspace, function () use ($workspace, $now, &$totals): void {
                    $requests = SurveyRequest::query()->where('status', 'scheduled')->where('scheduled_for', '<=', $now)
                        ->orderBy('scheduled_for')->limit((int) config('igr-reviews.send_batch', 500))->get();
                    $surveys = SurveyDefinition::query()->whereIn('id', $requests->pluck('survey_id')->unique()->all())->get()->keyBy('id');
                    foreach ($requests as $request) {
                        $survey = $surveys->get($request->survey_id);
                        $totals[$this->send($workspace, $request, $survey, $now)]++;
                    }
                });
            } catch (Throwable $e) {
                report($e);
                Log::error('igr.surveys.requests_failed', ['workspace' => $workspace->id, 'error' => $e->getMessage()]);
            }
        }

        return $totals;
    }

    /** @return 'sent'|'deferred'|'skipped'|'expired' */
    public function send(Workspace $workspace, SurveyRequest $request, ?SurveyDefinition $survey, CarbonImmutable $now): string
    {
        if ($survey === null || $survey->status !== 'active') {
            return $this->skip($request, 'survey_paused');
        }
        if ($request->scheduled_for !== null && $request->scheduled_for->lessThan($now->subDays((int) config('igr-reviews.request_expire_days', 30)))) {
            $request->forceFill(['status' => 'expired', 'skip_reason' => 'not_sent_in_time'])->save();

            return 'expired';
        }
        $order = $request->order_id !== null ? Order::query()->find($request->order_id) : null;
        if ($order !== null && in_array($order->status, ['cancelled', 'refunded', 'failed'], true)) {
            return $this->skip($request, 'order_cancelled');
        }
        $t = NpsSurvey::trigger($survey);
        $request->increment('attempts');
        $context = ['enlaces' => ['nps' => ReviewToken::surveyUrl($request).'?nota=']];
        if ($order !== null) {
            $context['pedido'] = ['numero' => (string) ($order->getAttribute('number') ?? $order->external_id)];
        }
        $result = $this->cascade->send(
            $workspace, $request->contact_id, $t['channels'],
            ['email' => $t['email_template_id'], 'whatsapp' => $t['whatsapp_template_id'], 'sms' => $t['sms_template_id']],
            $survey->kind === 'nps' ? 'nps_survey' : null, MessageKind::Marketing, $request->id, $context,
            'survey_request:'.$request->id, $now,
        );
        if ($result['status'] === 'deferred') {
            $request->forceFill(['scheduled_for' => $result['until']])->save();

            return 'deferred';
        }
        if ($result['status'] === 'skipped') {
            return $this->skip($request, $result['reason']);
        }
        $request->forceFill(['status' => 'sent', 'channel' => $result['channel'], 'message_id' => $result['message_id'], 'sent_at' => $now, 'skip_reason' => null])->save();

        return 'sent';
    }

    /** @return 'skipped' */
    private function skip(SurveyRequest $request, string $reason): string
    {
        $request->forceFill(['status' => 'skipped', 'skip_reason' => mb_substr($reason, 0, 40)])->save();

        return 'skipped';
    }
}
