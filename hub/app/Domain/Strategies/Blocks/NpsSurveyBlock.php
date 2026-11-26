<?php

declare(strict_types=1);

namespace App\Domain\Strategies\Blocks;

use App\Domain\Strategies\Support\Previews;
use App\Domain\Strategies\Support\Requirement;
use App\Domain\Strategies\Support\StrategyContext;
use App\Domain\Surveys\Actions\NpsSurvey;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Workspaces\Actions\RecordAuditLog;

/**
 * La encuesta NPS con aviso de detractores (M15, flujo 17: la envía el propio módulo, `SendSurveyRequests`). Activar
 * la pone activa con su pregunta por defecto (NpsSurvey) por correo, y por WhatsApp solo si ya tiene una plantilla
 * aprobada elegida.
 */
final class NpsSurveyBlock implements StrategyBlock
{
    public function kind(): string
    {
        return 'nps_survey';
    }

    public function requirements(StrategyContext $ctx, array $options = []): array
    {
        $survey = $this->survey($ctx);
        $whatsapp = $ctx->hasChannel('whatsapp') && $survey !== null && NpsSurvey::trigger($survey)['whatsapp_template_id'] !== null;

        return [new Requirement('email', $ctx->hasChannel('email') || $whatsapp, true, 'canales')];
    }

    public function state(StrategyContext $ctx): BlockState
    {
        $survey = $this->survey($ctx);
        if ($survey?->status !== 'active') {
            return BlockState::off('resenas?tab=nps');
        }

        return BlockState::on('resenas?tab=nps', array_values(array_filter([$ctx->channelProblem(NpsSurvey::trigger($survey)['channels'])])));
    }

    public function activate(StrategyContext $ctx, array $options, ?string $userId): array
    {
        $survey = app(NpsSurvey::class)->for($ctx->workspace, 'nps');
        if ($survey->status === 'active') {
            return [];
        }
        $trigger = NpsSurvey::trigger($survey);
        $channels = [];
        if ($ctx->hasChannel('whatsapp') && $trigger['whatsapp_template_id'] !== null) {
            $channels[] = 'whatsapp';
        }
        if ($ctx->hasChannel('email')) {
            $channels[] = 'email';
        }
        $survey->forceFill(['status' => 'active', 'trigger' => ['channels' => $channels] + $trigger])->save();
        $this->audit($survey, $userId);

        return [];
    }

    public function pause(StrategyContext $ctx, ?string $userId): array
    {
        $survey = $this->survey($ctx);
        if ($survey?->status === 'active') {
            $survey->forceFill(['status' => 'paused'])->save();
            $this->audit($survey, $userId);
        }

        return [];
    }

    public function preview(StrategyContext $ctx): array
    {
        $question = $this->survey($ctx)?->questions['question'] ?? NpsSurvey::defaultQuestions('nps', $ctx->workspace->name)['question'];

        return Previews::summary([(string) $question, __('strategies.preview.nps_alert')]);
    }

    private function survey(StrategyContext $ctx): ?SurveyDefinition
    {
        return $ctx->remember('nps_survey', fn () => SurveyDefinition::query()->where('kind', 'nps')->orderBy('created_at')->first());
    }

    private function audit(SurveyDefinition $survey, ?string $userId): void
    {
        app(RecordAuditLog::class)->handle('surveys.nps_updated', $survey, ['status' => $survey->status, 'days' => NpsSurvey::trigger($survey)['days']], $survey->workspace_id, null, $userId);
    }
}
