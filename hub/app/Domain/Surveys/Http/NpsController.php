<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Http;

use App\Domain\Flows\Support\TemplateRef;
use App\Domain\Surveys\Actions\NpsSurvey;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * NPS y CSAT en la SPA (M15): ajustes de cada encuesta y su tablero (nota,
 * distribución, evolución semanal y respuestas recientes). Los comentarios
 * pueden tener datos personales: solo los ve quien puede ver contactos.
 */
final class NpsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly NpsSurvey $surveys) {}

    /** GET reviews/surveys/{kind} */
    public function show(string $kind): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json($this->present($this->survey($kind)));
    }

    /** PUT reviews/surveys/{kind} */
    public function update(Request $request, string $kind, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $survey = $this->survey($kind);
        [, $max] = NpsSurvey::range($kind);
        $data = $request->validate([
            'status' => ['required', 'in:active,paused'],
            'question' => ['required', 'string', 'max:240'],
            'comment_label' => ['nullable', 'string', 'max:160'],
            'thanks' => ['required', 'string', 'max:200'],
            'after' => ['required', 'in:completed,paid'],
            'days' => ['required', 'integer', 'between:0,120'],
            'channels' => ['required', 'array', 'min:1', 'max:3'],
            'channels.*' => ['in:whatsapp,email,sms', 'distinct'],
            'email_template_id' => ['nullable', 'string', 'max:40'],
            'whatsapp_template_id' => ['nullable', 'string', 'max:40'],
            'sms_template_id' => ['nullable', 'string', 'max:40'],
            'alert_threshold' => ['required', 'integer', 'between:-1,'.$max],
            'alert_emails' => ['present', 'array', 'max:10'],
            'alert_emails.*' => ['email', 'max:190'],
        ]);
        $errors = [];
        foreach (['email', 'whatsapp', 'sms'] as $channel) {
            $id = $data[$channel.'_template_id'] ?? null;
            if (is_string($id) && $id !== '' && TemplateRef::resolve($this->current->id(), $channel, $id, null) === null) {
                $errors[$channel.'_template_id'] = __('Esa plantilla no existe o es de otro canal.');
            }
        }
        if ($data['status'] === 'active' && $kind === 'csat' && in_array('email', $data['channels'], true) && ($data['email_template_id'] ?? null) === null) {
            $errors['email_template_id'] = __('Elige la plantilla de correo del CSAT.');
        }
        if ($data['status'] === 'active' && in_array('whatsapp', $data['channels'], true) && ($data['whatsapp_template_id'] ?? null) === null) {
            $errors['whatsapp_template_id'] = __('Elige la plantilla aprobada de WhatsApp o quita el canal.');
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $survey->forceFill([
            'status' => $data['status'],
            'questions' => ['question' => trim($data['question']), 'comment_label' => trim((string) ($data['comment_label'] ?? '')), 'thanks' => trim($data['thanks'])],
            'trigger' => [
                'after' => $data['after'], 'days' => (int) $data['days'], 'channels' => array_values($data['channels']),
                'email_template_id' => $data['email_template_id'] ?? null, 'whatsapp_template_id' => $data['whatsapp_template_id'] ?? null,
                'sms_template_id' => $data['sms_template_id'] ?? null, 'alert_threshold' => (int) $data['alert_threshold'],
                'alert_emails' => array_values(array_unique(array_map('strtolower', $data['alert_emails']))),
            ],
        ])->save();
        $audit->handle('surveys.'.$kind.'_updated', $survey, ['status' => $data['status'], 'days' => (int) $data['days']], $this->current->id());

        return response()->json($this->present($survey));
    }

    /** GET reviews/surveys/{kind}/report?days=90 */
    public function report(Request $request, string $kind): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $survey = $this->survey($kind);
        $days = max(7, min(730, (int) $request->query('days', '90')));
        $tz = $this->current->get()->timezone ?: 'America/Bogota';
        $from = CarbonImmutable::now($tz)->subDays($days)->startOfDay()->utc();
        [$min, $max] = NpsSurvey::range($kind);

        $responses = SurveyResponse::query()->where('survey_id', $survey->id)->where('created_at', '>=', $from)->whereNotNull('score')
            ->get(['id', 'score', 'contact_id', 'external_order_id', 'comment', 'created_at']);
        $distribution = [];
        for ($i = $min; $i <= $max; $i++) {
            $distribution[(string) $i] = 0;
        }
        $cats = [];
        $weeks = [];
        foreach ($responses as $r) {
            $score = (int) $r->score;
            $distribution[(string) $score] = ($distribution[(string) $score] ?? 0) + 1;
            $cat = NpsSurvey::category($kind, $score);
            $cats[$cat] = ($cats[$cat] ?? 0) + 1;
            $week = CarbonImmutable::instance($r->created_at ?? now())->setTimezone($tz)->startOfWeek()->toDateString();
            $weeks[$week][$cat] = ($weeks[$week][$cat] ?? 0) + 1;
            $weeks[$week]['_n'] = ($weeks[$week]['_n'] ?? 0) + 1;
        }
        ksort($weeks);
        $total = $responses->count();
        $sent = SurveyRequest::query()->where('survey_id', $survey->id)->where('sent_at', '>=', $from)->count();
        $canSeePii = Gate::allows('ws.viewAllContacts');

        return response()->json([
            'kind' => $kind,
            'days' => $days,
            'responses' => $total,
            'sent' => $sent,
            'response_rate' => $sent > 0 ? round(SurveyRequest::query()->where('survey_id', $survey->id)->where('sent_at', '>=', $from)->where('status', 'answered')->count() / $sent, 4) : null,
            'score' => $total > 0 ? self::score($kind, $cats, $total) : null,
            'categories' => $cats,
            'distribution' => $distribution,
            'trend' => array_map(fn (string $week, array $c) => [
                'week' => $week,
                'responses' => (int) $c['_n'],
                'score' => self::score($kind, $c, (int) $c['_n']),
            ], array_keys($weeks), array_values($weeks)),
            'recent' => $responses->sortByDesc('created_at')->take(20)->map(fn (SurveyResponse $r) => [
                'id' => $r->publicId(),
                'score' => (int) $r->score,
                'category' => NpsSurvey::category($kind, (int) $r->score),
                'comment' => $canSeePii ? $r->comment : null,
                'contact_id' => $r->contact_id !== null ? 'ct_'.$r->contact_id : null,
                'order' => $r->external_order_id,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    /**
     * NPS: % promotores − % detractores (de −100 a 100). CSAT: % satisfechos (4 y 5).
     *
     * @param  array<string, int>  $cats
     */
    private static function score(string $kind, array $cats, int $total): float
    {
        if ($kind === 'nps') {
            return round((($cats['promoter'] ?? 0) - ($cats['detractor'] ?? 0)) * 100 / $total, 1);
        }

        return round(($cats['satisfied'] ?? 0) * 100 / $total, 1);
    }

    private function survey(string $kind): SurveyDefinition
    {
        if (! in_array($kind, NpsSurvey::KINDS, true)) {
            abort(404);
        }

        return $this->surveys->for($this->current->get(), $kind);
    }

    /** @return array<string, mixed> */
    private function present(SurveyDefinition $survey): array
    {
        $q = $survey->questions;
        [$min, $max] = NpsSurvey::range($survey->kind);

        return [
            'id' => $survey->publicId(),
            'kind' => $survey->kind,
            'status' => $survey->status,
            'question' => (string) ($q['question'] ?? ''),
            'comment_label' => (string) ($q['comment_label'] ?? ''),
            'thanks' => (string) ($q['thanks'] ?? ''),
            'scale' => ['min' => $min, 'max' => $max],
        ] + NpsSurvey::trigger($survey);
    }
}
