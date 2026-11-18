<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Http;

use App\Domain\Surveys\Actions\HdyhauSurvey;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/** Configuración de la encuesta «¿Cómo nos conociste?» desde el panel. */
final class SurveySettingsController
{
    public function __construct(private readonly CurrentWorkspace $current, private readonly HdyhauSurvey $hdyhau) {}

    /** GET /api/w/{workspace}/surveys/hdyhau */
    public function show(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $survey = $this->hdyhau->for($this->current->get());
        $counts = SurveyResponse::query()->where('survey_id', $survey->id)
            ->selectRaw('answer, COUNT(*) AS c')->groupBy('answer')->pluck('c', 'answer');

        return response()->json([
            'id' => $survey->publicId(),
            'status' => $survey->status,
            'questions' => $survey->questions,
            'responses' => ['total' => (int) $counts->sum(), 'by_answer' => $counts->map(fn ($c) => (int) $c)],
        ]);
    }

    /** PUT /api/w/{workspace}/surveys/hdyhau */
    public function update(Request $request, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageMarketing');
        $data = $request->validate([
            'status' => ['required', 'in:active,paused'],
            'title' => ['required', 'string', 'max:120'],
            'question' => ['required', 'string', 'max:200'],
            'options' => ['required', 'array', 'min:2', 'max:15'],
            'options.*.label' => ['required', 'string', 'max:80'],
            'options.*.key' => ['nullable', 'string', 'max:40'],
            'allow_other' => ['boolean'],
            'other_label' => ['nullable', 'string', 'max:40'],
            'thanks' => ['required', 'string', 'max:160'],
        ]);
        $survey = $this->hdyhau->for($this->current->get());
        $keys = [];
        $options = [];
        foreach ($data['options'] as $option) {
            // Las claves existentes se conservan para no romper las respuestas viejas.
            $key = isset($option['key']) && $option['key'] !== '' ? Str::slug((string) $option['key'], '_') : Str::slug((string) $option['label'], '_');
            if ($key === '' || $key === 'otro' || isset($keys[$key])) {
                $key .= '_'.(count($keys) + 1);
            }
            $keys[$key] = true;
            $options[] = ['key' => $key, 'label' => trim((string) $option['label'])];
        }
        $survey->forceFill([
            'status' => $data['status'],
            'questions' => [
                'title' => $data['title'], 'question' => $data['question'], 'options' => $options,
                'allow_other' => (bool) ($data['allow_other'] ?? true), 'other_label' => $data['other_label'] ?? __('Otro'), 'thanks' => $data['thanks'],
            ],
        ])->save();
        $audit->handle('survey.updated', $survey, ['status' => $data['status'], 'options' => count($options)], $survey->workspace_id);

        return $this->show();
    }
}
