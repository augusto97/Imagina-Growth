<?php

declare(strict_types=1);

namespace App\Domain\Intelligence\Ai\Copilot\Http;

use App\Domain\Intelligence\Ai\Contracts\AiGateway;
use App\Domain\Intelligence\Ai\Copilot\CampaignCopilot;
use App\Domain\Intelligence\Ai\Copilot\ContentWriter;
use App\Domain\Intelligence\Ai\Copilot\FlowCopilot;
use App\Domain\Intelligence\Ai\Copilot\Models\AiDraft;
use App\Domain\Intelligence\Ai\Copilot\Support\BrandTone;
use App\Domain\Intelligence\Ai\Copilot\Support\CopilotFailed;
use App\Domain\Intelligence\Ai\Exceptions\AiUnavailable;
use App\Domain\Templates\Support\BusinessProfile;
use App\Domain\Workspaces\Actions\RecordAuditLog;
use App\Domain\Workspaces\Models\User;
use App\Support\Tenancy\CurrentWorkspace;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Copiloto y redactor (M10, inteligencia.md §6.4–6.5). Proponer y redactar: useAi.
 * Aplicar, descartar o aprender el tono: además manageMarketing. Aplicar crea
 * borradores; nunca envía ni activa.
 */
final class CopilotController
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** GET copilot/status */
    public function status(AiGateway $ai, BrandTone $tone): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $ws = $this->current->get();

        return response()->json(['available' => $ai->available($ws->id), 'tone' => $tone->cached($ws)]);
    }

    /** POST copilot/campaigns {prompt} */
    public function campaign(Request $request, CampaignCopilot $copilot): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $prompt = $this->prompt($request);

        return $this->guard(fn () => response()->json(DraftPresenter::make($copilot->propose($this->current->get(), $this->userId($request), $prompt)), 201));
    }

    /** POST copilot/flows {prompt} */
    public function flow(Request $request, FlowCopilot $copilot): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $prompt = $this->prompt($request);

        return $this->guard(fn () => response()->json(DraftPresenter::make($copilot->propose($this->current->get(), $this->userId($request), $prompt)), 201));
    }

    /** GET copilot/drafts?kind= */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $kind = $request->query('kind');
        $drafts = AiDraft::query()->when(is_string($kind) && in_array($kind, ['campaign', 'flow', 'content'], true), fn ($q) => $q->where('kind', $kind))
            ->orderByDesc('created_at')->limit(50)->get();

        return response()->json(['data' => $drafts->map(fn (AiDraft $d) => DraftPresenter::make($d, false))->values()]);
    }

    /** GET copilot/drafts/{draft} */
    public function show(string $draft): JsonResponse
    {
        Gate::authorize('ws.useAi');

        return response()->json(DraftPresenter::make($this->find($draft)));
    }

    /** PATCH copilot/drafts/{draft}: cambios de la persona a una propuesta de campaña. */
    public function update(Request $request, string $draft, CampaignCopilot $copilot): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $model = $this->find($draft);
        if ($model->kind !== 'campaign' || $model->status !== AiDraft::DRAFT) {
            return response()->json(['message' => __('Solo se editan propuestas de campaña sin aplicar.')], 422);
        }
        $edits = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'holdout_pct' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'segment_id' => ['sometimes', 'string', 'max:40'],
            'schedule' => ['sometimes', 'array'],
            'schedule.date' => ['sometimes', 'date_format:Y-m-d'],
            'schedule.hour' => ['sometimes', 'integer', 'min:0', 'max:23'],
            'schedule.send_mode' => ['sometimes', Rule::in(['scheduled', 'contact_timezone', 'best_time'])],
            'coupon' => ['sometimes', 'array'],
            'coupon.enabled' => ['sometimes', 'boolean'],
            'coupon.kind' => ['sometimes', Rule::in(['percent', 'fixed_cart', 'free_shipping'])],
            'coupon.amount' => ['sometimes', 'numeric', 'min:0'],
            'coupon.expires_in_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
            'coupon.minimum_amount' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'channels' => ['sometimes', 'array', 'max:3'],
            'channels.*.subject' => ['sometimes', 'nullable', 'string', 'max:160'],
            'channels.*.preheader' => ['sometimes', 'nullable', 'string', 'max:160'],
            'channels.*.headline' => ['sometimes', 'nullable', 'string', 'max:160'],
            'channels.*.intro' => ['sometimes', 'nullable', 'string', 'max:600'],
            'channels.*.cta' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);

        return $this->guard(fn () => response()->json(DraftPresenter::make($copilot->edit($this->current->get(), $model, $edits))));
    }

    /** POST copilot/drafts/{draft}/apply: crea los borradores (campañas o flujo). */
    public function apply(Request $request, string $draft, CampaignCopilot $campaigns, FlowCopilot $flows, RecordAuditLog $audit): JsonResponse
    {
        Gate::authorize('ws.useAi');
        Gate::authorize('ws.manageMarketing');
        $model = $this->find($draft);
        /** @var User $user */
        $user = $request->user();

        return $this->guard(function () use ($model, $user, $campaigns, $flows, $audit) {
            $ws = $this->current->get();
            if ($model->kind === 'flow') {
                $flows->apply($ws, $model, $user);
            } elseif ($model->kind === 'campaign') {
                $campaigns->apply($ws, $model, $user);
            } else {
                return response()->json(['message' => __('Este contenido se usa desde el editor.')], 422);
            }
            $audit->handle('ai.draft_applied', null, ['draft' => $model->publicId(), 'kind' => $model->kind], $ws->id);

            return response()->json(DraftPresenter::make($model->refresh()));
        });
    }

    /** POST copilot/drafts/{draft}/discard */
    public function discard(string $draft): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $model = $this->find($draft);
        if ($model->status === AiDraft::DRAFT) {
            $model->forceFill(['status' => AiDraft::DISCARDED])->save();
        }

        return response()->json(DraftPresenter::make($model));
    }

    /** POST copilot/content/subjects {goal, offer?, audience?, count?} */
    public function subjects(Request $request, ContentWriter $writer): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $brief = $this->brief($request);
        $count = (int) ($request->validate(['count' => ['sometimes', 'integer', 'min:1', 'max:10']])['count'] ?? 5);

        return $this->guard(function () use ($writer, $request, $brief, $count) {
            $r = $writer->subjects($this->current->get(), $this->userId($request), $brief, $count);

            return response()->json(['id' => $r['draft']->publicId(), 'variants' => $r['variants']]);
        });
    }

    /** POST copilot/content/whatsapp {goal, offer?, audience?, category?} */
    public function whatsapp(Request $request, ContentWriter $writer): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $brief = $this->brief($request);
        $category = (string) ($request->validate(['category' => ['sometimes', Rule::in(['marketing', 'utility'])]])['category'] ?? 'marketing');

        return $this->guard(function () use ($writer, $request, $brief, $category) {
            $r = $writer->whatsapp($this->current->get(), $this->userId($request), $brief, $category);

            return response()->json(['id' => $r['draft']->publicId()] + array_diff_key($r, ['draft' => true]));
        });
    }

    /** POST copilot/content/landing {goal, offer?, audience?}: los textos de una página de captura. */
    public function landing(Request $request, ContentWriter $writer): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $ws = $this->current->get();
        $brief = $this->brief($request) + ['business' => (string) (BusinessProfile::for($ws)['name'] ?: $ws->name)];

        return $this->guard(function () use ($writer, $request, $brief, $ws) {
            $r = $writer->landing($ws, $this->userId($request), $brief);

            return response()->json(['id' => $r['draft']->publicId(), 'texts' => $r['texts']]);
        });
    }

    /** POST copilot/content/variants {text, kind?, count?} */
    public function variants(Request $request, ContentWriter $writer): JsonResponse
    {
        Gate::authorize('ws.useAi');
        $data = $request->validate([
            'text' => ['required', 'string', 'min:2', 'max:1024'],
            'kind' => ['sometimes', Rule::in(['subject', 'body', 'whatsapp'])],
            'count' => ['sometimes', 'integer', 'min:1', 'max:5'],
        ]);

        return $this->guard(function () use ($writer, $request, $data) {
            $r = $writer->variants($this->current->get(), $this->userId($request), (string) $data['text'], (string) ($data['kind'] ?? 'subject'), (int) ($data['count'] ?? 3));

            return response()->json(['id' => $r['draft']->publicId(), 'variants' => $r['variants']]);
        });
    }

    /** POST copilot/tone: aprende (o vuelve a aprender) el tono de marca. */
    public function learnTone(BrandTone $tone): JsonResponse
    {
        Gate::authorize('ws.useAi');
        Gate::authorize('ws.manageMarketing');

        return $this->guard(fn () => response()->json(['tone' => $tone->learn($this->current->get())]));
    }

    /** @param Closure(): JsonResponse $fn */
    private function guard(Closure $fn): JsonResponse
    {
        try {
            return $fn();
        } catch (AiUnavailable $e) {
            return response()->json(['message' => self::unavailableMessage($e), 'reason' => $e->reason], $e->reason === 'invalid' ? 422 : 503);
        } catch (CopilotFailed $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors], 422);
        }
    }

    public static function unavailableMessage(AiUnavailable $e): string
    {
        return match ($e->reason) {
            'disabled' => __('La IA no está configurada en este espacio de trabajo. Puedes crear la campaña o el flujo a mano.'),
            'budget' => __('Se alcanzó el tope de gasto en IA de este mes. Puedes seguir a mano.'),
            'refusal' => __('El modelo no quiso responder este pedido. Reformúlalo o hazlo a mano.'),
            'invalid' => $e->getMessage() !== 'invalid' ? $e->getMessage() : __('La respuesta de la IA no tenía el formato esperado. Intenta de nuevo.'),
            default => __('La IA no respondió. Intenta de nuevo en unos minutos o sigue a mano.'),
        };
    }

    private function prompt(Request $request): string
    {
        return trim((string) $request->validate(['prompt' => ['required', 'string', 'min:8', 'max:1000']])['prompt']);
    }

    /** @return array{goal: string, offer?: string|null, audience?: string|null} */
    private function brief(Request $request): array
    {
        /** @var array{goal: string, offer?: string|null, audience?: string|null} $data */
        $data = $request->validate([
            'goal' => ['required', 'string', 'min:3', 'max:500'],
            'offer' => ['sometimes', 'nullable', 'string', 'max:300'],
            'audience' => ['sometimes', 'nullable', 'string', 'max:300'],
        ]);

        return $data;
    }

    private function userId(Request $request): ?string
    {
        $user = $request->user();

        return $user instanceof User ? $user->id : null;
    }

    private function find(string $publicId): AiDraft
    {
        return AiDraft::findByPublicId($publicId) ?? abort(404);
    }
}
