<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Http;

use App\Domain\Reviews\Support\ReviewToken;
use App\Domain\Surveys\Actions\NpsSurvey;
use App\Domain\Surveys\Actions\RecordNpsAnswer;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyRequest;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Models\Workspace;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * /r/nps/{token}: encuesta NPS (0 a 10) o CSAT (1 a 5) de una pregunta, con
 * comentario opcional (M15). El enlace del correo trae la nota preseleccionada
 * (`?nota=`), pero se guarda solo al enviar: los antivirus del correo abren los
 * enlaces y no deben responder por la persona. No ofrece la reseña de Google
 * según la nota (eso sería filtrar reseñas).
 */
final class NpsPageController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    public function show(Request $request, string $token): Response
    {
        $ctx = $this->context($token);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $req, $survey] = $ctx;

        return $this->current->run($workspace, function () use ($workspace, $req, $survey, $request, $token): Response {
            $answered = SurveyResponse::query()->where('survey_id', $survey->id)->where('answers->request_id', $req->id)->first();

            return $this->page($workspace, $survey, $token, $answered->score ?? $this->preset($survey, $request->query('nota')), null, $answered !== null);
        });
    }

    public function store(Request $request, string $token, RecordNpsAnswer $record): Response
    {
        $ctx = $this->context($token);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$workspace, $req, $survey] = $ctx;
        [$min, $max] = NpsSurvey::range($survey->kind);
        $score = $request->input('score');
        if (! is_numeric($score) || (int) $score < $min || (int) $score > $max) {
            return $this->page($workspace, $survey, $token, null, __('Elige una nota del :min al :max.', ['min' => $min, 'max' => $max]), false, 422);
        }
        $comment = $request->input('comment');
        $this->current->run($workspace, fn () => $record->handle($workspace, $req, $survey, (int) $score, is_string($comment) ? $comment : null));

        return $this->view('surveys.nps-thanks', $workspace, ['thanks' => (string) ($survey->questions['thanks'] ?? __('¡Gracias!'))]);
    }

    private function preset(SurveyDefinition $survey, mixed $value): ?int
    {
        [$min, $max] = NpsSurvey::range($survey->kind);

        return is_numeric($value) && (int) $value >= $min && (int) $value <= $max ? (int) $value : null;
    }

    private function page(Workspace $workspace, SurveyDefinition $survey, string $token, ?int $score, ?string $error, bool $answered, int $status = 200): Response
    {
        [$min, $max] = NpsSurvey::range($survey->kind);

        return $this->view('surveys.nps', $workspace, [
            'kind' => $survey->kind,
            'question' => (string) ($survey->questions['question'] ?? ''),
            'commentLabel' => (string) ($survey->questions['comment_label'] ?? ''),
            'min' => $min,
            'max' => $max,
            'score' => $score,
            'error' => $error,
            'answered' => $answered,
            'action' => ReviewToken::base().'/r/nps/'.$token,
        ], $status);
    }

    /** @return array{0: Workspace, 1: SurveyRequest, 2: SurveyDefinition}|Response */
    private function context(string $token): array|Response
    {
        $parsed = ReviewToken::parse(ReviewToken::SURVEY, $token);
        if ($parsed['status'] !== 'ok') {
            return $this->view('reviews.invalid', null, ['expired' => $parsed['status'] === 'expired'], $parsed['status'] === 'expired' ? 410 : 404);
        }
        $claims = $parsed['claims'];
        $workspace = Workspace::query()->find((string) $claims['w']);
        $found = $workspace === null ? null : $this->current->run($workspace, function () use ($claims): ?array {
            $req = SurveyRequest::query()->whereKey((string) $claims['r'])->where('contact_id', (string) ($claims['c'] ?? ''))->first();
            $survey = $req !== null ? SurveyDefinition::query()->whereKey($req->survey_id)->whereIn('kind', NpsSurvey::KINDS)->first() : null;

            return $req !== null && $survey !== null ? [$req, $survey] : null;
        });
        if ($workspace === null || $found === null) {
            return $this->view('reviews.invalid', $workspace, ['expired' => false], 404);
        }

        return [$workspace, $found[0], $found[1]];
    }

    /** @param  array<string, mixed>  $data */
    private function view(string $view, ?Workspace $workspace, array $data, int $status = 200): Response
    {
        $profile = $workspace !== null ? BusinessProfile::for($workspace) : null;

        return response()->view($view, $data + [
            'brand' => $profile !== null ? ($profile['name'] !== '' ? $profile['name'] : (string) $workspace?->name) : 'Imagina Growth',
            'logo' => $profile !== null && $profile['logo_url'] !== '' ? $profile['logo_url'] : null,
        ], $status)->withHeaders(['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY']);
    }
}
