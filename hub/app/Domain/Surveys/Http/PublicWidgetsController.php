<?php

declare(strict_types=1);

namespace App\Domain\Surveys\Http;

use App\Domain\Identity\SiteToken;
use App\Domain\Onsite\Models\OnsiteWidget;
use App\Domain\Onsite\Support\PublicWidgetConfig;
use App\Domain\Onsite\Support\WidgetsCache;
use App\Domain\Sites\Models\Site;
use App\Domain\Surveys\Actions\HdyhauSurvey;
use App\Domain\Surveys\Actions\RecordSurveyAnswer;
use App\Domain\Surveys\Models\SurveyDefinition;
use App\Domain\Surveys\Models\SurveyResponse;
use App\Domain\Workspaces\Models\Workspace;
use App\Ingest\Http\SiteResolver;
use App\Support\Ids\PrefixedId;
use App\Support\Tenancy\CurrentWorkspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Endpoints públicos de widgets (api-connector.md §8). Sin sesión ni firma:
 * el sitio se identifica con su clave pública y las respuestas, con el token
 * del pedido que imprimió el plugin.
 */
final class PublicWidgetsController
{
    public function __construct(private readonly SiteResolver $sites, private readonly CurrentWorkspace $current) {}

    /** GET /p/{publicKey}/widgets.json?page_type=&path= (§8.1 y, desde la Fase 3, los widgets de M12 de §8.3) */
    public function widgets(Request $request, string $publicKey, HdyhauSurvey $hdyhau, PublicWidgetConfig $onsite): JsonResponse
    {
        [$site, $workspace] = $this->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['widgets' => []], 404);
        }

        $pageType = is_string($request->query('page_type')) ? (string) $request->query('page_type') : null;
        $path = is_string($request->query('path')) ? mb_substr((string) $request->query('path'), 0, 500) : null;
        // R11: la misma respuesta por sitio, tipo de página y ruta durante 5 min; guardar un widget la renueva.
        $payload = WidgetsCache::remember($site, $pageType, $path, fn (): array => $this->current->run($workspace, function () use ($hdyhau, $onsite, $workspace, $site, $pageType, $path): array {
            $widgets = [];
            if ($pageType === 'thankyou') {
                $survey = $hdyhau->for($workspace);
                if ($survey->status === 'active') {
                    $q = $survey->questions;
                    $widgets[] = [
                        'id' => $survey->publicId(), 'kind' => 'hdyhau_survey', 'slot' => 'thankyou',
                        'title' => $q['title'] ?? '', 'question' => $q['question'] ?? '', 'options' => $q['options'] ?? [],
                        'allow_other' => (bool) ($q['allow_other'] ?? false), 'other_label' => $q['other_label'] ?? '', 'thanks' => $q['thanks'] ?? '',
                    ];
                }
            }
            // M12: widgets activos del sitio para ese tipo de página y ruta (nunca en el checkout).
            foreach ($onsite->live($site, $pageType, $path) as $widget) {
                /** @var OnsiteWidget $widget */
                $widgets[] = $onsite->entry($workspace, $widget);
            }

            // Textos legales vigentes para los formularios que no son widgets («avísame»).
            return ['widgets' => $widgets, 'consents' => $onsite->legalConsents($workspace)];
        }));

        return response()->json($payload)
            ->withHeaders($this->cors($request, $site) + ['Cache-Control' => 'public, max-age=300']);
    }

    /** POST /p/{publicKey}/surveys/{surveyId}/answers (text/plain con JSON) */
    public function answer(Request $request, string $publicKey, string $surveyId, RecordSurveyAnswer $record): JsonResponse
    {
        [$site, $workspace] = $this->resolve($publicKey) ?? [null, null];
        if ($site === null || $workspace === null) {
            return response()->json(['error' => 'unknown_site'], 404);
        }
        $ip = (string) $request->ip();
        if (RateLimiter::tooManyAttempts('igr-survey:'.$ip, 30)) {
            return response()->json(['error' => 'rate_limited'], 429);
        }
        RateLimiter::hit('igr-survey:'.$ip, 60);
        $cors = $this->cors($request, $site);

        $body = json_decode($request->getContent(), true);
        $token = SiteToken::verify($site, is_array($body) && is_string($body['order_token'] ?? null) ? $body['order_token'] : null);
        $orderId = is_array($token) && is_string($token['order'] ?? null) ? $token['order'] : null;
        if (! is_array($body) || $orderId === null) {
            return response()->json(['error' => 'invalid_token'], 422)->withHeaders($cors);
        }

        return $this->current->run($workspace, function () use ($workspace, $site, $surveyId, $body, $orderId, $record, $cors): JsonResponse {
            $id = PrefixedId::decode(SurveyDefinition::ID_PREFIX, $surveyId);
            $survey = $id === null ? null : SurveyDefinition::query()->find($id);
            $answer = is_string($body['answer'] ?? null) ? $body['answer'] : '';
            $allowOther = (bool) ($survey?->questions['allow_other'] ?? false);
            $isOther = $answer === 'otro' && $allowOther;
            if ($survey === null || $survey->status !== 'active' || (! $isOther && ! in_array($answer, $survey->optionKeys(), true))) {
                return response()->json(['error' => 'invalid_answer'], 422)->withHeaders($cors);
            }
            $other = $isOther && is_string($body['other'] ?? null) ? mb_substr(trim($body['other']), 0, 300) : null;
            $anonymous = is_string($body['anonymous_id'] ?? null) ? mb_substr($body['anonymous_id'], 0, 128) : null;

            if (SurveyResponse::query()->where('survey_id', $survey->id)->where('site_id', $site->id)->where('external_order_id', $orderId)->exists()) {
                return response()->json(['error' => 'already_answered'], 409)->withHeaders($cors);
            }
            try {
                $record->handle($workspace, $site, $survey, $orderId, $answer, $other, $anonymous);
            } catch (UniqueConstraintViolationException) {
                return response()->json(['error' => 'already_answered'], 409)->withHeaders($cors);
            }

            return response()->json(['ok' => true], 202)->withHeaders($cors);
        });
    }

    public function preflight(Request $request, string $publicKey): Response
    {
        [$site] = $this->resolve($publicKey) ?? [null];

        return response('', 204)->withHeaders(($site !== null ? $this->cors($request, $site) : []) + ['Access-Control-Max-Age' => '86400']);
    }

    /** @return array{0: Site, 1: Workspace}|null */
    private function resolve(string $publicKey): ?array
    {
        $resolved = $this->sites->byPublicKey($publicKey);
        if ($resolved === null || ! $resolved[0]->isActive()) {
            return null;
        }
        $workspace = Workspace::query()->find($resolved[0]->workspace_id);

        return $workspace === null ? null : [$resolved[0], $workspace];
    }

    /** @return array<string, string> */
    private function cors(Request $request, Site $site): array
    {
        $origin = (string) $request->header('Origin');
        $host = parse_url($origin, PHP_URL_HOST);
        $allowed = is_string($host) && in_array(strtolower($host), array_map('strtolower', $site->domains ?? []), true);

        return ($allowed ? ['Access-Control-Allow-Origin' => $origin] : []) + [
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Content-Type',
            'Vary' => 'Origin',
        ];
    }
}
