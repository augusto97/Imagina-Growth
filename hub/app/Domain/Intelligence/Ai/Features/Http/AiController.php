<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Features\Http;

use App\Domain\Contacts\Http\ContactAccess;
use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Features\Analyst\WeeklyAnalyst;
use App\Domain\Intelligence\Ai\Features\AskData\AskYourData;
use App\Domain\Intelligence\Ai\Features\AskData\SegmentFromQuery;
use App\Domain\Intelligence\Ai\Features\Models\AiReport;
use App\Domain\Intelligence\Ai\Features\Models\AskDataQuery;
use App\Domain\Intelligence\Ai\Features\Summaries\ProfileSummary;
use App\Domain\Intelligence\Ai\Features\Summaries\ReviewSummary;
use App\Domain\Intelligence\Ai\Support\AiMeter;
use App\Domain\Reports\SendWeeklyReport;
use App\Domain\Reports\WeeklyReport;
use App\Domain\Segments\Actions\SaveSegment;
use App\Domain\Sites\Models\Site;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;

/**
 * IA del panel (M17, Fase 4): uso del mes, informes del analista semanal,
 * «Pregúntale a tus datos» y los resúmenes de perfil y de reseñas. Rutas en
 * routes/api/ai.php, dentro de /api/w/{workspace}.
 */
final class AiController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET ai/usage — gasto del mes frente al tope y por función. */
    public function usage(AiGateway $ai, AiMeter $meter): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $ws = $this->current->get();
        $rows = DB::table('ai_usage')->where('workspace_id', $ws->id)->where('date', '>=', now()->startOfMonth()->toDateString())
            ->selectRaw('feature, SUM(requests) AS requests, SUM(tokens_in) AS tokens_in, SUM(tokens_out) AS tokens_out, SUM(cost_usd) AS cost_usd')
            ->groupBy('feature')->orderByDesc('cost_usd')->get();

        return response()->json([
            'available' => $ai->available($ws->id),
            'configured' => (bool) config('igr-ai.enabled') && (string) config('igr-ai.api_key') !== '',
            'spent_usd' => round($meter->spentThisMonth($ws->id), 6),
            'budget_usd' => (float) config('igr-ai.monthly_budget_usd'),
            'features' => $rows->map(fn (object $r) => [
                'feature' => (string) $r->feature, 'requests' => (int) $r->requests, 'tokens_in' => (int) $r->tokens_in,
                'tokens_out' => (int) $r->tokens_out, 'cost_usd' => round((float) $r->cost_usd, 6),
            ])->values(),
        ]);
    }

    /** GET ai/reports */
    public function reports(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['data' => AiReport::query()->orderByDesc('period_start')->limit(52)->get()->map(fn (AiReport $r) => self::presentReport($r, false))->values()]);
    }

    /** GET ai/reports/{report} */
    public function report(string $report): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(self::presentReport(AiReport::findByPublicId($report) ?? abort(404), true));
    }

    /** POST ai/reports/generate {week?} — escribe (o reescribe) el informe de una semana. */
    public function generate(Request $request, WeeklyAnalyst $analyst): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $data = $request->validate(['week' => ['nullable', 'date_format:Y-m-d']]);
        $ws = $this->current->get();
        $tz = $ws->timezone ?: 'UTC';
        $week = isset($data['week']) ? CarbonImmutable::parse($data['week'], $tz)->startOfWeek(CarbonImmutable::MONDAY) : WeeklyReport::lastWeekStart($ws);
        if (! RateLimiter::attempt('ai-report:'.$ws->id, 6, fn () => true, 3600)) {
            abort(429, __('Ya generaste varios informes en la última hora. Intenta más tarde.'));
        }

        return response()->json(self::presentReport($analyst->handle($ws, $week, true), true), 201);
    }

    /** POST ai/reports/{report}/send — reenvía el correo de esa semana a los dueños. */
    public function send(string $report, SendWeeklyReport $send, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.manageWorkspace');
        $model = AiReport::findByPublicId($report) ?? abort(404);
        $ws = $this->current->get();
        $result = $send->handle($ws, CarbonImmutable::parse($model->period_start->toDateString(), $ws->timezone ?: 'UTC'), true);
        $audit->handle('ai_report.sent', $model, ['recipients' => count($result['recipients'])], $ws->id);

        return response()->json(['status' => $result['status'], 'recipients' => count($result['recipients'])]);
    }

    /** GET ai/ask — historial de la persona. */
    public function history(Request $request): JsonResponse
    {
        Gate::authorize('ws.useAi');
        /** @var User $user */
        $user = $request->user();
        $items = AskDataQuery::query()->where('user_id', $user->id)->orderByDesc('created_at')->limit(30)->get();

        return response()->json(['data' => $items->map(fn (AskDataQuery $q) => self::presentAsk($q))->values()]);
    }

    /** POST ai/ask {question} */
    public function ask(Request $request, AskYourData $ask): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $data = $request->validate(['question' => ['required', 'string', 'min:3', 'max:500']]);
        $ws = $this->current->get();
        /** @var User $user */
        $user = $request->user();
        if (! RateLimiter::attempt('ai-ask:'.$ws->id.':'.$user->id, 30, fn () => true, 3600)) {
            abort(429, __('Hiciste muchas preguntas en la última hora. Intenta más tarde.'));
        }

        return response()->json(self::presentAsk($ask->handle($ws, $user, (string) $data['question'])), 201);
    }

    /** GET ai/ask/{query}/run — las cifras al día de una consulta guardada, sin IA. */
    public function run(string $query, AskYourData $ask): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $model = AskDataQuery::findByPublicId($query) ?? abort(404);
        abort_if($model->query === null, 422, __('Esta pregunta no tiene una consulta para repetir.'));

        return response()->json(['id' => $model->publicId(), 'result' => $ask->rerun($this->current->get(), $model)]);
    }

    /** GET ai/saved — consultas fijadas en Inteligencia («Agregar al tablero»). */
    public function saved(): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');

        return response()->json(['data' => AskDataQuery::query()->where('saved_as', 'dashboard')->orderByDesc('updated_at')->limit(20)->get()
            ->map(fn (AskDataQuery $q) => self::presentAsk($q))->values()]);
    }

    /** POST ai/ask/{query}/save {as: segment|dashboard, name?} */
    public function save(Request $request, string $query, SegmentFromQuery $segments, SaveSegment $save, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $data = $request->validate(['as' => ['required', 'in:segment,dashboard'], 'name' => ['nullable', 'string', 'max:120']]);
        $model = AskDataQuery::findByPublicId($query) ?? abort(404);
        if ($data['as'] === 'segment') {
            Gate::authorize('ws.manageMarketing');
            $definition = $segments->definition($model->query);
            abort_if($definition === null, 422, __('Esta respuesta no se puede guardar como segmento.'));
            /** @var User $user */
            $user = $request->user();
            $name = trim((string) ($data['name'] ?? '')) !== '' ? (string) $data['name'] : mb_substr((string) $model->question, 0, 120);
            $segment = $save->handle(null, ['name' => $name, 'definition' => $definition], $user);
            $model->forceFill(['saved_as' => 'segment', 'saved_id' => $segment->id])->save();
            $audit->handle('ask_data.saved_segment', $model, ['segment' => $segment->publicId()], $this->current->id(), null, $user->id);

            return response()->json(self::presentAsk($model) + ['segment_id' => $segment->publicId()]);
        }
        $model->forceFill(['saved_as' => 'dashboard'])->save();

        return response()->json(self::presentAsk($model));
    }

    /** DELETE ai/ask/{query}/saved — quita una consulta fijada. */
    public function unsave(string $query): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $model = AskDataQuery::findByPublicId($query) ?? abort(404);
        if ($model->saved_as === 'dashboard') {
            $model->forceFill(['saved_as' => null])->save();
        }

        return response()->json(self::presentAsk($model));
    }

    /** GET contacts/{contact}/ai-summary */
    public function profileSummary(string $contact, ProfileSummary $summary): JsonResponse
    {
        $model = ContactAccess::find($contact);

        return response()->json($summary->forContact($this->current->get(), $model));
    }

    /** GET ai/review-summary?product_id=&site= */
    public function reviewSummary(Request $request, ReviewSummary $summary): JsonResponse
    {
        Gate::authorize('ws.viewDashboards');
        $data = $request->validate(['product_id' => ['required', 'string', 'max:64'], 'site' => ['nullable', 'string', 'max:40']]);
        $siteId = isset($data['site']) ? (Site::findByPublicId((string) $data['site']) ?? abort(404))->id : null;

        return response()->json($summary->forProduct($this->current->get(), (string) $data['product_id'], $siteId));
    }

    /** @return array<string, mixed> */
    private static function presentReport(AiReport $r, bool $full): array
    {
        return [
            'id' => $r->publicId(),
            'kind' => $r->kind,
            'period_start' => $r->period_start->toDateString(),
            'period_end' => $r->period_end->toDateString(),
            'source' => $r->source,
            'body' => $r->body_markdown,
            'actions' => $r->actions ?? [],
            'delivered_at' => $r->delivered_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
        ] + ($full ? ['facts' => $r->facts, 'validation' => $r->validation, 'model' => $r->model] : []);
    }

    /** @return array<string, mixed> */
    private static function presentAsk(AskDataQuery $q): array
    {
        return [
            'id' => $q->publicId(),
            'question' => $q->question,
            'answer' => $q->answer,
            'status' => $q->status,
            'query' => $q->query,
            'result' => $q->result_meta,
            'source' => $q->result_meta['source'] ?? (isset($q->validation['fallback_reason']) ? 'fallback' : 'ai'),
            'saved_as' => $q->saved_as,
            'created_at' => $q->created_at?->toIso8601String(),
        ];
    }
}
